<?php
namespace OWA\Module\Sqs;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The tracking intake on AWS SQS (PLAN 2.30.4a).
 *
 * Registers the sqs queue type. An install, or a logging node, puts beacons
 * on it with:
 *
 *   define('OWA_TRACKER_INGEST_QUEUE_TYPE', 'sqs');
 *   define('OWA_QUEUE_TRACKER_INGEST', true);
 *   define('OWA_SQS_REGION', 'us-east-1');      // or the setting on this module's screen
 *
 * The queues -- the main one and its dead-letter queue -- are created by the
 * module: on activation, when the region is saved, by
 * cmd=tracker-ingest-provision, and on first use when they are missing.
 * Credentials are never stored: the AWS SDK's default chain, or OWA_SQS_KEY
 * and OWA_SQS_SECRET in owa-config.php.
 *
 * Replaces the RemoteQueue module, which forwarded beacons over HTTP to
 * another install's queue.php.
 */
class Module extends \OWA\Core\Module {

    function __construct() {

        $this->name = 'sqs';
        $this->display_name = 'AWS SQS Tracking Intake';
        $this->group = 'logging';
        $this->author = 'Peter Adams';
        $this->version = '2.0';
        $this->description = 'Queues incoming beacons on AWS SQS, for a central install to drain.';
        $this->config_required = false;
        $this->required_schema_version = 1;

        $this->registerImplementation( 'event_queue_types', 'sqs',
            \OWA\Module\Sqs\Classes\SqsQueue::class, 'Classes/SqsQueue.php' );

        return parent::__construct();
    }

    /** Active, and its queues created if credentials and a region resolve. */
    function activate() {

        $ret = parent::activate();

        if ( \OWA\Module\Sqs\Classes\Sqs::region() ) {

            $this->noteProvisioning( \OWA\Module\Sqs\Classes\Sqs::provision() );
        }

        return $ret;
    }

    function registerActions() {

        $this->registerAction( 'sqs.optionsSqs', 'OWA\\Module\\Sqs\\Controller\\OptionsSqs', '' );
        $this->registerAction( 'sqs.optionsSqsUpdate', 'OWA\\Module\\Sqs\\Controller\\OptionsSqsUpdate', '' );
    }

    function registerAdminPanels() {

        $this->registerSettingsPage( array(
            'do'        => 'sqs.optionsSqs',
            'title'     => 'AWS SQS',
            'group'     => 'Modules',
            'order'     => 20,
            'fieldsets' => array( 'sqs.connection' ),
        ) );

        $this->registerSettingsFieldSet( array(
            'id'       => 'sqs.connection',
            'legend'   => 'Connection',
            'settings' => array( 'region' ),
        ) );
    }

    function _registerEventHandlers() {

        $this->registerEventHandler( 'base.install_settings_saved', $this, 'onSettingsSaved' );
    }

    /** A saved region is where the queues must be: create them there. */
    function onSettingsSaved( $event ) {

        if ( $event->get( 'module' ) === $this->name && in_array( 'region', (array) $event->get( 'keys' ), true ) ) {

            $this->noteProvisioning( \OWA\Module\Sqs\Classes\Sqs::provision() );
        }

        return OWA_EHS_EVENT_HANDLED;
    }

    private function noteProvisioning( array $result ) {

        \OWA\Core\CoreAPI::notice( $result['ok']
            ? sprintf( 'SQS: tracker-ingest queues in place: %s and %s.', $result['main'], $result['dlq'] )
            : 'SQS: the tracker-ingest queues could not be provisioned: ' . $result['error'] );
    }
}

?>
