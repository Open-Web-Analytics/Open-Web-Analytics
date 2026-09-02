<?php
namespace OWA\Module\Base\Controller;

/**
 * Remove one funnel and its steps.
 *
 * A real delete: a funnel owns no collected data. It describes a path through
 * events that stay exactly as they are, and the goal event it counted as is
 * untouched -- which is the point of the two being only loosely coupled.
 */
class FunnelDelete extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'funnelId', $this->getParam( 'funnelId' ), 'required' );
    }

    function action() {

        $funnelId = $this->getParam( 'funnelId' );

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( $entity->getTableName() );
        $db->where( 'funnel_id', $funnelId );
        $db->executeQuery();

        $funnel = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );
        $funnel->delete( $funnelId );

        $this->set( 'siteId', $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.funnels' );
        $this->set( 'status_code', 3204 );
    }
}
