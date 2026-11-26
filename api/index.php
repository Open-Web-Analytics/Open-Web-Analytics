<?php

// Automatic updates: until a new release being installed has booted (the
// install marker), 503 busy in the REST envelope, before any OWA file loads (updater.php).
if ((require __DIR__ . '/../updater.php')['serveMaintenance'](dirname(__DIR__), true)) {
    exit;
}

require_once(__DIR__ . '/../owa.php');

/**
 * REST API
 */

// invoke OWA

$config = [

    'request_type' => 'rest'
];

$owa = new owa( $config );

// By its path from the install's root: index.php is the admin and reports.
if ( $owa->isEndpointEnabled( 'api/index.php' ) ) {
    
    $s = \OWA\Core\CoreAPI::serviceSingleton();

    
    // run api command and echo page content
    echo $owa->handleRestRequest();
}
