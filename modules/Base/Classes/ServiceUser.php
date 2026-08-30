<?php
namespace OWA\Module\Base\Classes;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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

/**
 * Service User Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class ServiceUser extends \OWA\Core\Base {
    /**
     * @var \owa_user
     */
    public $user;
    var $capabilities = array();
    var $preferences = array();
    var $is_authenticated = false;
    public $assignedSites = array();
    private $isInitialized = false;
    private $isAssignedSitesListLoaded = false;

    function __construct() {
        //parent::__construct();
        // create empty user entity
        $this->user = \OWA\Core\CoreAPI::entityFactory('base.user');
        // set default role
        $this->setRole('everyone');
    }

    /**
     * Loads Current user based on user_id
     * This method should only used if the user is authenticated.
     *
     * @param $user_id    string    the user_id
     * @depricated
     */
    function load( $user_id = '' ) {

        if (! $user_id ) {

            // if there is no user_id and role is everyone
            // procead with loading sites and
            //if ( $this->isAnonymousUser() ) {
            //    return $this->initInternalProperties();
            //} else {
                throw new \Exception('No valid userid given!');
            //}
        }
		 
        // if there is a user_id load the user object and other properties.
        $this->user->load($user_id, 'user_id');
        $this->initInternalProperties();
    }

    /**
     * Loads the current user from an owa_user object
     * owa_auth uses this after the user is authenticated
     *
     * @param $user_obj    object    \owa_user object
     */
    function loadNewUserByObject($user_obj) {
	    
        $this->user = $user_obj;
        $this->initInternalProperties();
    }

    private function initInternalProperties() {
        $this->loadRelatedUserData();
        $this->loadAssignedSites();
        $this->setInitialized();
    }

    function loadRelatedUserData() {
        $this->capabilities = $this->getCapabilities($this->user->get('role'));
        $this->preferences = $this->getPreferences($this->user->get('user_id'));

    }
    /**
     * gets allowed capabilities for the user role
     * @param mixed $role
     */
    function getCapabilities($role) {
        return \OWA\Core\CoreAPI::getCapabilities( $role );
    }

    function getPreferences($user_id) {
        return false;
    }

    function getRole() {
        return $this->user->get('role');
    }
    
    function getApiKey() {
	    
	    return $this->user->get('api_key');
    }

    /**
     * Sets role and related capabilities
     *
     * @param    $value    string    the user's role
     */
    function setRole($value) {

        $this->user->set('role', $value);
        $this->capabilities = $this->getCapabilities($value);
    }

    function setUserData($name, $value) {

        $this->user->set($name, $value);
    }

    function getUserData($name) {

        return $this->user->get($name);
    }

    /**
     * Checks if user has a partciular capability
     *
     * @param string     $cap
     * @param integer     $siteId    only needed if capability requires site access. you need to pass site_id (not id) field
     * @return boolean
     */
    function isCapable($cap, $siteId = null) {
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
     * @param    $capability    string    the name of the capability (e.g. 'view_reports')
     * @return    boolean
     */
    function isSiteAccessRequiredForCapability( $capability ) {

        $capabilitiesThatRequireSiteAccess = \OWA\Core\CoreAPI::getSetting('base', 'capabilitiesThatRequireSiteAccess');
        if (is_array($capabilitiesThatRequireSiteAccess) && in_array($capability, $capabilitiesThatRequireSiteAccess)) {
            return true;
        }
    }

    /**
     * Checks to see if the a site is accessible to a user
     *
     * @param    string    $siteId    the siteId of the site in question
     * @return    boolean
     */
    function isSiteAccessible( $siteId ) {

        if ( is_null($siteId) ) {
            throw new \InvalidArgumentException('Cannot tell if site is accessible to user without a siteId (none given).');
        }

        if ( $this->user->isAdmin() ) {
            return true;
        }

        if ( ! $this->isAssignedSitesListLoaded ) {
            //$this->loadAssignedSites();
        }

        if ( isset( $this->assignedSites[ $siteId ] ) ) {
            \OWA\Core\CoreAPI::debug("Site ID: $siteId in accessible list for this user.");
            return true;
        } else {
            \OWA\Core\CoreAPI::debug("Site ID: $siteId is not in accessible list for this user.");
        }
    }

    // mark the user as authenticated and populate their capabilities
    function setAuthStatus($bool) {
        $this->is_authenticated = true;
    }

    function isAuthenticated() {
        return $this->is_authenticated;
    }


    /**
     * Loads internal $this->assignedSites member
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

        $site_ids = array();
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( 'owa_site_user' );
        $db->selectColumn( '*' );
        $db->where( 'user_id', $this->user->get('id') );
        $site_ids = $db->getAllRows();

        // filter array of site_ids.
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $site_ids = $dispatch->filter('allowed_sites_list', $site_ids);

        $this->setAllowedSitesList($site_ids);
    }

    public function setInitialized() {
	    
        $this->isInitialized = true;
    }

    /**
     * Grant this user the sites matching a list of domains.
     *
     * Resolves each domain with a lookup. It used to COMPUTE the key instead --
     * generateId( generateSiteId( $domain ) ) -- which worked only while a
     * site's identity was md5 of its domain. Identifiers are now minted, so a
     * computed key would name a site that does not exist, and this would grant
     * access to nothing while appearing to succeed.
     *
     * Nothing calls this. It is corrected rather than removed because it is a
     * public method that could be called from a module, and a permission helper
     * that silently resolves to the wrong sites is worse than one that is
     * merely unused. A domain with no site is skipped, so an unknown domain
     * grants nothing rather than granting something arbitrary.
     */
    public function loadAssignedSitesByDomain($domains) {

        if ( $domains ) {
            $site_ids = array();

            foreach ($domains as $domain) {

                $site = \OWA\Core\CoreAPI::entityFactory('base.site');
                $site->load( $domain, 'domain' );

                if ( $site->wasPersisted() ) {

                    $site_ids[] = array('site_id' => $site->get('id') );
                }
            }

            $this->setAllowedSitesList($site_ids);
        }
    }

    private function setAllowedSitesList($site_ids) {

        $list = array();

        if ( ! empty($site_ids) ) {
            foreach ($site_ids as $row) {
                $siteEntity = \OWA\Core\CoreAPI::entityFactory('base.site');
                $siteEntity->load($row['site_id']);
                $list[ $siteEntity->get('site_id') ] = $siteEntity;
            }
        }

        $this->assignedSites = $list;
        $this->isAssignedSitesListLoaded = true;
    }

    public function getAssignedSites() {

        return $this->assignedSites;
    }


    public function isOWAAdmin() {

        return $this->user->isOWAAdmin();
    }

    public function isAdmin() {

        return $this->user->isAdmin();
    }

    public function isAnonymousUser() {

        if ( ! $this->user->get('user_id') || $this->getRole() === 'everyone') {
	        
            return true;
            
        }
    }
}



?>