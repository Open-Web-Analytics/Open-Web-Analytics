<?php
namespace OWA\Module\Base\Controller;


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



/**
 * Users Roster View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class Users extends \OWA\Core\AdminController {
        
    function __construct($params) {
        
        $this->setRequiredCapability('edit_users');
        return parent::__construct($params);
    }
    
    function action() {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_user');
        $db->selectColumn("*");
        $users = $db->getAllRows();
        
        // remove this after switch to REST API in Admin Interface
        $this->set('users', $users);
        
        $user_objs = \OWA\Core\CoreAPI::loadEntitiesFromArray( $users, 'base.user');
        $this->set('users_objs', $user_objs );
    }
    
    function success() {
	    
	    /*
	     * The hierarchy wrapper: user accounts live in the Organization, so this
	     * is an Organization screen reached from the site control's nav. The
	     * install settings nav beside it would offer a way out that has nothing
	     * to do with where you are.
	     */
	    $owa_site_id = $this->resolveCurrentSiteId();
	    $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
	    $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
	    /* Tier 1: this screen is about an Organization, so the context line stops there. */
	    $this->set( 'hierarchy_tier', 1 );
	    $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
	    $this->setView('base.optionsHierarchy');
        $this->setSubview('base.users');
    }
}




?>
