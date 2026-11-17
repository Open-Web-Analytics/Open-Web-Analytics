<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

require_once(__DIR__ . '/owa_env.php');

/**
 * OWA Core
 * 
 * Main core class wrapper.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 */

class owa extends \OWA\Core\Caller {

    /**
     * The instance singleton() answers, made by its first call.
     *
     * @var owa|null
     */
    private static $instance = null;

    public function __construct($config = null) {

        parent::__construct($config);
    }

    /**
     * OWA Singleton Method
     *
     * The one owa of this process: owa::singleton( $config ). Calling it on an
     * instance answers the same one.
     *
     * $config reaches the constructor on the first call only. The constructor
     * is the boot: it takes the request type from $config, loads the stored
     * settings, applies $config over them, then sets the error handler. A later
     * call's $config is not applied (a debug line says so); setSetting()
     * changes a value after boot.
     *
     * @param  array<mixed>|null $config as the constructor takes it; used by the first call only
     * @return owa
     */
    public static function singleton($config = null) {

        if ( self::$instance === null ) {

            self::$instance = new owa($config);

        } elseif ( $config ) {

            \OWA\Core\CoreAPI::debug( 'owa::singleton(): the instance exists, so this config is not applied.' );
        }

        return self::$instance;
    }
}

?>