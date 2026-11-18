<?php
namespace OWA\Core;

/**
 * Install Abstract Class
 */
class Install extends \OWA\Core\Base{

    /**
     * Data access object
     *
     * @var object
     */
    public $db;

    /**
     * Version of string
     *
     * @var string
     */
    public $version;

    /**
     * Params array
     *
     * @var array<string, mixed> name => value as the request carried it
     */
    public $params;

    /**
     * Constructor
     *
     * @return \OWA\Core\Install
     */

    public function __construct() {

        parent::__construct();
        $this->db = \OWA\Core\CoreAPI::dbSingleton();
    }

}
