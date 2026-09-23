<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The custom dimensions of one Property.
 *
 * A site can set any event or user property it likes and ingest stores them
 * all; registering one here is what makes it QUERYABLE, by adding a real column
 * to that Property's reporting cube for a build to fill.
 *
 * PER PROPERTY, because the cube is. Two Properties may use the same key for
 * different things, which is the whole point -- v1's numbered slots forced one
 * namespace on an installation and cv3 meant something different on every site.
 * GA registers custom definitions on the property for the same reason.
 *
 * ADDRESSED BY siteId even so, like the goal events screen: it is reached from
 * a Profile in the nav and resolves the Property itself, so the context line
 * above it stops at the Profile (tier 3) while getHierarchyNav() files the
 * ENTRY under Property, where the data lives.
 */
class CustomDimensions extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';

        /*
         * Registering issues DDL against the table every report reads, which is
         * the same reach as editing the installation's settings rather than
         * editing a site.
         */
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $siteId );

        $property_id = (string) $site->get( 'property_id' );

        $this->set( 'siteId', $siteId );
        $this->set( 'propertyId', $property_id );
        $this->set( 'dimensions',
            \OWA\Module\Base\Classes\Cube\Dimensions::forProperty( $property_id ) );

        $this->set( 'cube', self::cubeState( $property_id ) );
        $this->set( 'scopes', \OWA\Module\Base\Entity\CustomDimension::scopes() );
        $this->set( 'types', \OWA\Module\Base\Entity\CustomDimension::types() );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.customDimensions' );
    }

    /**
     * What the screen needs to know about the cube behind this Property.
     *
     * THE CAPACITY IS MEASURED, NOT THE CONSTANT. Twenty is an outer cap; how
     * many a server will actually take depends on which row limit binds there,
     * and that differs by MySQL version -- 19 on 8.0 against 20 on 8.4 for a
     * cube of the same shape. Showing the cap where the server allows fewer
     * would promise room that the next registration refuses.
     *
     * A PROPERTY WITH NO CUBE HAS NOTHING TO REGISTER AGAINST. A cube is
     * created by a build on the Property's first data, so this is the ordinary
     * state of a Property that has not collected anything yet, and the screen
     * says so rather than offering a form that cannot work.
     *
     * @param string $property_id
     * @return array
     */
    public static function cubeState( $property_id ) {

        $table  = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property_id );
        $exists = $table
            && \OWA\Core\CoreAPI::dbSingleton()->tableExists( $table );

        return array(
            'table'    => $table,
            'exists'   => $exists,
            'capacity' => $exists
                ? \OWA\Module\Base\Classes\Cube\Dimensions::capacityFor( $property_id )
                : \OWA\Module\Base\Classes\Cube\Dimensions::MAX_PER_PROPERTY,
            'cap'      => \OWA\Module\Base\Classes\Cube\Dimensions::MAX_PER_PROPERTY,
        );
    }
}

?>
