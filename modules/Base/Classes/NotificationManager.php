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
 *
 * An Item is what a from*() transform makes, and record() stores; type, when
 * present, overrides record()'s.
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-type Item array{source_key: string, title: string, body: string, url: string, published_at: int, type?: string}
 */
class NotificationManager {

    /** Everything this class writes is attributed to a source. */
    const SOURCE_GITHUB = 'github_release';

    /**
     * What a notification IS, as opposed to where it came from.
     *
     * Drives the icon the panel draws. Kept apart from `source` because source
     * is dedupe machinery: two sources can mean the same kind of thing, and one
     * source could eventually mean several, so presentation keyed on source
     * would be reading the wrong column.
     */
    const TYPE_RELEASE = 'release';

    /** Notifications with no type, or a type the panel does not know. */
    const TYPE_GENERAL = 'general';

    /**
     * How much a notice matters, lowest first. Drives the bell's icon; a
     * notice with none, or one not listed, is info.
     */
    const SEVERITIES = [ 'info', 'warning', 'security', 'critical' ];

    /**
     * What happened to a notice's admin e-mail (the email_status column).
     * notify() writes it once and never retries: a failed send stays in the
     * bell, and System Health shows the latest outcome.
     */
    const EMAIL_SENT          = 'sent';
    const EMAIL_FAILED        = 'failed';
    const EMAIL_NO_RECIPIENTS = 'no_recipients';

    /** Asked for from a page request, which never sends mail (notify()). */
    const EMAIL_NOT_FROM_WEB  = 'not_sent_from_web';

    /**
     * Makes the mailer notify() sends with. TESTS ONLY: null builds a
     * Classes\Mailer.
     *
     * @var (callable(): object)|null
     */
    public static $mailerFactory = null;

    /**
     * Words kept in the excerpt shown under a headline.
     *
     * The panel shows a headline and a hint of the body, the way any social
     * notification list does -- not the whole of a release note, which runs to
     * screenfuls of markdown.
     */
    const EXCERPT_WORDS = 20;

    /**
     * Backstop on an excerpt, in characters.
     *
     * Not a storage limit -- the column is TEXT and does not care. This is
     * here so a pathological body (one 40kB "word", a minified blob in a
     * release note) cannot become a 40kB notification row, and so the panel
     * always gets something that fits on two lines.
     *
     * Generous on purpose: EXCERPT_WORDS is the knob that decides length, and
     * this should not quietly become the real one when someone turns it up.
     */
    const EXCERPT_MAX_CHARS = 1000;

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

    /** title and url are VARCHAR(255): characters. */
    const MAX_TITLE_CHARS = 255;
    const MAX_URL_CHARS   = 255;

    /** body is TEXT: 65,535 bytes. */
    const MAX_BODY_BYTES = 65535;

    /**
     * An item's fields as they are stored: title cut to its column, body cut
     * to its column on a character boundary, and url kept only when it is an
     * https: URL that fits its column -- the panel makes it a link, and a cut
     * URL points somewhere else.
     *
     * Pure, so the limits are testable without a database.
     *
     * @param array<string, mixed> $item an Item from a from*() transform, or a notify() notice: title, body and url are read
     * @return array{title:string, body:string, url:string}
     */
    public static function storable( array $item ) {

        $url = trim( (string) ( $item['url'] ?? '' ) );

        return [
            'title' => mb_substr( (string) ( $item['title'] ?? '' ), 0, self::MAX_TITLE_CHARS ),
            // mb_strcut: at most this many bytes, never half a character.
            'body'  => mb_strcut( (string) ( $item['body'] ?? '' ), 0, self::MAX_BODY_BYTES ),
            'url'   => self::isLinkable( $url ) && mb_strlen( $url ) <= self::MAX_URL_CHARS ? $url : '',
        ];
    }

    /**
     * Whether a URL may be a notification's link: https: only.
     *
     * @param  scalar|null $url  as an item or a stored row holds it
     * @return bool
     */
    public static function isLinkable( $url ) {

        return is_string( $url ) && preg_match( '#^https://[^\s/?\#]+#i', $url ) === 1;
    }

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
     * @return list<Item>
     */
    public static function fromGithubReleases( $decoded ) {

        $items = [];

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

            $items[] = [
                'source_key'   => (string) $release['id'],
                // A release can be published without a name; the tag always
                // exists and is what people call it anyway.
                'title'        => (string) ( $release['name'] ?? $release['tag_name'] ?? \OWA\Core\CoreAPI::t( 'Release' ) ),
                'body'         => (string) ( $release['body'] ?? '' ),
                'url'          => (string) ( $release['html_url'] ?? '' ),
                'published_at' => $published ?: 0,
            ];
        }

        return $items;
    }

    /**
     * A short, plain-text hint of a body.
     *
     * Release notes are markdown with headings, links and list bullets, and
     * dropping raw markdown into a notification panel shows people `## Overview`
     * and `[text](url)`. This is not a markdown renderer -- it strips the few
     * things that actually appear and collapses the rest to a sentence.
     *
     * Pure, so the truncation is testable without a database.
     *
     * @param string $body
     * @param int $words
     * @return string
     */
    public static function excerpt( $body, $words = self::EXCERPT_WORDS ) {

        $text = (string) $body;

        // Fenced code and headings read as noise once the markup is gone.
        $text = preg_replace( '/```.*?```/s', ' ', $text );
        $text = preg_replace( '/^\s{0,3}#{1,6}\s*/m', ' ', $text );
        // Links: keep the text, drop the target.
        $text = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $text );
        // Bullets, emphasis and the stray backtick.
        $text = preg_replace( '/^\s{0,3}[-*+]\s+/m', ' ', $text );
        $text = str_replace( [ '**', '__', '`', '>' ], ' ', $text );

        $text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

        if ( $text === '' ) {

            return '';
        }

        $parts = explode( ' ', $text );

        $ellipsis = "\xE2\x80\xA6";

        $out = count( $parts ) <= $words
            ? $text
            // A single ellipsis character, not three dots: one glyph, and it
            // cannot be broken across a line.
            : implode( ' ', array_slice( $parts, 0, $words ) ) . $ellipsis;

        if ( mb_strlen( $out ) > self::EXCERPT_MAX_CHARS ) {

            // mb_substr, not substr: cutting a UTF-8 string by bytes can end
            // halfway through a character and produce something no longer
            // valid UTF-8.
            $out = rtrim( mb_substr( $out, 0, self::EXCERPT_MAX_CHARS - 1 ) ) . $ellipsis;
        }

        return $out;
    }

    /**
     * Write items that are not already stored.
     *
     * Idempotent on (source, source_key, user_id): the job sees the same
     * releases every run, so "have I got this one" is the whole design. Asked
     * per audience rather than globally, because the same underlying thing can
     * legitimately be addressed to several users.
     *
     * @param list<Item> $items from a from*() transform
     * @param string $source
     * @param string $userId '' for everyone
     * @param string $type what these ARE; drives the icon the panel draws
     * @return int how many were created
     */
    public static function record( array $items, $source, $userId = '', $type = self::TYPE_GENERAL ) {

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
             * hold, or the history the first fetch skipped would arrive on the
             * second run.
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

            $n      = \OWA\Core\CoreAPI::entityFactory( 'base.notification' );
            $stored = self::storable( $item );

            $n->set( 'id', $n->generateId( $source . $item['source_key'] . $userId ) );
            $n->set( 'source', $source );
            $n->set( 'source_key', (string) $item['source_key'] );
            $n->set( 'type', (string) ( $item['type'] ?? $type ) );
            $n->set( 'user_id', (string) $userId );
            $n->set( 'title', $stored['title'] );
            $n->set( 'body', $stored['body'] );
            // Derived once, here, rather than on every read.
            $n->set( 'excerpt', self::excerpt( $item['body'] ?? '' ) );
            $n->set( 'url', $stored['url'] );
            $n->set( 'published_at', (int) ( $item['published_at'] ?? 0 ) );
            $n->set( 'created_at', time() );

            if ( $n->create() ) {

                $created++;
            }
        }

        return $created;
    }

    /**
     * Raise one notice for every admin: store it, and e-mail it at once.
     *
     * For what OWA itself has to say (an update that installed or failed),
     * not a feed: none of record()'s first-fetch limit or watermark, which
     * would drop a notice older than the newest stored one. A notice already
     * stored under its source and source_key is not stored or e-mailed again,
     * so a caller may raise the same one on every run.
     *
     * THE MAIL GOES FROM THIS PROCESS, never a page request, and is never
     * retried. Notices are raised from CLI runs, and a scheduler job sending
     * them later would be stopped exactly when one matters: the scheduler
     * stops while the schema is behind (Controller::pendingUpdateStopsAction()).
     * The outcome is written on the row (email_status, emailed_at). The row's
     * id derives from source and source_key, so of two runs raising one
     * notice only the one whose insert succeeds sends it.
     *
     * Recipients are read at sending (adminRecipients()), not stored.
     *
     * @param array{source: string, source_key: string, title: string, body?: string, url?: string, severity?: string, email?: bool} $notice
     *        title and body already translated; body is plain text
     * @return bool whether it was stored, false when already stored or not written
     */
    public static function notify( array $notice ) {

        $source = (string) ( $notice['source'] ?? '' );
        $key    = (string) ( $notice['source_key'] ?? '' );

        if ( $source === '' || $key === '' || self::exists( $source, $key, '' ) ) {

            return false;
        }

        $severity = (string) ( $notice['severity'] ?? '' );
        $severity = in_array( $severity, self::SEVERITIES, true ) ? $severity : 'info';
        $stored   = self::storable( $notice );
        $now      = time();

        $n = \OWA\Core\CoreAPI::entityFactory( 'base.notification' );

        $n->set( 'id', $n->generateId( $source . $key ) );
        $n->set( 'source', $source );
        $n->set( 'source_key', $key );
        $n->set( 'type', self::TYPE_GENERAL );
        $n->set( 'user_id', '' );
        $n->set( 'title', $stored['title'] );
        $n->set( 'body', $stored['body'] );
        $n->set( 'excerpt', self::excerpt( $notice['body'] ?? '' ) );
        $n->set( 'url', $stored['url'] );
        $n->set( 'published_at', $now );
        $n->set( 'created_at', $now );
        $n->set( 'severity', $severity );
        $n->set( 'email_status', '' );
        $n->set( 'emailed_at', 0 );

        if ( ! $n->create() ) {

            return false;
        }

        if ( empty( $notice['email'] ) ) {

            return true;
        }

        $status = \OWA\Core\RequestType::is( \OWA\Core\RequestType::CLI )
            ? self::emailAdmins( $stored['title'], $stored['body'], $stored['url'] )
            : self::EMAIL_NOT_FROM_WEB;

        $n->set( 'email_status', $status );
        $n->set( 'emailed_at', $status === self::EMAIL_NOT_FROM_WEB ? 0 : time() );
        $n->update();

        return true;
    }

    /**
     * Where admin notices go: admin_notice_email, or every admin user's
     * address when it is empty. Read each time, so a changed address applies
     * to the next notice.
     *
     * @return list<string>
     */
    public static function adminRecipients() {

        $configured = trim( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'admin_notice_email' ) );

        if ( $configured !== '' ) {

            return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $configured ) ),
                static fn ( $address ) => $address !== '' ) ) );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName() );
        $db->selectColumn( 'email_address' );
        $db->where( 'role', \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE );

        $out = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $address = trim( (string) ( $row['email_address'] ?? '' ) );

            if ( $address !== '' && ! in_array( $address, $out, true ) ) {

                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * Send one notice to the admins, as plain text with an HTML copy.
     *
     * @param  string $title
     * @param  string $body plain text
     * @param  string $url  https only (storable()), or ''
     * @return string one of the EMAIL_* outcomes
     */
    private static function emailAdmins( $title, $body, $url ) {

        $recipients = self::adminRecipients();

        if ( ! $recipients ) {

            \OWA\Core\CoreAPI::notice( sprintf( "Admin notice '%s' not e-mailed: no admin has an e-mail address and admin_notice_email is empty.", $title ) );

            return self::EMAIL_NO_RECIPIENTS;
        }

        $mailer = self::$mailerFactory ? ( self::$mailerFactory )() : new Mailer();

        $added = 0;

        foreach ( $recipients as $address ) {

            if ( $mailer->addAddress( $address ) ) {

                $added++;
            }
        }

        if ( ! $added ) {

            return self::EMAIL_NO_RECIPIENTS;
        }

        $install = rtrim( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), '/' );

        $lines = [ $title, '', $body ];

        if ( $url !== '' ) {

            $lines[] = '';
            $lines[] = $url;
        }

        $lines[] = '';
        $lines[] = sprintf( \OWA\Core\CoreAPI::t( 'Sent by Open Web Analytics at %s.' ), $install );

        $text = implode( "\n", $lines );

        $mailer->setSubject( sprintf( \OWA\Core\CoreAPI::t( 'OWA: %s' ), $title ) );
        $mailer->setAltBody( $text );
        $mailer->setHtmlBody( nl2br( htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) ) );

        return $mailer->sendMail() ? self::EMAIL_SENT : self::EMAIL_FAILED;
    }

    /**
     * The latest notice that asked for an e-mail, or null: what System Health
     * reports of notice mail.
     *
     * @return Row|null
     */
    public static function lastEmailedNotice() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.notification' )->getTableName() );
        $db->selectColumn( '*' );
        // whereSql(): where() drops an empty value, so "not empty" cannot be said with it.
        $db->whereSql( 'email_asked', "email_status IS NOT NULL AND email_status <> ''" );
        $db->orderBy( 'created_at', 'DESC' );
        $db->limit( 1 );

        $row = $db->getOneRow();

        return $row ?: null;
    }

    /**
     * The newest published_at this source holds, or null if it holds nothing.
     *
     * Null and 0 are different answers here -- "never fetched" versus "holds
     * something with no timestamp" -- and they lead to different behaviour, so
     * this must not collapse them into a falsy value.
     *
     * @param  string $source
     * @return int|null
     */
    public static function newestPublishedAt( $source ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.notification' )->getTableName() );
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
     *
     * @param  string $source
     * @param  string $sourceKey
     * @param  string $userId  '' for everyone
     * @return bool
     */
    public static function exists( $source, $sourceKey, $userId = '' ) {

        foreach ( self::rowsForSourceKey( $source, $sourceKey ) as $row ) {

            if ( (string) ( $row['user_id'] ?? '' ) === (string) $userId ) {

                return true;
            }
        }

        return false;
    }

    /**
     * When the install-wide notice stored under a source key was raised, or
     * null when none is. A caller that warns about something lasting uses
     * its first notice as the clock: the release check e-mails about a
     * download that has failed for a week.
     *
     * @param  string $source
     * @param  string $sourceKey
     * @return int|null
     */
    public static function raisedAt( $source, $sourceKey ) {

        foreach ( self::rowsForSourceKey( $source, $sourceKey ) as $row ) {

            if ( (string) ( $row['user_id'] ?? '' ) === '' ) {

                return (int) ( $row['created_at'] ?? 0 );
            }
        }

        return null;
    }

    /**
     * Every stored row for one source key, whoever it is addressed to.
     *
     * @param  string $source
     * @param  string $sourceKey
     * @return list<Row>
     */
    private static function rowsForSourceKey( $source, $sourceKey ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.notification' )->getTableName() );
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
     * @return list<Row> each with read (bool) added
     */
    public static function undismissedFor( $userId, $limit = 25 ) {

        /*
         * Read the per-user state FIRST. The builder is a singleton holding one
         * query's state, so running a second query part-way through building
         * this one clobbers it -- the outer select then executes with whatever
         * the inner one left behind. It fails loudly here, but the general
         * shape of that bug does not have to.
         */
        $states = self::statesFor( $userId );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.notification' )->getTableName() );
        $db->selectColumn( '*' );
        $db->orderBy( 'published_at', 'DESC' );
        // Bounded so this cannot become an unbounded read years from now. Far
        // above any real count: a release every week for a decade is ~500.
        $db->limit( self::MAX_ROWS );

        $out = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $audience = (string) ( $row['user_id'] ?? '' );

            if ( $audience !== '' && $audience !== (string) $userId ) {

                continue;
            }

            $state = $states[ (string) ( $row['id'] ?? '' ) ] ?? null;

            if ( $state && $state['dismissed'] ) {

                continue;
            }

            // Carried on the row: a READ notification stays in the list, it
            // just stops being bold and stops counting.
            $row['read'] = (bool) ( $state && $state['read'] );

            // The panel makes it a link: a row stored before record() checked is checked here.
            if ( ! self::isLinkable( $row['url'] ?? '' ) ) {

                $row['url'] = '';
            }

            $out[] = $row;

            if ( count( $out ) >= (int) $limit ) {

                break;
            }
        }

        return $out;
    }

    /**
     * notification_id => state row, for everything this user has acted on.
     *
     * One read for both facts. Asking separately would mean two queries to
     * answer one question, and two chances for them to disagree about a row
     * written between them.
     *
     * @param  string $userId
     * @return array<string, array{read:bool, dismissed:bool}>
     */
    private static function statesFor( $userId ) {

        if ( (string) $userId === '' ) {

            // Nobody is not a user. Returning nothing here would show a
            // logged-out viewer every notification ever stored, all unread.
            return [];
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.notification_state' )->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'user_id', (string) $userId );

        $states = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $states[ (string) $row['notification_id'] ] = [
                'read'      => ( (int) ( $row['read_at'] ?? 0 ) ) > 0,
                'dismissed' => ( (int) ( $row['dismissed_at'] ?? 0 ) ) > 0,
            ];
        }

        return $states;
    }

    /**
     * How many the badge shows: undismissed AND unread.
     *
     * Not the length of the list. Reading something clears it from the count
     * while leaving it on screen, which is the whole point of separating the
     * two states -- so the badge and the list deliberately disagree, and the
     * client must not compute one from the other.
     *
     * @param  string $userId
     * @return int
     */
    public static function unreadCountFor( $userId ) {

        $unread = 0;

        foreach ( self::undismissedFor( $userId, self::MAX_ROWS ) as $row ) {

            if ( empty( $row['read'] ) ) {

                $unread++;
            }
        }

        return $unread;
    }

    /**
     * Record that one user has READ one notification.
     *
     * It stays in their list. The headline stops being bold and it stops
     * counting towards the badge, and that is all.
     *
     * @param  string $notificationId
     * @param  string $userId
     * @return bool false when either is empty, or the row was not written
     */
    public static function markRead( $notificationId, $userId ) {

        return self::stamp( $notificationId, $userId, 'read_at' );
    }

    /**
     * Record that one user is DONE with one notification.
     *
     * Dismissing also marks it read. Something you have finished with cannot
     * still be waiting to be looked at, and leaving read_at unset would let a
     * dismissed notification keep inflating the badge if it ever came back
     * into the list.
     *
     * @param  string $notificationId
     * @param  string $userId
     * @return bool
     */
    public static function dismiss( $notificationId, $userId ) {

        if ( ! self::stamp( $notificationId, $userId, 'read_at' ) ) {

            return false;
        }

        return self::stamp( $notificationId, $userId, 'dismissed_at' );
    }

    /**
     * Set one timestamp on one user's state row, creating the row if needed.
     *
     * Both facts go through here so they cannot drift into different ideas of
     * what a state row is. Idempotent: the id is derived from the pair, and an
     * already-set timestamp is left alone rather than moved -- "when did I
     * first read this" should not change because a second tab loaded the
     * panel.
     *
     * @param string $notificationId
     * @param string $userId
     * @param string $column read_at | dismissed_at
     * @return bool
     */
    private static function stamp( $notificationId, $userId, $column ) {

        if ( ! $notificationId || ! $userId ) {

            return false;
        }

        $state = \OWA\Core\CoreAPI::entityFactory( 'base.notification_state' );

        $id = $state->generateId( $notificationId . $userId );

        $state->load( $id );

        if ( $state->get( 'id' ) ) {

            if ( ( (int) $state->get( $column ) ) > 0 ) {

                return true;
            }

            $state->set( $column, time() );

            return (bool) $state->update();
        }

        $state->set( 'id', $id );
        $state->set( 'notification_id', $notificationId );
        $state->set( 'user_id', (string) $userId );
        $state->set( $column, time() );

        return (bool) $state->create();
    }
}
