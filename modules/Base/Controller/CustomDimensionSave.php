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
 * THE SCREEN CANNOT DO THE ALTER, and that is why this returns so quickly.
 * Adding the column is a full table rebuild -- measured at 11.3 seconds on a
 * 1.5M-row table, and longer on a real cube -- which is past PHP's 30-second
 * limit, Varnish's 60 and the load balancer's 65. Worse, a request killed at
 * any of those does NOT stop the ALTER: the client dies and MySQL finishes
 * anyway, so a synchronous form would show a timeout, add the column
 * regardless, and invite a retry that pays a second rebuild before failing on
 * a duplicate column.
 *
 * So this writes the registration and returns. The column arrives at the next
 * cube build, or sooner from the apply-custom-dimensions job, whichever comes
 * first -- both under the lock that keeps an ALTER from landing between a
 * build's staging copy and its swap.
 */
class CustomDimensionSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required',
            array( 'errorMsg' => 'A custom dimension belongs to a Property.' ) );

        $key = trim( (string) $this->getParam( 'dimensionKey' ) );

        $this->addValidation( 'dimensionKey', $key, 'required',
            array( 'errorMsg' => 'A custom dimension needs the name the tracker sets it under.' ) );

        if ( $key === '' || ! $this->getParam( 'propertyId' ) ) {

            return;
        }

        /*
         * THE REGISTRAR'S OWN RULES, ASKED BEFORE ANYTHING IS WRITTEN, so a
         * refusal arrives as an ordinary form error: on the form, with the
         * reason, keeping what was typed. Restating the rules here would be a
         * second copy to keep in step with the CLI, and every refusal the
         * registrar produces is already written for a person to read -- the
         * name pattern, the duplicate, the budget, the Property with no cube.
         *
         * Registered as a FAILING 'required' on the field it is about, because
         * that is the framework's one way to carry a message to the form. The
         * field genuinely is invalid; this says why.
         */
        $refusal = \OWA\Module\Base\Classes\Cube\Dimensions::refusalFor(
            (string) $this->getParam( 'propertyId' ),
            array(
                'key'   => $key,
                'scope' => (string) $this->getParam( 'scope' ),
                'type'  => (string) $this->getParam( 'dataType' ),
            ) );

        if ( $refusal !== '' ) {

            $this->addValidation( 'dimensionKey', '', 'required',
                array( 'errorMsg' => $refusal ) );
        }
    }

    /**
     * Back to the list, with the reason and what was typed.
     *
     * The form lives on the list screen rather than on one of its own, so
     * there is nowhere else for a refusal to land.
     */
    function errorAction() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $siteId );

        $property_id = (string) $site->get( 'property_id' );

        $this->set( 'siteId', $siteId );
        $this->set( 'propertyId', $property_id );
        $this->set( 'dimensions',
            \OWA\Module\Base\Classes\Cube\Dimensions::forProperty( $property_id ) );
        $this->set( 'cube', CustomDimensions::cubeState( $property_id ) );
        $this->set( 'scopes', \OWA\Module\Base\Entity\CustomDimension::scopes() );
        $this->set( 'types', \OWA\Module\Base\Entity\CustomDimension::types() );

        /* What was typed, so a refused form comes back carrying it. */
        $this->set( 'submitted', array(
            'dimensionKey' => trim( (string) $this->getParam( 'dimensionKey' ) ),
            'scope'        => (string) $this->getParam( 'scope' ),
            'dataType'     => (string) $this->getParam( 'dataType' ),
            'label'        => trim( (string) $this->getParam( 'label' ) ),
        ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.customDimensions' );
    }

    function action() {

        $property_id = (string) $this->getParam( 'propertyId' );

        $result = \OWA\Module\Base\Classes\Cube\Dimensions::register( $property_id, array(
            array(
                'key'   => trim( (string) $this->getParam( 'dimensionKey' ) ),
                'scope' => (string) $this->getParam( 'scope' ),
                'type'  => (string) $this->getParam( 'dataType' ),
                'label' => trim( (string) $this->getParam( 'label' ) ),
            ),
        ) );

        $this->set( 'siteId', $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.customDimensions' );

        if ( ! $result['ok'] ) {

            /*
             * validate() asked the same question and was satisfied, so getting
             * here means the answer changed in between -- another registration
             * taking the last of the budget, or a cube that grew. Rare, and
             * survivable: nothing was written.
             */
            \OWA\Core\CoreAPI::notice( sprintf(
                'Registering %s was refused: %s',
                $this->getParam( 'dimensionKey' ), $result['error'] ) );

            $this->set( 'status_code', 3200 );

            return;
        }

        $this->set( 'status_code', 3210 );
    }
}

?>
