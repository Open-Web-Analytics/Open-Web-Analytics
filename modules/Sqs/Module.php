<?php
namespace OWA\Module\Sqs;

/**
 * The tracking intake on AWS SQS.
 *
 * Registers the sqs queue type. OWA_SQS_QUEUE_URL activates the module and
 * makes sqs the tracking intake's backend, on any server (Core\ModuleActivation);
 * the region is read from the URL. A collector, which has no database, is
 * configured entirely in owa-config.php:
 *
 *   define('OWA_SERVER_ROLE', 'collector');
 *   define('OWA_SQS_QUEUE_URL', 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-tracker-ingest');
 *
 * Activated from the Modules screen without the URL, the module provisions
 * queues with a name derived from the database settings, and the intake stays
 * on the file queue until OWA_SQS_QUEUE_URL names one.
 *
 * The queues -- the main one and its dead-letter queue -- are created by the
 * install that drains them: on activation, when the region is saved, by
 * cmd=tracker-ingest-provision, and on first use when they are missing. Never
 * by log.php: a collector that finds no queue keeps the beacon in its file
 * queue (async_log_dir), and `php cli.php cmd=drain-tracker-ingest
 * forward_only=1`, run from its cron, forwards those once the queue answers.
 * Credentials are never stored: the AWS SDK's default chain, or OWA_SQS_KEY,
 * OWA_SQS_SECRET and, for temporary credentials, OWA_SQS_TOKEN in
 * owa-config.php.
 *
 * Replaces the RemoteQueue module, which forwarded beacons over HTTP to
 * another install's queue.php.
 */
class Module extends \OWA\Core\Module {

    const NAME = 'sqs';

    /*
     * Declared, not set in the constructor, so cmd=version-info reads it
     * without constructing the module (Core\VersionInfo).
     */
    public $required_schema_version = 1;

    public function __construct() {

        $this->display_name = 'AWS SQS Tracking Intake';
        $this->group = 'logging';
        $this->author = 'Peter Adams';
        $this->version = '2.0';
        $this->description = 'Queues incoming beacons on AWS SQS, for a central install to drain.';
        $this->config_required = false;

        $this->registerImplementation( 'event_queue_types', 'sqs',
            \OWA\Module\Sqs\Classes\SqsQueue::class );

        parent::__construct();
    }

    /** Active, and its queues created if credentials and a region resolve. */
    public function activate() {

        $ret = parent::activate();

        if ( \OWA\Module\Sqs\Classes\Sqs::region() ) {

            $this->noteProvisioning( \OWA\Module\Sqs\Classes\Sqs::provision() );
        }

        return $ret;
    }

    public function registerActions() {

        $this->registerAction( 'sqs.optionsSqs', 'OWA\\Module\\Sqs\\Controller\\OptionsSqs' );
        $this->registerAction( 'sqs.optionsSqsUpdate', 'OWA\\Module\\Sqs\\Controller\\OptionsSqsUpdate' );
    }

    /**
     * In the Instance section, and only while the module is active: the nav
     * drops a section it does not build.
     */
    public function registerAdminPanels() {

        $this->registerSettingsPage( [
            'do'        => 'sqs.optionsSqs',
            'title'     => \OWA\Core\CoreAPI::t( 'AWS SQS' ),
            'section'   => 'Instance',
            'order'     => 20,
            'fieldsets' => [ 'sqs.connection' ],
        ] );

        $this->registerSettingsFieldSet( [
            'id'       => 'sqs.connection',
            'legend'   => \OWA\Core\CoreAPI::t( 'Connection' ),
            'settings' => [ 'region' ],
        ] );
    }

    public function _registerEventHandlers() {

        $this->registerEventHandler( 'base.install_settings_saved', $this, 'onSettingsSaved' );
    }

    /**
     * A saved region is where the queues must be: create them there.
     *
     * @param  \OWA\Module\Base\Classes\Event $event the settings save: its module and keys
     * @return int an OWA_EHS_ handler status
     */
    public function onSettingsSaved( $event ) {

        if ( $event->get( 'module' ) === $this->name && in_array( 'region', (array) $event->get( 'keys' ), true ) ) {

            $this->noteProvisioning( \OWA\Module\Sqs\Classes\Sqs::provision() );
        }

        return OWA_EHS_EVENT_HANDLED;
    }

    /**
     * @param  array{ok: bool, error: string|null, main: string|null, dlq: string|null, at: int} $result what Sqs::provision() answers
     * @return void
     */
    private function noteProvisioning( array $result ) {

        \OWA\Core\CoreAPI::notice( $result['ok']
            ? sprintf( 'SQS: tracker-ingest queues in place: %s and %s.', $result['main'], $result['dlq'] )
            : 'SQS: the tracker-ingest queues could not be provisioned: ' . $result['error'] );
    }
}
