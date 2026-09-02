<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//




namespace OWA\Module\Base\Entity;

/**
 * Site Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Site extends \OWA\Core\Entity {

    /*
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
     */
    protected $private_properties = [ 'archived_date' ];

    private static $cachedAssignedUsers = array();

    /**
     * Forget which users are assigned to which sites.
     *
     * The cache is keyed by site, and the two places that maintain it evict a
     * single site because they change a single site. Deleting a USER revokes
     * their access across every site at once, which no per-site eviction can
     * express -- so it clears the lot.
     */
    public static function forgetAssignedUsers() {

        self::$cachedAssignedUsers = array();
    }

    function __construct() {

        $this->setTableName('site');
        $this->setCachable();
        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['site_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
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
    }

    /**
     * Has this Profile been archived?
     *
     * Archiving is how a Profile is removed: the row and everything hanging off
     * it -- grants, scoped settings, collected data -- are kept, so a restore is
     * possible. Every listing and the tracking gate ask this.
     */
    public function isArchived() {

        return (bool) $this->get('archived_date');
    }

    /**
     * The identifier an existing site was given, derived from its domain.
     *
     * LEGACY. Every site_id issued before identity was decoupled from the
     * domain has this shape, and they are never reissued -- every fact row
     * references one. Kept so a legacy identifier can still be reconstructed
     * from a domain where something needs to; new sites get mintSiteId().
     *
     * Deriving identity from the domain is what made two sites for one domain
     * impossible, which is why new sites no longer use this.
     */
    function generateSiteId($domain) {

        return md5($domain);
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
     */
    function mintSiteId() {

        return 'OWA-' . bin2hex( random_bytes( 8 ) );
    }

    function settingsGetFilter($value) {
        if ($value) {
            // Site settings are an array of scalars -- settingsSetFilter()
            // writes serialize($value) for exactly that. Refusing objects means
            // a tampered-with column cannot instantiate a class on read.
            return unserialize($value, ['allowed_classes' => false]);
        }
    }

    function settingsSetFilter($value) {
        \OWA\Core\CoreAPI::debug('hello rom setFilter');
        $value = serialize($value);
        \OWA\Core\CoreAPI::debug($value);
        return $value;
    }

    /**
     * Retrieves a specific setting from the settings
     * property for this site
     *
     * @param string $name the name of the setting
     * @return mixed
     */
    /**
     * This Profile's effective value for one setting.
     *
     * Reads the scoped store rather than this row's own settings blob. The
     * blob is legacy: Update022 copied it into scoped rows and the screens
     * write there, so reading it here would answer with a stale copy and
     * could never inherit from the Property or Organization.
     */
    public function getSiteSetting($name) {

        return \OWA\Core\CoreAPI::getSetting(
            'base', $name, 'profile', $this->get('site_id') );
    }

    /**
     * The bare host, whether or not one was stored with a scheme.
     *
     * Returned unconditionally. It used to be guarded by
     * `strpos( $domain, '://' )` and fall off the end when there was no scheme
     * -- returning NULL for a domain stored bare, which the table already
     * contains. The one caller uses the result to build `'://' . $domain` when
     * checking domain aliases, so a null turned that test into a search for
     * '://' alone, which matches any absolute URL and skipped alias resolution
     * entirely.
     *
     * Schemes are on their way out of this column: a domain is not a URL, and
     * storing one was only load-bearing while a site's identity was
     * md5( domain ), where http:// and https:// produced two unrelated sites for
     * one website. This method therefore has to cope with both shapes for as
     * long as both exist.
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
     * @param    array    $current    user ids holding a grant now
     * @param    array    $rendered    user ids the form offered
     * @param    array    $checked    user ids the operator ticked
     * @return    array    [ 'grant' => int[], 'revoke' => int[] ]
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

        return array(
            'grant'  => array_values( array_diff( $checked, $current ) ),
            'revoke' => array_values( array_intersect( array_diff( $rendered, $checked ), $current ) ),
        );
    }

    /**
     * Applies a form submission to this site's grants as a delta.
     *
     * Only users the form rendered can be affected, and only rows that
     * actually change are written -- unlike updateAssignedUserIds(), which
     * deletes every grant for the site before reinserting the survivors, so
     * the common edit passes through a state where nobody has access at all.
     *
     * @param    array    $rendered    user ids the form offered
     * @param    array    $checked    user ids the operator ticked
     * @return    array    the changes applied
     */
    public function applyAssignedUserChanges( array $rendered, array $checked ) {

        if ( ! $this->get('id') ) {

            throw new \Exception('no site data loaded!');
        }

        $current = array();

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
            $db->deleteFrom('owa_site_user');
            $db->where( 'site_id', $this->get('id') );
            $db->where( 'user_id', $id );
            $db->executeQuery();
        }

        unset ( self::$cachedAssignedUsers[$this->get('id')] );

        return $changes;
    }

    /**
     * Replaces the whole grant set for this site.
     *
     * Prefer applyAssignedUserChanges(): this deletes every grant before
     * reinserting, so a caller that computes the set wrongly -- or a form that
     * submits nothing -- removes everyone.
     *
     * @param array $siteIds
     */
    public function updateAssignedUserIds(array $userIds) {
         if (!$this->get('id')) {
             throw new \Exception('no site data loaded!');
         }
         $db = \OWA\Core\CoreAPI::dbSingleton();
         $db->deleteFrom('owa_site_user');
         $db->where( 'site_id', $this->get('id') );
         $ret = $db->executeQuery();

         foreach ($userIds as $id) {
             $relation = \OWA\Core\CoreAPI::entityFactory('base.site_user');
            $relation->set( 'user_id', intval ($id ) );
            $relation->set( 'site_id', $this->get('id') );
            $relation->save();
         }

         unset ( self::$cachedAssignedUsers[$this->get('id')] );

    }


    /**
     * Checks if user is allowed to access the site.
     * @param integer $userId
     * @return boolean
     */
    public function isUserAssigned($userId) {
        $users = $this->getAssignedUsers();
        foreach ($users as $user) {
            if ($userId == $user->get('id')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns collection of owa_user entities that are allowed for current user
     * @return owa_user[]
     */
    public function getAssignedUsers() {
        if (!$this->get('id')) {
             throw new \Exception('no site data loaded!');
        }
        if (!isset(self::$cachedAssignedUsers[$this->get('id')])) {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->selectFrom( 'owa_site_user' );
            $db->selectColumn( '*' );
            $db->where( 'site_id', $this->get('id') );
            $relations = $db->getAllRows();
            $result = array();
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

?>