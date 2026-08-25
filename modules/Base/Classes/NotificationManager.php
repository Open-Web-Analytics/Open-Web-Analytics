<?php

namespace OWA\Module\Base\Classes;

/**
 * Notifications, and who has dismissed them.
 *
 * The GitHub release feed this replaces was fetched SYNCHRONOUSLY on every
 * dashboard render: an outbound request to api.github.com in the page's
 * critical path, uncached, never stored, and invisible the moment the network
 * was slow. Here the fetching is a scheduled job and the reading is a local
 * query.
 *
 * The transform is separated from the storage on purpose. `fromGithubReleases()`
 * is pure -- give it decoded JSON, get items -- so the parsing can be tested
 * against real API shapes with no database and no network, which is the half
 * most likely to be wrong.
 */
class NotificationManager {

    /** Everything this class writes is attributed to a source. */
    const SOURCE_GITHUB = 'github_release';

    /**
     * The ceiling on one read of the table.
     *
     * Not a page size -- the badge counts everything undismissed, so it has to
     * read everything. Far above any real count: a release a week for a decade
     * is about 500.
     */
    const MAX_ROWS = 1000;

    /**
     * How many a source may store on its FIRST fetch.
     *
     * The releases endpoint returns a page of five, and an install that has
     * never fetched would take all of them -- so a brand new install greets its
     * operator with a badge reading 5 and a panel of history they have already
     * lived through. Three is enough to show what the feature is without
     * turning first use into a chore.
     *
     * Only the first fetch. Once a source has anything stored, every genuinely
     * new item is stored: this caps the BACKFILL, not the feed.
     */
    const INITIAL_LIMIT = 3;

    /**
     * Decoded GitHub releases -> notification items.
     *
     * Pure: no database, no clock, no network.
     *
     * Releases that are drafts are skipped -- they are not announcements yet --
     * and so is anything without an id, because the id is what makes the fetch
     * idempotent and an item without one would arrive again on every run.
     *
     * @param mixed $decoded json_decode() output from the releases endpoint
     * @return array<int, array{source_key:string,title:string,body:string,url:string,published_at:int}>
     */
    public static function fromGithubReleases( $decoded ) {

        $items = array();

        foreach ( (array) $decoded as $release ) {

            $release = (array) $release;

            // The API returns an object with a `message` on error (rate limit,
            // 404). Iterating that yields strings, not releases.
            if ( ! isset( $release['id'] ) ) {

                continue;
            }

            if ( ! empty( $release['draft'] ) ) {

                continue;
            }

            $published = isset( $release['published_at'] ) ? strtotime( (string) $release['published_at'] ) : false;

            $items[] = array(
                'source_key'   => (string) $release['id'],
                // A release can be published without a name; the tag always
                // exists and is what people call it anyway.
                'title'        => (string) ( $release['name'] ?? $release['tag_name'] ?? 'Release' ),
                'body'         => (string) ( $release['body'] ?? '' ),
                'url'          => (string) ( $release['html_url'] ?? '' ),
                'published_at' => $published ?: 0,
            );
        }

        return $items;
    }

    /**
     * Write items that are not already stored.
     *
     * Idempotent on (source, source_key, user_id): the job sees the same
     * releases every run, so "have I got this one" is the whole design. Asked
     * per audience rather than globally, because the same underlying thing can
     * legitimately be addressed to several users.
     *
     * @param array $items from a from*() transform
     * @param string $source
     * @param string $userId '' for everyone
     * @return int how many were created
     */
    public static function record( array $items, $source, $userId = '' ) {

        $created = 0;

        /*
         * Sort newest first. The endpoint happens to return them that way, but
         * "happens to" is not something to decide what to keep on.
         */
        usort( $items, static function ( $a, $b ) {

            return ( (int) ( $b['published_at'] ?? 0 ) ) <=> ( (int) ( $a['published_at'] ?? 0 ) );
        } );

        $watermark = self::newestPublishedAt( $source );

        if ( $watermark === null ) {

            // First fetch: take the newest few and no history.
            $items = array_slice( $items, 0, self::INITIAL_LIMIT );

        } else {

            /*
             * Afterwards, never import anything OLDER than what we already
             * hold. Capping only the first fetch would merely postpone the
             * backfill: the second run finds rows present, the cap no longer
             * applies, and the history we deliberately skipped arrives anyway.
             * The watermark is what makes the decision stick.
             *
             * `>=` and not `>`: two releases can share a timestamp, and the
             * one already stored is refused by the dedupe below rather than by
             * this comparison.
             */
            $items = array_values( array_filter( $items, static function ( $item ) use ( $watermark ) {

                return ( (int) ( $item['published_at'] ?? 0 ) ) >= $watermark;
            } ) );
        }

        foreach ( $items as $item ) {

            if ( empty( $item['source_key'] ) ) {

                continue;
            }

            if ( self::exists( $source, $item['source_key'], $userId ) ) {

                continue;
            }

            $n = \OWA\Core\CoreAPI::entityFactory( 'base.notification' );

            $n->set( 'id', $n->generateId( $source . $item['source_key'] . $userId ) );
            $n->set( 'source', $source );
            $n->set( 'source_key', (string) $item['source_key'] );
            $n->set( 'user_id', (string) $userId );
            $n->set( 'title', (string) ( $item['title'] ?? '' ) );
            $n->set( 'body', (string) ( $item['body'] ?? '' ) );
            $n->set( 'url', (string) ( $item['url'] ?? '' ) );
            $n->set( 'published_at', (int) ( $item['published_at'] ?? 0 ) );
            $n->set( 'created_at', time() );

            if ( $n->create() ) {

                $created++;
            }
        }

        return $created;
    }

    /**
     * The newest published_at this source holds, or null if it holds nothing.
     *
     * Null and 0 are different answers here -- "never fetched" versus "holds
     * something with no timestamp" -- and they lead to different behaviour, so
     * this must not collapse them into a falsy value.
     */
    public static function newestPublishedAt( $source ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_notification' );
        $db->selectColumn( 'published_at' );
        $db->where( 'source', $source );
        $db->orderBy( 'published_at', 'DESC' );
        $db->limit( 1 );

        $row = $db->getOneRow();

        return $row ? (int) $row['published_at'] : null;
    }

    /**
     * Is this exact thing already stored for this audience?
     *
     * The audience is compared in PHP, NOT in the query. `Db::where()` drops
     * any condition whose value is empty -- so `where('user_id', '')`, which is
     * how a GLOBAL notification is addressed, filters on nothing at all and
     * would match a row belonging to some other user. The builder offers a
     * single space as an "intentionally empty" escape; relying on that here
     * would put the correctness of dedupe on a hack the next reader has to
     * know about.
     */
    public static function exists( $source, $sourceKey, $userId = '' ) {

        foreach ( self::rowsForSourceKey( $source, $sourceKey ) as $row ) {

            if ( (string) ( $row['user_id'] ?? '' ) === (string) $userId ) {

                return true;
            }
        }

        return false;
    }

    /** Every stored row for one source key, whoever it is addressed to. */
    private static function rowsForSourceKey( $source, $sourceKey ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_notification' );
        $db->selectColumn( '*' );
        $db->where( 'source', $source );
        $db->where( 'source_key', (string) $sourceKey );

        return (array) $db->getAllRows();
    }

    /**
     * The notifications this user can see and has not dismissed.
     *
     * "Can see" is global OR addressed to them. Newest first by when the THING
     * happened, so a first fetch of a year of releases reads as history rather
     * than as an arbitrary order.
     *
     * The audience and dismissal filters are applied in PHP over a bounded
     * read, not as a LEFT JOIN ... IS NULL. Two reasons, and neither is
     * squeamishness about SQL: the builder drops empty-valued conditions (see
     * exists()), which is exactly how a global notification is addressed; and
     * this table holds one row per release, so it is tens of rows, not the
     * millions the fact tables hold. A join written around that first problem
     * would be harder to read than the whole method.
     *
     * @param string $userId
     * @param int $limit
     * @return array<int, array>
     */
    public static function undismissedFor( $userId, $limit = 25 ) {

        /*
         * Read the dismissals FIRST. The builder is a singleton holding one
         * query's state, so running a second query part-way through building
         * this one clobbers it -- the outer select then executes with whatever
         * the inner one left behind. It fails loudly here, but the general
         * shape of that bug does not have to.
         */
        $dismissed = self::dismissedIdsFor( $userId );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_notification' );
        $db->selectColumn( '*' );
        $db->orderBy( 'published_at', 'DESC' );
        // Bounded so this cannot become an unbounded read years from now. Far
        // above any real count: a release every week for a decade is ~500.
        $db->limit( self::MAX_ROWS );

        $out = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $audience = (string) ( $row['user_id'] ?? '' );

            if ( $audience !== '' && $audience !== (string) $userId ) {

                continue;
            }

            if ( isset( $dismissed[ (string) ( $row['id'] ?? '' ) ] ) ) {

                continue;
            }

            $out[] = $row;

            if ( count( $out ) >= (int) $limit ) {

                break;
            }
        }

        return $out;
    }

    /** notification_id => true, for everything this user has dismissed. */
    private static function dismissedIdsFor( $userId ) {

        if ( (string) $userId === '' ) {

            // Nobody is not a user. Returning nothing dismissed here would show
            // a logged-out viewer every notification ever stored.
            return array();
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_notification_dismissal' );
        $db->selectColumn( 'notification_id' );
        $db->where( 'user_id', (string) $userId );

        $ids = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $ids[ (string) $row['notification_id'] ] = true;
        }

        return $ids;
    }

    /** How many the badge should show. */
    public static function unreadCountFor( $userId ) {

        return count( self::undismissedFor( $userId, self::MAX_ROWS ) );
    }

    /**
     * Record that one user is done with one notification.
     *
     * Idempotent: dismissing twice is the same as dismissing once, because the
     * id is derived from the pair. Two tabs, or a double click, must not create
     * two rows that then have to be reconciled.
     *
     * @return bool
     */
    public static function dismiss( $notificationId, $userId ) {

        if ( ! $notificationId || ! $userId ) {

            return false;
        }

        $d = \OWA\Core\CoreAPI::entityFactory( 'base.notification_dismissal' );

        $id = $d->generateId( $notificationId . $userId );

        $d->load( $id );

        if ( $d->get( 'id' ) ) {

            return true;
        }

        $d->set( 'id', $id );
        $d->set( 'notification_id', $notificationId );
        $d->set( 'user_id', (string) $userId );
        $d->set( 'dismissed_at', time() );

        return (bool) $d->create();
    }
}
