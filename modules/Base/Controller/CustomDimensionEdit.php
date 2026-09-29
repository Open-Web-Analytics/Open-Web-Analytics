<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Register one custom dimension.
 *
 * Its own screen, reached from the list, like every other record in the admin:
 * a Goal Event, a User, a Site. A refused registration comes back here with the
 * reason and what was typed (CustomDimensionSave::errorAction()).
 *
 * CREATE-ONLY. A registered dimension has nothing to edit: its name is the key
 * the tracker sets and its type is fixed when the column is made. Changing
 * either is removing it and registering again, which the list offers.
 */
class CustomDimensionEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';

        /* The same reach as the list: registering issues DDL against the cube. */
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        self::prepare( $this, $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) ) );
    }

    /**
     * Everything the register screen reads, for this screen and for the save's
     * errorAction(), which renders it again after a refusal.
     *
     * @param \OWA\Core\AdminController $controller
     * @param string $siteId
     * @param array  $submitted  what was typed, when a refused form comes back
     */
    public static function prepare( $controller, $siteId, array $submitted = array() ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $siteId );

        $property_id = (string) $site->get( 'property_id' );

        $controller->set( 'siteId', $siteId );
        $controller->set( 'propertyId', $property_id );
        $controller->set( 'cube', CustomDimensions::cubeState( $property_id ) );
        $controller->set( 'used', count(
            \OWA\Module\Base\Classes\Cube\Dimensions::forProperty( $property_id ) ) );
        $controller->set( 'scopes', \OWA\Module\Base\Entity\CustomDimension::scopes() );
        $controller->set( 'types', \OWA\Module\Base\Entity\CustomDimension::types() );
        $controller->set( 'submitted', array_merge( array(
            'dimensionKey' => '', 'scope' => '', 'dataType' => '', 'label' => '',
        ), $submitted ) );

        $controller->set( 'params', array_merge( (array) $controller->params, array( 'siteId' => $siteId ) ) );
        $controller->set( 'site_hierarchy',
            $controller->getSiteHierarchy( $controller->getSitesAllowedForCurrentUser() ) );
        $controller->set( 'hierarchy_tier', 3 );
        $controller->set( 'hierarchy_nav', $controller->getHierarchyNav( $siteId ) );
        $controller->setView( 'base.optionsHierarchy' );
        $controller->setSubview( 'base.customDimensionEdit' );
    }
}

?>
