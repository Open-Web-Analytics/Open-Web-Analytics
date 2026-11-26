<?php

require_once(__DIR__ . '/owa_env.php');

/**
 * OWA Core
 * 
 * Main core class wrapper.
 */

class owa extends \OWA\Core\Caller {

    public function __construct($config = null) {

        parent::__construct($config);
    }
}
