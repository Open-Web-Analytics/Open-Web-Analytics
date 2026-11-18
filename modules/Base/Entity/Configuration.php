<?php

namespace OWA\Module\Base\Entity;

/**
 * Configuration Entity
 */

class Configuration extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName('configuration');
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['settings'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['settings']->setDataType(OWA_DTD_BLOB);
        $this->setCachable();
    }
}
