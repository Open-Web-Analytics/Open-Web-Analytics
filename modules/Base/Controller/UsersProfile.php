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
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UsersProfile extends \OWA\Core\Controller {

    function __construct($params) {

        $this->setRequiredCapability('edit_users');
        return parent::__construct($params);
    }

    function action() {

        // This needs form validation in a bad way.
        //Check to see if user is passed by constructor or else fetch the object.
        if ($this->getParam('user_id')) {
            $u = \OWA\Core\CoreAPI::entityFactory('base.user');
            $u->getByColumn('user_id', $this->getParam('user_id'));
            $this->set('profile', $u->_getProperties());
            $this->set('edit', true);
            $this->set('user_id', $this->getParam('user_id'));
        } else {
            $this->set('edit', false);
            $this->set('profile', array());
        }

        /*
         * The hierarchy wrapper. There is one settings nav now -- the old
         * base.options menu is gone -- so every settings screen carries the tile
         * and the tier groups, module screens included.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 1 );
        $this->setView('base.optionsHierarchy');
        $this->setSubview('base.usersProfile');

    }

}



?>
