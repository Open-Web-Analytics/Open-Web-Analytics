<?php
namespace OWA\Module\Base\Controller;

/**
 * Archive a Property, and every Observation Profile under it
 * (Classes\PropertyArchive::archive(), which cmd=property --archive runs too).
 *
 * The cascade is downward and explicit: a Property is how someone says "this
 * website", so removing it means removing the ways it was being watched. It
 * does NOT work the other way -- archiving the last Profile of a Property
 * leaves the Property empty and reachable, which is what makes it possible to
 * start its Profiles over.
 *
 * Nothing is destroyed. Both tiers take an archived_date, and everything
 * hanging off them -- grants, scoped settings, collected data -- is left where
 * it is. cmd=property --restore brings them back.
 */
class PropertyDelete extends \OWA\Core\AdminController {

    // create_properties, held install-wide, and a Property the user may see.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'create_properties' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required' );

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists',
            [
                'entity'   => 'base.property',
                'column'   => 'id',
                'errorMsg' => \OWA\Core\CoreAPI::t( 'That Property does not exist.' ),
            ] );
    }

    public function action() {

        \OWA\Module\Base\Classes\PropertyArchive::archive( $this->getParam( 'propertyId' ) );

        // Not to the Property just deleted: setRedirectAction() would carry it.
        $this->set( 'propertyId', '' );
        $this->setRedirectAction( 'base.reportingHome' );
        $this->setStatusCode( 3205 );
    }
}
