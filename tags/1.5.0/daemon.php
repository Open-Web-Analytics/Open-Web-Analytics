<?php
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006-2011 Peter Adams. All rights reserved.
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
 * OWA Daemon
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006-2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */
require_once('owa_env.php');
require_once(OWA_DIR.'owa_php.php');
require_once(OWA_BASE_CLASS_DIR.'daemon.php');

define('OWA_DAEMON', true);

if (!empty($_POST)) {
	exit();
} elseif (!empty($_GET)) {
	exit();
}

$owa = new owa_php();

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {
	// start daemon
	$daemon = new owa_daemon();
	$daemon->start();
	
} else {
	// unload owa
	$owa->restInPeace();
}

?>