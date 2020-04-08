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

include_once('../owa_env.php');
require_once(OWA_BASE_DIR.'/owa_php.php');

/**
 * REST API
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2020 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @since       owa 1.6.7
 */

// define entry point cnstant
define('OWA_RESTAPI', true);

// invoke OWA
$owa = new owa_php;
$owa->setSetting('base', 'request_mode', 'rest_api');

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {
	
	//$owa->setSetting('base', 'rest_api_mode', true);
	
	$s = owa_coreAPI::serviceSingleton();

	
    // run api command and echo page content
    echo $owa->handleRequest();
} else {
    // unload owa
    $owa->restInPeace();
}

?>