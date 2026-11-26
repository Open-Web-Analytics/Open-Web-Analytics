<?php

// Automatic updates: until a new release being installed has booted (the
// install marker), a 503 maintenance page, before any OWA file loads (updater.php).
if ((require __DIR__ . '/updater.php')['serveMaintenance'](__DIR__)) {
    exit;
}

require_once(__DIR__ . '/owa.php');

/**
 * Main Admin Page Wrapper Script
 */

// Initialize owa admin

$config = [

    'request_type' => 'ui'
];

$owa = new owa( $config );

if (!$owa->isOwaInstalled()) {
    // redirect to install
    \OWA\Core\Lib::redirectBrowser(\OWA\Core\CoreAPI::getSetting('base','public_url').'install.php');
}

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    /*
     * Every page this answers is the admin or the reports, for whoever is
     * signed in. Without this a host's page cache could store one user's page
     * and serve it to the next request for the same URL.
     */
    header( 'Cache-Control: private, no-store' );

    // Framed by OWA's own pages only, so no other site can draw one under a
    // decoy and steer a signed-in user's clicks. Nothing of OWA's is framed
    // elsewhere: the overlay launcher frames the tracked site, not the reverse.
    header( "Content-Security-Policy: frame-ancestors 'self'" );
    header( 'X-Frame-Options: SAMEORIGIN' );

    
    $params = [];
    
    $do = \OWA\Core\CoreAPI::getRequestParam('do');
    
    if ( ! $do ) {
    
        $params['do'] = $owa->getSetting('base', 'start_page');
    }
    // run controller or view and echo page content
    echo $owa->handleRequest( $params );
    
}
