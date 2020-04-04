<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2011 Peter Adams. All rights reserved.
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

require_once(OWA_BASE_MODULE_DIR.'sitesAdd.php');

/**
 * Add Site Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.4.1
 */

class owa_sitesAddCliController extends owa_sitesAddController {
    
    function init() {
		
		$this->setMode( 'cli' );
	}
	
	function errorAction() {
	
        $this->setView('base.genericCli');
    }
    
    function success() {
	   
	    $this->setView('base.genericCliView');
	    $site_id = $this->getParam('site_id');
	    $this->set('status_msg', "Site added successfully. site_id: $site_id");

    }    
}

?>