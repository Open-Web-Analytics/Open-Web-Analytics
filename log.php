<?php

include_once(__DIR__ . '/owa_env.php');


/**
 * Special HTTP Requests Controler
 */

 // keep php executing even if the client closes the connection
ignore_user_abort(true);

// turn off gzip compression
if ( function_exists( 'apache_setenv' ) ) {
    apache_setenv( 'no-gzip', '1' );
}

ini_set('zlib.output_compression', 0);

// turn on output buffering if necessary
if (ob_get_level() == 0) {
       ob_start();
}

// removing any content encoding like gzip etc.
header('Content-encoding: none', true);

/*
 * A POST is a beacon sent by sendBeacon() or a keepalive fetch, or a 1.x
 * tracker's hidden-iframe form, and none of them reads the response: 204, no
 * body and nowhere to go, so the browser makes no second request. Answered
 * without settings, which are not built until after the response has ended.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    http_response_code( 204 );

} else {
    // return 1x1 pixel gif: 43 bytes
    $pixel = sprintf(
        '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c',
        71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
    );

    header("Content-type: image/gif");
    /*
     * The real length, so the header survives and the response is not sent
     * chunked -- which a client can only see the end of when the script ends,
     * ingest and all.
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
 * the script exits, so the request would stay open for the whole ingest.
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

/*
 * While an install copies files, the tree is part old and part new and
 * nothing can boot: the beacon is dropped (updater.php `dropsBeacon`). Once the
 * copy is done OWA boots, and while the schema is behind every beacon is
 * queued for the drain (TrackerIngest::isQueued()).
 */
if ((require __DIR__ . '/updater.php')['dropsBeacon'](__DIR__)) {
    exit;
}

// Create instance of OWA
require_once(__DIR__ . '/owa.php');
$config = [

    'tracking_mode' => true,
    'request_type' => 'tracking'
];

$owa = new owa( $config );

// check to see if this endpoint is enabled.
if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    $owa->e->debug('Logging new tracking event...');
    
    $service = \OWA\Core\CoreAPI::serviceSingleton();
    // An older beacon's second decode, and the URLs as they were before it (conf/beacon_compat.php).
    $arrived = \OWA\Module\Base\Classes\Beacon\Compat::decodeOlderBeacon( $service->request );
    $event = new \OWA\Module\Base\Classes\Event();
    // e_t from the current tracker, event_type from an older one.
    $event_type = \OWA\Core\CoreAPI::getRequestParam('e_t');
    $event->setEventType( $event_type !== false && $event_type !== ''
        ? $event_type : \OWA\Core\CoreAPI::getRequestParam('event_type') );
    /*
     * Only parameters a request is ALLOWED to set reach the event.
     *
     * A tracking request is untrusted input, so this is an allowlist. Two things
     * are admitted and nothing else: a name some event declares
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

    $params = \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams( $params ) + $arrived;

    $event->setProperties( $params );

    $event = \OWA\Module\Base\Classes\Ingest::at( \OWA\Module\Base\Classes\Ingest::REQUEST_POST, $event );

    \OWA\Core\CoreAPI::logEvent($event->getEventType(), $event);

}
