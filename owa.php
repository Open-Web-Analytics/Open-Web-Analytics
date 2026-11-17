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

    public function __construct($config = null) {

        parent::__construct($config);
    }

    /**
     * OWA Singleton Method
     *
     * Makes a singleton instance of OWA using the config array
     *
     * @param  array<mixed>|null $config as the constructor takes it; used by the first call only
     * @return owa
     */
    public function singleton($config = null) {

        static $owa;

        if( empty( $owa ) ) {
      
            $owa =  new owa($config);
        }
        
        return $owa;
    }
}

?>