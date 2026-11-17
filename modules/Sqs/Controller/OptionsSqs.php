<?php

namespace OWA\Module\Sqs\Controller;

/**
 * Settings page for the SQS tracking intake (PLAN 2.30.4a): the region, and
 * what provisioning found.
 */
class OptionsSqs extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $S = '\OWA\Module\Sqs\Classes\Sqs';

        $this->set( 'queue_name', $S::queueName() );
        $this->set( 'dlq_name', $S::deadLetterName( $S::queueName() ) );
        $this->set( 'credential_source', $S::credentialSource() );
        $this->set( 'region', $S::region() );
        $this->set( 'provisioned', \OWA\Core\CoreAPI::getSetting( 'sqs', 'provisioned' ) ?: null );
        $this->set( 'in_use', \OWA\Module\Base\Classes\TrackerIngest::backend() === 'sqs' );
        $this->set( 'queued', \OWA\Module\Base\Classes\TrackerIngest::isQueued() );

        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'hierarchy_tier', 0 );
        $this->settingsChrome( array( 'siteId' => $owa_site_id ), '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview( 'sqs.optionsSqs', [
            'template' => 'options_sqs.php',
            'vars'     => [ 'queue_name', 'dlq_name', 'credential_source', 'region', 'provisioned', 'in_use', 'queued',
                            'settings_fieldsets' => \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'sqs.optionsSqs' ) ],
        ] );

        return $this->data;
    }
}
