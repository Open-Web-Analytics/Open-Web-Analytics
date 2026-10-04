<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Saves a Property's Data Retention page.
 *
 * The shorter window is applied by the next rotate-partitions run; a longer
 * one queues the rebuild of the months it now covers here, at once.
 */
class PropertyRetentionUpdate extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    function validate() {

        // Runs from the parent constructor (Core\Controller), so nothing here may
        // assume a Property was named.
        $id       = (string) $this->getParam( 'propertyId' );
        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( ctype_digit( $id ) ) {

            $property->load( $id );
        }

        if ( ! ctype_digit( $id ) || ! $property->wasPersisted() ) {

            $this->addValidation( 'propertyId', '', 'required', array( 'errorMsg' => 'No such Property.' ) );

            return;
        }

        foreach ( \OWA\Module\Base\Classes\SettingsForm::scopedProblems( self::fieldSet(), 'property',
                (string) $this->getParam( 'propertyId' ), (array) $this->getParam( 'config' ),
                (array) $this->getParam( 'override' ) ) as $key => $problem ) {

            $this->addValidation( $key, '', 'required', array( 'errorMsg' => $problem ) );
        }
    }

    function action() {

        $propertyId = (string) $this->getParam( 'propertyId' );

        \OWA\Module\Base\Classes\SettingsForm::saveScoped( self::fieldSet(), 'property', $propertyId,
            (array) $this->getParam( 'config' ), (array) $this->getParam( 'override' ) );

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $propertyId );
        $owed  = $table && \OWA\Core\CoreAPI::dbSingleton()->tableExists( $table )
            ? \OWA\Module\Base\Classes\Retention::backfillFor( $propertyId, $table,
                \OWA\Module\Base\Classes\Retention::cubeMonths( $propertyId ) )
            : null;

        if ( $owed ) {

            \OWA\Module\Base\Classes\Retention::enqueueBackfills( array( $owed ) );
        }

        $this->set( 'propertyId', $propertyId );
        $this->setRedirectAction( 'base.propertyRetention' );
        $this->set( 'status_code', 2500 );
    }

    function errorAction() {

        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );
        $this->set( 'error_msg', implode( ' ', (array) $this->getValidationErrorMsgs() ) );
        $this->setRedirectAction( 'base.propertyRetention' );
    }

    private static function fieldSet() {

        return \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.propertyRetention' );
    }
}

?>
