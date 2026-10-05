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
            array( 'errorMsg' => \OWA\Core\CoreAPI::t( 'Property is required.' ) ) );

        $key = trim( (string) $this->getParam( 'dimensionKey' ) );

        $this->addValidation( 'dimensionKey', $key, 'required',
            array( 'errorMsg' => \OWA\Core\CoreAPI::t( 'Dimension key is required.' ) ) );

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
     * Back to the register screen, with the reason and what was typed.
     */
    function errorAction() {

        CustomDimensionEdit::prepare( $this,
            $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) ),
            array(
                'dimensionKey' => trim( (string) $this->getParam( 'dimensionKey' ) ),
                'scope'        => (string) $this->getParam( 'scope' ),
                'dataType'     => (string) $this->getParam( 'dataType' ),
                'label'        => trim( (string) $this->getParam( 'label' ) ),
            ) );
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
