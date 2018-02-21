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
require_once(OWA_BASE_DIR.'/owa_lib.php');


/**
 * Special HTTP Requests Controler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

ignore_user_abort(true);

// turn off gzip compression
if ( function_exists( 'apache_setenv' ) ) {
	apache_setenv( 'no-gzip', 1 );
}

ini_set('zlib.output_compression', 0);

// turn on output buffering if necessary
if (ob_get_level() == 0) {
   	ob_start();
}

// removing any content encoding like gzip etc.
header('Content-encoding: none', true);

//check to se if request is a POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	
	// redirect to blank.php
	owa_lib::redirectBrowser( str_replace('log.php', 'blank.php', owa_lib::get_current_url() ) );
	// necessary or else buffer is not actually flushed
	echo ' ';
} else {
	// return 1x1 pixel gif
	header("Content-type: image/gif");
	// needed to avoid cache time on browser side
	header("Content-Length: 42");
	header("Cache-Control: private, no-cache, no-cache=Set-Cookie, proxy-revalidate");
	header("Expires: Wed, 11 Jan 2000 12:59:00 GMT");
	header("Last-Modified: Wed, 11 Jan 2006 12:59:00 GMT");
	header("Pragma: no-cache");
	
	echo sprintf(
		'%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
		71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
	);	
}

// flush all output buffers. No reason to make the user wait for OWA.
ob_flush();
flush();
ob_end_flush();

// Create instance of OWA
require_once(OWA_BASE_DIR.'/owa_php.php');
$config = array(

	'tracking_mode' => true
);

$owa = new owa_php( $config );

// check to see if this endpoint is enabled.
if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {
	
	$owa->e->debug('Logging new tracking event from request.');
	$owa->logEventFromUrl();
	
} else {
	// unload owa
	$owa->restInPeace();
}

?>