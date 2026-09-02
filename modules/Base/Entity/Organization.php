<?php

namespace OWA\Module\Base\Entity;

/**
 * The top tier: who is doing the measuring.
 *
 * User accounts belong to an organization and roles are scoped to it. An
 * install has exactly one until multi-organization membership is built --
 * deliberately, because "which organization am I acting in?" is a real question
 * and nothing yet needs to ask it.
 *
 * Migrated and freshly installed instances get one named "My Organization"
 * (PLAN.html §3.5).
 */
class Organization extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'organization' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        $description = new \OWA\Module\Base\Classes\DbColumn( 'description', OWA_DTD_TEXT );
        $this->setProperty( $description );

        $settings = new \OWA\Module\Base\Classes\DbColumn( 'settings', OWA_DTD_BLOB );
        $this->setProperty( $settings );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }
}

?>
