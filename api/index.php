<?php

require_once(__DIR__ . '/../owa.php');

/**
 * REST API
 */

// invoke OWA

$config = [

    'request_type' => 'rest'
];

$owa = new owa( $config );

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {
    
    $s = \OWA\Core\CoreAPI::serviceSingleton();

    
    // run api command and echo page content
    echo $owa->handleRestRequest();
}
