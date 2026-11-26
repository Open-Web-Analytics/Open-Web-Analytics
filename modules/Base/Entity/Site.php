<?php

namespace OWA\Module\Base\Entity;

/**
 * Site Entity
 */

class Site extends \OWA\Core\Entity {

    /**
     * Kept out of GET /v1/sites.
     *
     * The payload is a published contract the WordPress plugin reads, and
     * whether a Profile has been archived is an internal fact about removal --
     * an archived Profile is filtered out of that listing entirely, so the
     * field would be present, always falsy, and meaningless to a client.
     *
     * Declared rather than left to chance: getPublicProperties() emits every
     * column by default, so any column added to this entity joins a public API
     * unless someone says otherwise.
     *
     * @var list<string>
     */
    protected $private_properties = [ 'archived_date', 'stream_type', 'app_id' ];

    /** @var array<int|string, list<User>> site id => its users */
    private static $cachedAssignedUsers = [];

    /**
     * Forget which users are assigned to which sites.
     *
     * The cache is keyed by site, and the two places that maintain it evict a
     * single site because they change a single site. Deleting a USER revokes
     * their access across every site at once, which no per-site eviction can
     * express -- so it clears the lot.
     *
     * @return void
     */
    public static function forgetAssignedUsers() {

        self::$cachedAssignedUsers = [];
    }

    public function __construct() {

        $this->setTableName('site');
        $this->setCachable();
        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['site_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);

        /*
         * Every beacon finds its Profile by site_id, which id is derived from
         * (generateId()), so one site_id names one row. Update093 adds this
         * to an existing table, as a plain index where the rows break that.
         */
        $this->addUniqueIndex( self::SITE_ID_INDEX, [ 'site_id' ] );
        $this->properties['domain'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['domain']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['name'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['name']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['description'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['description']->setDataType(OWA_DTD_TEXT);
        /*
         * The property this profile observes.
         *
         * Nullable until the migration backfills it, and nullable afterwards
         * for a profile created before its property exists -- the resolution
         * treats an unparented profile as belonging to no property rather than
         * guessing one.
         */
        $this->properties['property_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['property_id']->setDataType(OWA_DTD_BIGINT);

        /*
         * SUPERSEDED by property_id, which does this job properly, but kept:
         * it is emitted by GET /v1/sites, and that payload is a contract the
         * WordPress plugin reads. Removing the column would remove a field from
         * a public API to save a column nothing reads.
         */
        $this->properties['site_family'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site_family']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['settings'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['settings']->setDataType(OWA_DTD_BLOB);

        /*
         * When this was archived. FALSY means live.
         *
         * A timestamp rather than a boolean flag, because a restore wants to
         * know when -- and because a tinyint in this schema holds 1, 0 and
         * NULL, which group as three distinct things.
         *
         * Read it as FALSY, never as `IS NULL`. The column genuinely holds
         * three values: NULL for a row that has never been archived, a stamp
         * for one that is, and 0 for one that was restored. Restoring cannot
         * write NULL back through the entity layer -- setting '' on a numeric
         * column is treated as "no value given" and skipped, so 0 is what
         * lands. All three are answered correctly by empty()/(bool); an
         * `IS NULL` test would quietly classify every restored row as archived.
         */
        $this->properties['archived_date'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['archived_date']->setDataType(OWA_DTD_BIGINT);

        /*
         * What KIND of thing this Profile observes: 'web' or 'app'.
         *
         * The identifier a Profile needs depends on this, which is why the type
         * lives here and not on the Property: a Property carries no URL, because
         * a Property holding a site and its apps has no single domain to put
         * there.
         *
         * Two values, not four. OWA has no app SDK -- log.php will take events
         * from anything that can make an HTTP request, but there is no client
         * library and no documented app ingestion, so splitting 'app' into ios
         * and android would be schema for something with no way to send. It can
         * split later; nothing here assumes there are only two.
         */
        $this->properties['stream_type'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['stream_type']->setDataType(OWA_DTD_VARCHAR255);

        /*
         * The identifier an APP Profile is known by -- a bundle id or package
         * name. Empty for a web Profile, which is identified by its domain.
         */
        $this->properties['app_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['app_id']->setDataType(OWA_DTD_VARCHAR255);
    }

    /** The index on site_id. */
    const SITE_ID_INDEX = 'site_id_lookup';

    /** Web is the default, and what every Profile migrated from 1.x is. */
    const STREAM_WEB = 'web';
    const STREAM_APP = 'app';

    /**
     * What kind of thing this Profile observes (streamTypeOf()).
     *
     * @return string
     */
    public function getStreamType() {

        return self::streamTypeOf( [ 'stream_type' => $this->get( 'stream_type' ) ] );
    }

    /**
     * What kind of thing a Profile's row observes.
     *
     * Empty means web: a NULL is a row from 1.x, and every one of those is a
     * website. So is no row at all, a Profile not yet made.
     *
     * @param  array<string, mixed>|null $row
     * @return string a STREAM_* value
     */
    public static function streamTypeOf( $row ) {

        $type = is_array( $row ) ? (string) ( $row['stream_type'] ?? '' ) : '';

        return $type !== '' ? $type : self::STREAM_WEB;
    }

    /**
     * Has this Profile been archived?
     *
     * Archiving is how a Profile is removed: the row and everything hanging off
     * it -- grants, scoped settings, collected data -- are kept, so a restore is
     * possible. Every listing and the tracking gate ask this.
     *
     * @return bool
     */
    public function isArchived() {

        return (bool) $this->get('archived_date');
    }


    /**
     * The Profile a site_id names. Not persisted when there is none, or no
     * site_id.
     *
     * @param string $site_id
     * @return Site
     */
    public static function forSiteId( $site_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        // getByColumn() refuses a falsy value, '0' among them.
        if ( $site_id ) {

            $site->getByColumn( 'site_id', (string) $site_id );
        }

        return $site;
    }

    /**
     * A fresh identifier for a new site, owing nothing to its domain.
     *
     * Prefixed so a minted identifier is recognisable on sight and can be told
     * from a legacy md5 without consulting the database. The prefix is cosmetic
     * only: generateId() lowercases before hashing and MySQL's collation
     * compares case-insensitively, so OWA- and owa- are the same identifier.
     *
     * Sixteen hex characters of randomness. The collision that actually matters
     * is not on this string but on the numeric key derived from it, so callers
     * mint against a uniqueness check rather than trusting the width alone.
     *
     * @return string
     */
    public function mintSiteId() {

        return 'OWA-' . bin2hex( random_bytes( 8 ) );
    }

    /**
     * @param  string|null $value the stored column
     * @return mixed unserialize()'s result, unchecked; null when nothing is stored
     */
    public function settingsGetFilter($value) {
        if ($value) {
            // Site settings are an array of scalars -- settingsSetFilter()
            // writes serialize($value) for exactly that. Refusing objects means
            // a tampered-with column cannot instantiate a class on read.
            return unserialize($value, ['allowed_classes' => false]);
        }
    }

    /**
     * @param  array<string, scalar> $value
     * @return string
     */
    public function settingsSetFilter($value) {

        return serialize($value);
    }

    /**
     * This Profile's effective value for one setting.
     *
     * Reads the scoped store rather than this row's own settings blob: the
     * screens write there, and it inherits from the Property and Organization.
     *
     * @param  string $name
     * @return mixed a setting's value, any type
     */
    public function getSiteSetting($name) {

        return \OWA\Core\CoreAPI::getSetting(
            'base', $name, 'profile', $this->get('site_id') );
    }

    /**
     * The bare host, whether or not one was stored with a scheme.
     *
     * A domain is stored bare (SiteManager::domainOnly()), but rows from 1.x
     * may carry a scheme, so this copes with both shapes.
     *
     * @return string
     */
    public function getDomainName() {

        $domain = trim( (string) $this->get('domain') );

        if ( $domain === '' ) {

            return '';
        }

        $separator = strpos( $domain, '://' );

        if ( $separator !== false ) {

            $domain = substr( $domain, $separator + 3 );
        }

        return rtrim( trim( $domain ), '/' );
    }

    /**
     * Works out which grants a form submission actually changes.
     *
     * The caller passes the ids the form *rendered* alongside the ids the
     * operator *checked*. Anything outside the rendered set is invisible to
     * this submission and is left exactly as it was -- which is what stops an
     * empty or truncated POST from revoking every user of a site, and what
     * makes it safe to render the form filtered or partially.
     *
     * Pure set arithmetic: no database, no entity state.
     *
     * @param    array<int|string>    $current    user ids holding a grant now
     * @param    array<int|string>    $rendered    user ids the form offered
     * @param    array<int|string>    $checked    user ids the operator ticked
     * @return    array{grant: list<int>, revoke: list<int>}
     */
    public static function computeGrantChanges( array $current, array $rendered, array $checked ) {

        $toInt = function( $ids ) {

            return array_values( array_unique( array_map( 'intval', $ids ) ) );
        };

        $current  = $toInt( $current );
        $rendered = $toInt( $rendered );

        // A checked id the form never rendered did not come from this page,
        // so it is either stale or forged. Ignore it.
        $checked = array_intersect( $toInt( $checked ), $rendered );

        return [
            'grant'  => array_values( array_diff( $checked, $current ) ),
            'revoke' => array_values( array_intersect( array_diff( $rendered, $checked ), $current ) ),
        ];
    }

    /**
     * Applies a form submission to this site's grants as a delta.
     *
     * Only users the form rendered can be affected, and only rows that
     * actually change are written, so an edit never passes through a state
     * where nobody has access.
     *
     * @param    array<int|string>    $rendered    user ids the form offered
     * @param    array<int|string>    $checked    user ids the operator ticked
     * @return    array{grant: list<int>, revoke: list<int>}    the changes applied
     */
    public function applyAssignedUserChanges( array $rendered, array $checked ) {

        if ( ! $this->get('id') ) {

            throw new \Exception('no site data loaded!');
        }

        $current = [];

        foreach ( $this->getAssignedUsers() as $user ) {

            $current[] = $user->get('id');
        }

        $changes = self::computeGrantChanges( $current, $rendered, $checked );

        foreach ( $changes['grant'] as $id ) {

            $relation = \OWA\Core\CoreAPI::entityFactory('base.site_user');
            $relation->set( 'user_id', $id );
            $relation->set( 'site_id', $this->get('id') );
            $relation->save();
        }

        foreach ( $changes['revoke'] as $id ) {

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->deleteFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site_user' )->getTableName() );
            $db->where( 'site_id', $this->get('id') );
            $db->where( 'user_id', $id );
            $db->executeQuery();
        }

        unset ( self::$cachedAssignedUsers[$this->get('id')] );

        return $changes;
    }

    /**
     * Returns collection of owa_user entities that are allowed for current user
     * @return \OWA\Module\Base\Entity\User[]
     */
    public function getAssignedUsers() {
        if (!$this->get('id')) {
             throw new \Exception('no site data loaded!');
        }
        if (!isset(self::$cachedAssignedUsers[$this->get('id')])) {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site_user' )->getTableName() );
            $db->selectColumn( '*' );
            $db->where( 'site_id', $this->get('id') );
            $relations = $db->getAllRows();
            $result = [];
            if (is_array($relations)) {
                foreach ($relations as $row) {
                    $userEntity = \OWA\Core\CoreAPI::entityFactory('base.user');
                    $userEntity->load($row['user_id']);
                    $result[] = $userEntity;
                }
            }
            self::$cachedAssignedUsers[$this->get('id')] = $result;
        }

        return self::$cachedAssignedUsers[$this->get('id')];
    }

}
