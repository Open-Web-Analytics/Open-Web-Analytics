<?php
namespace OWA\Module\Base\Controller;

/**
 * A Property's Observation Profiles: the roster a Profile is reached from.
 *
 * Reports are of a Property, so the site control stops there and a Profile is
 * no longer picked from it. Its own screens -- details, how it observes, its
 * tag -- are reached from a row here.
 */
class PropertyProfiles extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    function action() {

        $property_id = (string) $this->getParam( 'propertyId' );

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( $property_id !== '' ) {

            $property->load( $property_id );
        }

        $this->set( 'property', $property->_getProperties() );
        $this->set( 'propertyId', $property_id );
        $this->set( 'profiles', $property_id !== '' ? self::rowsFor( $property_id ) : array() );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /* Tier 2: a list of the Property's Profiles is about the Property. */
        $this->set( 'hierarchy_tier', 2 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( '', $property_id ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.propertyProfiles' );
    }

    /**
     * The live Profiles of a Property, by name.
     *
     * @param  string $property_id
     * @return array[] each the Profile's columns
     */
    public static function rowsFor( $property_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $property_id );
        // ASC explicitly: orderBy() with no direction is DESC.
        $db->orderBy( 'name', OWA_SQL_ASCENDING );

        $rows = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            // Archived means removed; read as falsy, never as IS NULL (Site).
            if ( empty( $row['archived_date'] ) ) {

                $rows[] = $row;
            }
        }

        return $rows;
    }
}

?>
