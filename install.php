<?php

require_once(__DIR__ . '/owa.php');

/**
 * Install Page Wrapper Script
 */

// Initialize owa
//define('OWA_DEBUG', true);
define('OWA_INSTALLING', true);

// On a fresh install there is no owa-config.php yet, so OWA_DB_TYPE is undefined.
// Booting OWA constructs the base.configuration entity, whose column types
// reference OWA_DTD_BIGINT/OWA_DTD_BLOB -- constants define()d by the DB driver
// (Core/Db/), which entityFactory() only loads when
// OWA_DB_TYPE is set. Without it the driver never loads and the constructor
// fatals on an undefined constant (PHP 8), so install.php dies with a blank 500
// before the wizard can even render. Pre-define the type here so the storage
// engine loads and the DTD constants exist. Gated on the config file being
// absent so a normal post-install load is untouched (the config file's own
// unguarded define('OWA_DB_TYPE', ...) stays the single source of truth). No DB
// connection is attempted at boot in this state -- owa_caller gates that on
// isConfigFilePresent(). Mirrors the same guard in tests/bootstrap.php.
if ( ! defined( 'OWA_DB_TYPE' ) && ! file_exists( __DIR__ . '/owa-config.php' ) ) {
    define( 'OWA_DB_TYPE', 'mysql' );
}

$config = [

    'request_type' => 'install'
];

$owa = new owa( $config );
if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    // need third param here so that seting is not persisted.
    $owa->setSetting('base','main_url', 'install.php');
    // run controller, echo page content
    $do = \OWA\Core\CoreAPI::getRequestParam('do');
    $params = [];
    if (empty($do)) {

        $params['do'] = 'base.installStart';
    }

    // run controller or view and echo page content
    echo $owa->handleRequest($params);

}
