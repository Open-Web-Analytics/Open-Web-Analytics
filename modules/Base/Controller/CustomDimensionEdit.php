<?php
namespace OWA\Module\Base\Controller;

/**
 * Register one custom dimension.
 *
 * Its own screen, reached from the list, like every other record in the admin:
 * a Goal Event Definition, a User, a Site. A refused registration comes back here with the
 * reason and what was typed (CustomDimensionSave::errorAction()).
 *
 * CREATE-ONLY. A registered dimension has nothing to edit: its name is the key
 * the tracker sets and its type is fixed when the column is made. Changing
 * either is removing it and registering again, which the list offers.
 */
class CustomDimensionEdit extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';

        /* The same reach as the list: registering issues DDL against the cube. */
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        self::prepare( $this, $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) ) );
    }

    /**
     * Everything the register screen reads, for this screen and for the save's
     * errorAction(), which renders it again after a refusal.
     *
     * @param \OWA\Core\AdminController $controller
     * @param string $property_id
     * @param array<string, mixed> $submitted what was typed, when a refused form comes back
     * @return void
     */
    public static function prepare( $controller, $property_id, array $submitted = [] ) {

        $property_id = (string) $property_id;

        $controller->set( 'propertyId', $property_id );
        $controller->set( 'cube', CustomDimensions::cubeState( $property_id ) );
        $controller->set( 'used', count(
            \OWA\Module\Base\Classes\Cube\Dimensions::forProperty( $property_id ) ) );
        $controller->set( 'scopes', \OWA\Module\Base\Entity\CustomDimension::scopes() );
        $controller->set( 'types', \OWA\Module\Base\Entity\CustomDimension::types() );
        $controller->set( 'submitted', array_merge( [
            'dimensionKey' => '', 'scope' => '', 'dataType' => '', 'label' => '',
        ], $submitted ) );

        $controller->settingsChrome( [ 'propertyId' => $property_id ], '', $property_id );
        $controller->setSubview( 'base.customDimensionEdit', [
            'template' => 'custom_dimension_edit.php',
            'vars'     => [ 'propertyId', 'cube', 'scopes', 'types', 'submitted', 'used' ] ] );
    }
}
