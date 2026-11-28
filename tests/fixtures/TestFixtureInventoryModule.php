<?php
/**
 * A module for Classes\TableInventory's tests: two entities, one of each
 * backup group, and a constructor that counts the times it runs. Loaded with
 * require_once; it is not on disk under modules/, is never active, and the
 * inventory is handed its class.
 */

namespace OWA\Module\TestInventory {

    class Module extends \OWA\Core\Module {

        const NAME = 'test_inventory';

        const ENTITIES = [ 'probe_log', 'probe_setting' ];

        /** @var int how many times the constructor has run */
        public static $built = 0;

        public function __construct() {

            self::$built++;

            parent::__construct();
        }
    }
}

namespace OWA\Module\TestInventory\Entity {

    /** A table tracking writes. */
    class ProbeLog extends \OWA\Core\Entity {

        protected $backup_group = self::BACKUP_DATA;

        public function __construct() {

            $this->setTableName( 'test_inventory_probe_log' );
        }
    }

    /** A table of the application group, the default. */
    class ProbeSetting extends \OWA\Core\Entity {

        public function __construct() {

            $this->setTableName( 'test_inventory_probe_setting' );
        }
    }
}
