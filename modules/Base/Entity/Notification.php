<?php

namespace OWA\Module\Base\Entity;

/**
 * One thing OWA has to tell its operators about.
 *
 * A notification is either GLOBAL or addressed to ONE user. A new OWA release
 * is the same fact for everybody who logs in; "your export finished" is not.
 * `user_id` carries that: empty means everyone. Whether a user has DISMISSED a
 * notification is a different question again, and lives in its own entity --
 * a global notification is dismissed by one user without vanishing for the
 * rest, which a flag on this row could not express.
 *
 * `source`, `source_key` and `user_id` together are the identity of a row: the
 * same underlying thing can legitimately be addressed to several users, so
 * dedupe asks "does THIS user already have this one?" rather than "does anyone".
 * The fetcher runs on a schedule and sees the same GitHub releases every time,
 * so it needs to ask "do I already have this one?" -- and it must ask about the
 * RELEASE, not about a row it has no id for. Anything that can produce
 * notifications later (an update becoming available, a queue backing up) gets a
 * source of its own without touching this table.
 */
class Notification extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('notification');

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty($id);

        // What produced this -- 'github_release' today. Indexed with
        // source_key because dedupe reads both and nothing reads either alone.
        $source = new \OWA\Module\Base\Classes\DbColumn( 'source', OWA_DTD_VARCHAR255 );
        $source->setIndex();
        $this->setProperty($source);

        /*
         * The producer's own id for the thing -- a GitHub release id. NOT the
         * title or the url: a release can be retitled and its url can move,
         * and either would make the same release arrive as a second
         * notification every time the job ran.
         */
        $source_key = new \OWA\Module\Base\Classes\DbColumn( 'source_key', OWA_DTD_VARCHAR255 );
        $source_key->setIndex();
        $this->setProperty($source_key);

        /*
         * Who this is for. EMPTY MEANS EVERYONE.
         *
         * Empty here is NULL in practice, not '': the entity layer drops
         * empty-string writes, so setting '' stores nothing and the column
         * keeps its default. Both spellings mean the same audience, and every
         * reader must treat them the same -- NotificationManager compares
         * `(string) ( $row['user_id'] ?? '' )` for exactly this reason. A
         * comparison written as `=== ''` against a raw row would decide a
         * global notification belongs to nobody.
         */
        $user_id = new \OWA\Module\Base\Classes\DbColumn( 'user_id', OWA_DTD_VARCHAR255 );
        $user_id->setIndex();
        $this->setProperty($user_id);

        /*
         * What KIND of thing this is -- 'release' today.
         *
         * Separate from `source` on purpose: source is WHERE it came from and
         * is dedupe machinery, type is what it MEANS and drives presentation.
         * Two sources can produce the same type, and one source could
         * eventually produce several, so a UI keyed on source would be reading
         * the wrong column.
         */
        $type = new \OWA\Module\Base\Classes\DbColumn( 'type', OWA_DTD_VARCHAR255 );
        $type->setIndex();
        $this->setProperty($type);

        $title = new \OWA\Module\Base\Classes\DbColumn( 'title', OWA_DTD_VARCHAR255 );
        $this->setProperty($title);

        // Release notes run long and carry newlines, so TEXT rather than a
        // VARCHAR that would silently truncate mid-sentence.
        $body = new \OWA\Module\Base\Classes\DbColumn( 'body', OWA_DTD_TEXT );
        $this->setProperty($body);

        /*
         * The short plain-text line the panel shows under the headline.
         *
         * Stored rather than derived on read: it is the same answer every time,
         * and computing it per request means stripping markdown from every
         * notification on every page load that opens the panel.
         *
         * The trade is that it is fixed at WRITE time -- changing
         * EXCERPT_WORDS does not rewrite the rows already stored. That is the
         * normal cost of keeping a derived value, and the alternative is
         * paying for the derivation forever.
         *
         * TEXT, not VARCHAR(255), even though the value is short by design.
         * How short is a TUNING decision -- EXCERPT_WORDS -- and this install
         * runs with an empty sql_mode, so an excerpt that outgrew a VARCHAR
         * would be truncated SILENTLY and mid-character rather than refused.
         * Making the column indifferent means the word count can be changed
         * later without anyone having to remember this column exists.
         */
        $excerpt = new \OWA\Module\Base\Classes\DbColumn( 'excerpt', OWA_DTD_TEXT );
        $this->setProperty($excerpt);

        $url = new \OWA\Module\Base\Classes\DbColumn( 'url', OWA_DTD_VARCHAR255 );
        $this->setProperty($url);

        /*
         * When the THING happened -- the release's published_at -- not when we
         * noticed it. An install that has been off for a month should show a
         * month-old release as a month old, and the list is ordered by this.
         */
        $published_at = new \OWA\Module\Base\Classes\DbColumn( 'published_at', OWA_DTD_INT );
        $published_at->setIndex();
        $this->setProperty($published_at);

        // When we noticed. Kept separate so "we were down for a month" is
        // answerable, and because ordering by it would jumble a first fetch.
        $created_at = new \OWA\Module\Base\Classes\DbColumn( 'created_at', OWA_DTD_INT );
        $this->setProperty($created_at);
    }
}
