<?php
namespace OWA\Module\Base\Controller;

/**
 * Saves a Property's Data Retention page.
 *
 * The shorter window is applied by the next rotate-partitions run; a longer
 * one queues the rebuild of the months it now covers here, at once.
 *
 * @phpstan-import-type FieldSet from \OWA\Module\Base\Classes\Settings
 */
class PropertyRetentionUpdate extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    public function validate() {

        // Runs from Core\Controller::doAction() after the capability and nonce
        // checks. Nothing here may assume a Property was named.
        $id       = (string) $this->getParam( 'propertyId' );
        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( ctype_digit( $id ) ) {

            $property->load( $id );
        }

        if ( ! ctype_digit( $id ) || ! $property->wasPersisted() ) {

            $this->addValidationError( 'propertyId', \OWA\Core\CoreAPI::t( 'That Property does not exist.' ) );

            return;
        }

        foreach ( \OWA\Module\Base\Classes\SettingsForm::scopedProblems( self::fieldSet(), 'property',
                (string) $this->getParam( 'propertyId' ), (array) $this->getParam( 'config' ),
                (array) $this->getParam( 'override' ) ) as $key => $problem ) {

            $this->addValidationError( $key, $problem );
        }
    }

    public function action() {

        $propertyId = (string) $this->getParam( 'propertyId' );

        \OWA\Module\Base\Classes\SettingsForm::saveScoped( self::fieldSet(), 'property', $propertyId,
            (array) $this->getParam( 'config' ), (array) $this->getParam( 'override' ) );

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $propertyId );
        $owed  = $table && \OWA\Core\CoreAPI::dbSingleton()->tableExists( $table )
            ? \OWA\Module\Base\Classes\Retention::backfillFor( $propertyId, $table,
                \OWA\Module\Base\Classes\Retention::cubeMonths( $propertyId ) )
            : null;

        if ( $owed ) {

            \OWA\Module\Base\Classes\Retention::enqueueBackfills( [ $owed ] );
        }

        $this->setRedirectAction( 'base.propertyRetention' );
        $this->setStatusCode( 2500 );
    }

    /**
     * The Property's Data Retention screen drawn again with what was posted
     * and each refusal beside its field (SettingsForm::redrawRefused()).
     *
     * @return mixed the screen's answer
     */
    public function errorAction() {

        return \OWA\Module\Base\Classes\SettingsForm::redrawRefused( $this->params, (array) $this->getValidationErrorMsgs(),
            PropertyRetention::class );
    }

    /**
     * @return FieldSet
     */
    private static function fieldSet() {

        return \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.propertyRetention' );
    }
}
