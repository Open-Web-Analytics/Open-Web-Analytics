<?php
namespace OWA\Module\Base\Classes;

/**
 * Service User Class
 */


class ServiceUser extends \OWA\Core\Base {
    /**
     * @var \OWA\Module\Base\Entity\User
     */
    public $user;
    /** @var array<mixed> the role's capabilities */
    public $capabilities = [];
    /** @var mixed getPreferences()'s answer, false */
    public $preferences = [];
    /** @var bool */
    public $is_authenticated = false;
    /** @var array<\OWA\Module\Base\Entity\Site> site_id => the Profile */
    public $assignedSites = [];

    public function __construct() {
        // create empty user entity
        $this->user = \OWA\Core\CoreAPI::entityFactory('base.user');
        // set default role
        $this->setRole('everyone');
    }

    /**
     * Loads Current user based on user_id
     * This method should only used if the user is authenticated.
     *
     * @param string $user_id the user_id
     * @return void
     * @depricated
     */
    public function load( $user_id = '' ) {

        if (! $user_id ) {

                throw new \Exception('No valid userid given!');
        }
         
        // if there is a user_id load the user object and other properties.
        $this->user->load($user_id, 'user_id');
        $this->initInternalProperties();
    }

    /**
     * Loads the current user from an owa_user object
     * owa_auth uses this after the user is authenticated
     *
     * @param \OWA\Module\Base\Entity\User $user_obj
     * @return void
     */
    public function loadNewUserByObject($user_obj) {
        
        $this->user = $user_obj;
        $this->initInternalProperties();
    }

    /**
     * @return void
     */
    private function initInternalProperties() {
        $this->loadRelatedUserData();
        $this->loadAssignedSites();
    }

    /**
     * @return void
     */
    public function loadRelatedUserData() {
        $this->capabilities = $this->getCapabilities($this->user->get('role'));
        $this->preferences = $this->getPreferences($this->user->get('user_id'));

    }
    /**
     * gets allowed capabilities for the user role
     * @param mixed $role
     * @return mixed the role's entry in the capabilities setting; array() for a role it does not name
     */
    public function getCapabilities($role) {
        return \OWA\Core\CoreAPI::getCapabilities( $role );
    }

    /**
     * @param  mixed $user_id
     * @return false there are none
     */
    public function getPreferences($user_id) {
        return false;
    }

    /**
     * @return mixed
     */
    public function getRole() {
        return $this->user->get('role');
    }
    
    /**
     * Sets role and related capabilities
     *
     * @param    string $value the user's role
     * @return   void
     */
    public function setRole($value) {

        $this->user->set('role', $value);
        $this->capabilities = $this->getCapabilities($value);
    }

    /**
     * @param  string $name  a column of the user entity
     * @param  mixed  $value
     * @return void
     */
    public function setUserData($name, $value) {

        $this->user->set($name, $value);
    }

    /**
     * @param  string $name a column of the user entity
     * @return mixed
     */
    public function getUserData($name) {

        return $this->user->get($name);
    }

    /**
     * Checks if user has a partciular capability
     *
     * @param string     $cap
     * @param string|null $siteId    only needed if capability requires site access. you need to pass site_id (not id) field
     * @return boolean
     */
    public function isCapable($cap, $siteId = null) {
        \OWA\Core\CoreAPI::debug("Checking if user is capable of: ".$cap);

        // is this capability assigned to everyone?
        // is this the global admin user?
        // was no capability passed?
        // if so, the user can see and do everything
        if ( \OWA\Core\CoreAPI::isEveryoneCapable( $cap ) || $this->user->isAdmin() || empty($cap)) {
            \OWA\Core\CoreAPI::debug('No capability passed or user is an admin and capable of everything.');
            return true;
        }

        // is this user's role capable?
        if (!in_array($cap, $this->capabilities)) {
            \OWA\Core\CoreAPI::debug('capability does not exist for this role. user is not capable');
            return false;
        }

        // Does capability also require site access?
        if ( $this->isSiteAccessRequiredForCapability( $cap ) ) {
            \OWA\Core\CoreAPI::debug('Site access required for this capability.');
            if ( ! $this->isSiteAccessible( $siteId ) ) {
                \OWA\Core\CoreAPI::debug('Site is not accessible for this user.');
                return false;
            } else {
                \OWA\Core\CoreAPI::debug('Site is accessible for this user.');
            }
        }

        return true;
    }

    /**
     * Checks to see if the Capability requires
     * user to pass site access control check
     *
     * @param    string $capability the name of the capability (e.g. 'view_reports')
     * @return    boolean
     */
    public function isSiteAccessRequiredForCapability( $capability ) {

        $capabilitiesThatRequireSiteAccess = \OWA\Core\CoreAPI::getSetting('base', 'capabilitiesThatRequireSiteAccess');
        if (is_array($capabilitiesThatRequireSiteAccess) && in_array($capability, $capabilitiesThatRequireSiteAccess)) {
            return true;
        }

        return false;
    }

    /**
     * Checks to see if the a site is accessible to a user
     *
     * @param    string|null $siteId  the siteId of the site in question; null throws
     * @return    boolean
     */
    public function isSiteAccessible( $siteId ) {

        if ( is_null($siteId) ) {
            throw new \InvalidArgumentException('Cannot tell if site is accessible to user without a siteId (none given).');
        }

        if ( $this->user->isAdmin() ) {
            return true;
        }

        if ( isset( $this->assignedSites[ $siteId ] ) ) {
            \OWA\Core\CoreAPI::debug("Site ID: $siteId in accessible list for this user.");
            return true;
        }

        \OWA\Core\CoreAPI::debug("Site ID: $siteId is not in accessible list for this user.");

        return false;
    }

    /**
     * Mark the user as authenticated, or not.
     *
     * It used to ignore its argument and always set true, so a caller passing
     * false to withdraw authentication authenticated the user instead. Every
     * caller in OWA passes true.
     *
     * @param  bool $bool whether the user is authenticated
     * @return void
     */
    public function setAuthStatus($bool) {
        $this->is_authenticated = ( $bool === true );
    }

    /**
     * @return bool
     */
    public function isAuthenticated() {
        return $this->is_authenticated;
    }


    /**
     * Loads internal $this->assignedSites member
     *
     * @return void
     */
    private function loadAssignedSites() {
        \OWA\Core\CoreAPI::debug('loading assigned sites');
        
        try {
            
            if ( ! $this->user->get( 'id' ) ) {
                 throw new \Exception('no user object loaded!');
            }
            
        }
        
        catch( \Exception $e ) {
            
            \OWA\Core\CoreAPI::debug('Handled exception: '. $e->getMessage() );
            
        }

        $site_ids = [];
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site_user' )->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'user_id', $this->user->get('id') );
        $site_ids = $db->getAllRows();

        // filter array of site_ids.
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $site_ids = $dispatch->filter('allowed_sites_list', $site_ids);

        $this->setAllowedSitesList($site_ids);
    }

    /**
     * @param  mixed $site_ids the user's owa_site_user rows, as the allowed_sites_list filter left them
     * @return void
     */
    private function setAllowedSitesList($site_ids) {

        $list = [];

        if ( ! empty($site_ids) ) {
            foreach ($site_ids as $row) {
                $siteEntity = \OWA\Core\CoreAPI::entityFactory('base.site');
                $siteEntity->load($row['site_id']);

                /*
                 * Archived Profiles drop out here, which also settles
                 * AUTHORISATION and not just listing: isSiteAllowed() reads
                 * this map, so an archived Profile cannot be reported on by
                 * URL either. The grant row is deliberately left in place --
                 * archiving keeps what hangs off a Profile so a restore brings
                 * its access back with it.
                 */
                if ( $siteEntity->isArchived() ) {

                    continue;
                }

                $list[ $siteEntity->get('site_id') ] = $siteEntity;
            }
        }

        $this->assignedSites = self::withSiblingProfiles( $list );
    }

    /**
     * A grant on any Profile of a Property is a grant on the Property.
     *
     * Reports are of a Property, so a grant cannot stop at one of its Profiles:
     * the cube holds them all. Grants are still stored per Profile, and an
     * upgrade can leave the Profiles of one Property granted to different
     * users -- 1.x sites sharing a domain become Profiles of one Property -- so
     * the widening happens here, where access is read, rather than in the rows.
     *
     * Archived siblings stay out for the reason the loop above gives.
     *
     * @param  array<\OWA\Module\Base\Entity\Site> $list site_id => Site entity
     * @return array<\OWA\Module\Base\Entity\Site>       the same, plus every live sibling Profile
     */
    public static function withSiblingProfiles( array $list ) {

        $property_ids = [];

        foreach ( $list as $site ) {

            $property_id = (string) $site->get( 'property_id' );

            if ( ctype_digit( $property_id ) && $property_id !== '0' ) {

                $property_ids[ $property_id ] = true;
            }
        }

        if ( ! $property_ids ) {

            return $list;
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $rows = (array) $db->get_results( sprintf( 'SELECT * FROM %s WHERE property_id IN (%s)',
            $site->getTableName(), implode( ', ', array_keys( $property_ids ) ) ) );

        foreach ( $rows as $row ) {

            if ( isset( $list[ $row['site_id'] ] ) ) {

                continue;
            }

            $sibling = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $sibling->hydrate( (array) $row );

            if ( $sibling->isArchived() ) {

                continue;
            }

            $list[ $row['site_id'] ] = $sibling;
        }

        return $list;
    }

    /**
     * A Profile of the Property this user may see, or '' when there is none.
     *
     * Site access is checked per Profile, and any Profile of a Property grants
     * the Property (withSiblingProfiles()), so this is the site a Property's
     * capability checks are made against.
     *
     * @param  string $propertyId
     * @return string
     */
    public function siteIdForProperty( $propertyId ) {

        if ( (string) $propertyId === '' ) {

            return '';
        }

        $sites = $this->assignedSites;

        // An administrator is assigned nothing and may see every Profile.
        if ( $this->isAdmin() ) {

            $ids = \OWA\Module\Base\Classes\Cube\Cubes::siteIds( $propertyId );

            return $ids ? (string) $ids[0] : '';
        }

        foreach ( $sites as $site_id => $site ) {

            if ( (string) $site->get( 'property_id' ) === (string) $propertyId ) {

                return (string) $site_id;
            }
        }

        return '';
    }

    /**
     * @return array<\OWA\Module\Base\Entity\Site> site_id => the Profile
     */
    public function getAssignedSites() {

        return $this->assignedSites;
    }


    /**
     * @return bool
     */
    public function isOWAAdmin() {

        return $this->user->isOWAAdmin();
    }

    /**
     * @return bool
     */
    public function isAdmin() {

        return $this->user->isAdmin();
    }

    /**
     * @return true|void
     */
    public function isAnonymousUser() {

        if ( ! $this->user->get('user_id') || $this->getRole() === 'everyone') {
            
            return true;
            
        }
    }
}
