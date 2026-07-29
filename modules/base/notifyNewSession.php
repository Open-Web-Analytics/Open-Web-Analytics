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

require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Notify New Session Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class NotifyNewSession extends \owa_controller {
        
    function action() {
        
        $event = $this->getParam( 'event' );
        $site = $this->getParam( 'site' );
        $this->set( 'site', $site->_getProperties() );
            
        $this->set( 'email_address', \owa_coreAPI::getSetting( 'base', 'notice_email' ) );
        $this->set( 'session', $event->getProperties() );
        
        $this->set( 'subject', sprintf('OWA: New Visit to %s', $site->get( 'domain' ) ) );
        //$this->set( 'plainTextView', 'base.notifyNewSessionPlainText');
        $this->setView( 'base.notifyNewSession' );
    }
}

require_once(OWA_BASE_DIR.'/owa_view.php');



?>
