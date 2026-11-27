<?php
namespace OWA\Module\Base\Controller;

/**
 * Register one custom dimension.
 *
 * THE SCREEN CANNOT DO THE ALTER. Adding the column is a full table rebuild,
 * which on a real cube can outlast a web request's time limit, and a request
 * killed at that limit does NOT stop the ALTER: the client dies and MySQL finishes
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

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Property is required.' ) ] );

        $key = trim( (string) $this->getParam( 'dimensionKey' ) );

        $this->addValidation( 'dimensionKey', $key, 'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Dimension key is required.' ) ] );

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
         * refusalFor() measures nothing: its budget is the outer cap or a
         * capacity already measured. The measured budget is register()'s, in
         * action(), which reports a refusal on the form the same way.
         *
         * Registered as a FAILING 'required' on the field it is about, because
         * that is the framework's one way to carry a message to the form. The
         * field genuinely is invalid; this says why.
         */
        $refusal = \OWA\Module\Base\Classes\Cube\Dimensions::refusalFor(
            (string) $this->getParam( 'propertyId' ),
            [
                'key'   => $key,
                'scope' => (string) $this->getParam( 'scope' ),
                'type'  => (string) $this->getParam( 'dataType' ),
            ] );

        if ( $refusal !== '' ) {

            $this->addValidationError( 'dimensionKey', $refusal );
        }
    }

    /**
     * Back to the register screen, with the reason and what was typed.
     */
    public function errorAction() {

        CustomDimensionEdit::prepare( $this,
            $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) ),
            [
                'dimensionKey' => trim( (string) $this->getParam( 'dimensionKey' ) ),
                'scope'        => (string) $this->getParam( 'scope' ),
                'dataType'     => (string) $this->getParam( 'dataType' ),
                'label'        => trim( (string) $this->getParam( 'label' ) ),
            ] );
    }

    public function action() {

        $property_id = (string) $this->getParam( 'propertyId' );

        $result = \OWA\Module\Base\Classes\Cube\Dimensions::register( $property_id, [
            [
                'key'   => trim( (string) $this->getParam( 'dimensionKey' ) ),
                'scope' => (string) $this->getParam( 'scope' ),
                'type'  => (string) $this->getParam( 'dataType' ),
                'label' => trim( (string) $this->getParam( 'label' ) ),
            ],
        ] );

        if ( ! $result['ok'] ) {

            // register() measures the budget; its refusal goes back to the form
            // as a failed validation does.
            $this->set( 'validation_errors', [ 'dimensionKey' => $result['error'] ] );
            $this->errorAction();

            return $this->data;
        }

        $this->set( 'propertyId', $property_id );
        $this->setRedirectAction( 'base.customDimensions' );
        $this->setStatusCode( 3210 );
    }
}
