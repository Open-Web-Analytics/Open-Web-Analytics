<?php

require_once(__DIR__ . '/../owa.php');

/**
 * REST API
 */

// define entry point constant
define('OWA_RESTAPI', true);

// invoke OWA

$config = [

    'request_type' => 'rest'
];

$owa = new owa( $config );

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {
    
    //$owa->setSetting('base', 'rest_api_mode', true);
    
    $s = \OWA\Core\CoreAPI::serviceSingleton();

    
    // run api command and echo page content
    echo $owa->handleRestRequest();
}
