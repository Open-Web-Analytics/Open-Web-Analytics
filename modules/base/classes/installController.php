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

require_once(OWA_DIR.'owa_controller.php');

/**
 * Abstract Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_installController extends owa_controller {

    var $is_installer = true;
    var $im;

    function __construct( $params ) {
        
        
        // needed just in case a re-install happens and updates are also needed.
        // tells the controller to skip the updates redirect. Also tells the main owa_caller
        // class not to load the config from the DB which doesn't exist during the install.
        if (!defined('OWA_INSTALLING')) {
            define('OWA_INSTALLING', true);
        }
        
        $this->setRequiredCapability('install_schema');
          
        $this->im = owa_coreAPI::supportClassFactory('base', 'installManager');
        
        return parent::__construct( $params );
    }

    function pre() {

        if ( $this->isInstallComplete() ) {
            
            owa_coreAPI::debug( 'Install is already complete. redirecting to public url' );
            return $this->redirectBrowserToUrl( owa_coreAPI::getSetting( 'base', 'public_url' ) );
        }
    }

    function installSchema() {
        
        return $this->im->installSchema();
    }

    function createAdminUser( $user_id, $email_address, $password = '' ) {

        return $this->im->createAdminUser( $user_id, $email_address, $password );
    }

    function createDefaultSite( $domain, $name = '', $description = '', $site_family = '', $site_id = '' ) {
        
        return $this->im->createDefaultSite( $domain, $name, $description, $site_family, $site_id );
    }

    function checkDbConnection() {
        
        return $this->im->checkDbConnection();
    }
    
    function isInstallComplete() {
	    
	    return $this->im->isInstallComplete();
    }
}

?>