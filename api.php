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
 * REST API
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 * @link		http://wiki.openwebanalytics.com/index.php?title=REST_API
 */

// define entry point cnstant
define('OWA_API', true);
// invoke OWA
$owa = new owa_php;

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

	// run api command and echo page content
	echo $owa->handleRequest('', 'base.apiRequest');
} else {
	// unload owa
	$owa->restInPeace();
}

?>