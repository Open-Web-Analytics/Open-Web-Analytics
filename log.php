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

include_once(__DIR__ . '/owa_env.php');


/**
 * Special HTTP Requests Controler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

 // keep php executing even if the client closes the connection
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
    \OWA\Core\Lib::redirectBrowser( str_replace('log.php', 'blank.php', \OWA\Core\Lib::get_current_url() ) );
    // necessary or else buffer is not actually flushed
    echo ' ';
} else {
    // return 1x1 pixel gif: 43 bytes
    $pixel = sprintf(
        '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c',
        71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
    );

    header("Content-type: image/gif");
    /*
     * The real length. It said 42 for a 43-byte image, so the header did not
     * survive and the response went out chunked -- which a client can only
     * see the end of when the script ends, ingest and all.
     */
    header("Content-Length: " . strlen( $pixel ));
    header("Cache-Control: private, no-cache, no-cache=Set-Cookie, proxy-revalidate");
    header("Expires: Wed, 11 Jan 2000 12:59:00 GMT");
    header("Last-Modified: Wed, 11 Jan 2006 12:59:00 GMT");
    header("Pragma: no-cache");

    echo $pixel;
}

/*
 * End the response here, before anything is loaded: the visitor's browser
 * has its answer, and everything below -- booting OWA, ingest, the database
 * -- happens after it.
 *
 * flush() alone does not do that under PHP-FPM, which is how this runs on
 * most servers: the web server is not told the response is complete until
 * the script exits, so the request stayed open for the whole ingest.
 * fastcgi_finish_request() tells it now (LiteSpeed has its own); the flush
 * is what is left for a server API with neither.
 */
while ( ob_get_level() > 0 ) {
    ob_end_flush();
}

if ( function_exists( 'fastcgi_finish_request' ) ) {

    fastcgi_finish_request();

} elseif ( function_exists( 'litespeed_finish_request' ) ) {

    litespeed_finish_request();

} else {

    flush();
}

// Create instance of OWA
require_once(OWA_BASE_DIR.'/owa.php');
$config = array(

    'tracking_mode' => true,
    'instance_role' => 'logger'
);

$owa = new owa( $config );

// check to see if this endpoint is enabled.
if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    $owa->e->debug('Logging new tracking event...');
    
    $service = \OWA\Core\CoreAPI::serviceSingleton();
    $service->request->decodeRequestParams();
    $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
    // e_t from the current tracker, event_type from an older one.
    $event_type = \OWA\Core\CoreAPI::getRequestParam('e_t');
    $event->setEventType( $event_type !== false && $event_type !== ''
        ? $event_type : \OWA\Core\CoreAPI::getRequestParam('event_type') );
    /*
     * Only parameters a request is ALLOWED to set reach the event.
     *
     * A tracking request is untrusted input. This used to refuse the names the
     * server computes for itself and let everything else through -- a denylist,
     * and its own note said as much: unregistered names still passed. So the
     * gate was open for exactly the inputs nobody had thought about, which is
     * the shape of the mistake OWA already made once in the settings registry.
     *
     * Two things are admitted now and nothing else: a name some event declares
     * it carries, which the tracking property registry states; and a custom
     * value under one of the four scope/type prefixes -- eps_, epn_, vps_, vpn_
     * -- with a legal name. A site's own keys stay unrestricted, because the
     * prefix is a namespace rather than a list, so admitting them needs no
     * knowledge of a site's keys.
     *
     * A property the server derives is refused by construction: it is not
     * client-settable, so it is not in the admitted set.
     */
    $params = \OWA\Module\Base\Classes\Ingest::at( \OWA\Module\Base\Classes\Ingest::REQUEST_PRE,
        $service->request->getAllOwaParams() );

    $params = \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams( $params );

    $event->setProperties( $params );

    $event = \OWA\Module\Base\Classes\Ingest::at( \OWA\Module\Base\Classes\Ingest::REQUEST_POST, $event );

    \OWA\Core\CoreAPI::logEvent($event->getEventType(), $event);

} else {
    // unload owa
    $owa->restInPeace();
}

?>
