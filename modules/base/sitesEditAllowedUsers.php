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

require_once(OWA_BASE_MODULE_DIR.'/sitesEditSettings.php');

/**
 * Edit Sites allowed Users
 * 
 * @author     Daniel Pötzinger <poetzinger@aoemedia.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_sitesEditAllowedUsersController extends owa_sitesEditSettingsController {



    function action() {

        $site_id = $this->getParam( 'siteId' );
        $siteEntity = owa_coreAPI::entityFactory( 'base.site' );
        $siteEntity->load( $siteEntity->generateId( $site_id ) );
        owa_coreAPI::debug( $siteEntity->_getProperties());
        if ($this->getParam( 'allowed_users' ) ) {
            $siteEntity->updateAssignedUserIds($this->getParam( 'allowed_users' ));
        }
        else {
            $siteEntity->updateAssignedUserIds( array() );
        }
        //set variables for view
        $this->set('siteId', $site_id);
        $this->set('edit', true);
        $this->setStatusCode( 3201 );
        $this->setRedirectAction( 'base.sitesProfile' );

    }

}

?>