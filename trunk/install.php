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

include_once('owa_env.php');
require_once(OWA_BASE_DIR.'/owa_php.php');

/**
 * Install Page Wrapper Script
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Initialize owa
//define('OWA_ERROR_HANDLER', 'development');
define('OWA_CACHE_OBJECTS', false);
define('OWA_INSTALLING', true);
$owa = new owa_php();
if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

	// need third param here so that seting is not persisted.
	$owa->setSetting('base','main_url', 'install.php');
	// run controller, echo page content
	$do = owa_coreAPI::getRequestParam('do'); 
	$params = array();
	if (empty($do)) {
		
		$params['do'] = 'base.installStart';
	}
	
	// run controller or view and echo page content
	echo $owa->handleRequest($params);

} else {
	// unload owa
	$owa->restInPeace();
}

?>