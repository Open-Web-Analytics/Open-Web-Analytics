<?php

namespace OWA\Module\Sqs\Controller;

/**
 * Settings page for the SQS tracking intake (PLAN 2.30.4a): the region, and
 * what provisioning found.
 */
class OptionsSqs extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $S = '\OWA\Module\Sqs\Classes\Sqs';

        $this->set( 'queue_name', $S::queueName() );
        $this->set( 'dlq_name', $S::deadLetterName( $S::queueName() ) );
        $this->set( 'credential_source', $S::credentialSource() );
        $this->set( 'region', $S::region() );
        $this->set( 'provisioned', \OWA\Core\CoreAPI::getSetting( 'sqs', 'provisioned' ) ?: null );
        $this->set( 'in_use', \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_ingest_queue_type' ) === 'sqs' );

        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 0 );
        $this->setView( 'base.optionsHierarchy' );
        $this->data['subview'] = 'sqs.optionsSqs';
        $this->data['view_method'] = 'delegate';

        return $this->data;
    }
}
