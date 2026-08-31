<?php

namespace OWA\Module\Base\Entity;

/**
 * The middle tier: the website or app being measured.
 *
 * Holds what the thing IS -- name, domain, description -- as opposed to how it
 * is measured, which belongs to the observation profiles beneath it. A property
 * may have several profiles, which is what lets two schema generations run side
 * by side and what lets one website be measured more than one way.
 *
 * ACCESS IS GRANTED HERE, not on the profile. Someone who may see a website
 * should see its measurements: a per-profile grant would mostly express "you
 * may read the v1 data but not the v2 data", which nobody wants and which would
 * silently split a user's view at cutover (PLAN.html §3.5).
 */
class Property extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'property' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $organization_id = new \OWA\Module\Base\Classes\DbColumn( 'organization_id', OWA_DTD_BIGINT );
        $organization_id->setIndex();
        $this->setProperty( $organization_id );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /*
         * The domain describes the website, so it lives here rather than on the
         * profile -- two profiles measuring one website necessarily share it,
         * which is exactly what md5( domain ) as an identifier made impossible
         * before identity was decoupled from it.
         */
        $domain = new \OWA\Module\Base\Classes\DbColumn( 'domain', OWA_DTD_VARCHAR255 );
        $domain->setIndex();
        $this->setProperty( $domain );

        $description = new \OWA\Module\Base\Classes\DbColumn( 'description', OWA_DTD_TEXT );
        $this->setProperty( $description );

        $settings = new \OWA\Module\Base\Classes\DbColumn( 'settings', OWA_DTD_BLOB );
        $this->setProperty( $settings );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }
}

?>
