<?php

namespace OWA\Module\Base\Entity;

/** User to Site Relation Entity */

class SiteUser extends \OWA\Core\Entity {

    public function __construct() {
        $this->setTableName('site_user');
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'site_id' , OWA_DTD_BIGINT ) );
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'user_id' , OWA_DTD_INT ) );
    }



}
