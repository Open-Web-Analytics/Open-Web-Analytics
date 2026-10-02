<?php
namespace OWA\Module\Sqs\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What the SQS intake needs to know about this install (PLAN 2.30.4a): its
 * region, its credentials, and its queues' names.
 */
final class Sqs {

    /** The API version the client is pinned to. */
    const API_VERSION = '2012-11-05';

    /** The names' prefix; the rest is derived. */
    const PREFIX = 'owa-tracker-ingest-';

    /** @var callable|null a handler for the SDK client. TESTS ONLY: Aws\MockHandler. */
    public static $handler = null;

    /**
     * The main queue's name: derived, not a setting.
     *
     * From what identifies where the beacons land -- the database host, its
     * name and the table prefix -- so a logging node computes the same name
     * from its own owa-config.php and two installs on one server do not
     * share a queue. Not generateInstanceSpecificHash(): that is made from
     * the credentials, which installs on one server often share.
     *
     * @return string
     */
    public static function queueName() {

        $id = implode( '|', array(
            (string) \OWA\Core\CoreAPI::getSetting( 'base', 'db_host' ),
            (string) \OWA\Core\CoreAPI::getSetting( 'base', 'db_name' ),
            (string) \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ),
        ) );

        return self::PREFIX . substr( hash( 'sha256', $id ), 0, 12 );
    }

    /** The dead-letter queue's name. */
    public static function deadLetterName( $name ) {

        return $name . '-dlq';
    }

    /**
     * OWA_SQS_QUEUE_URL: the main queue, for a logging node that cannot read
     * what provisioning recorded. Its dead-letter queue is the same URL with
     * -dlq, and its region is in its host.
     *
     * @return string|null
     */
    public static function configuredUrl() {

        return defined( 'OWA_SQS_QUEUE_URL' ) && OWA_SQS_QUEUE_URL ? (string) OWA_SQS_QUEUE_URL : null;
    }

    /** The main queue's name as OWA_SQS_QUEUE_URL gives it, or null. */
    public static function configuredName() {

        $url = self::configuredUrl();

        return $url ? ( basename( (string) parse_url( $url, PHP_URL_PATH ) ) ?: null ) : null;
    }

    /**
     * OWA_SQS_REGION, else the one in OWA_SQS_QUEUE_URL's host, else the
     * setting, else null for the SDK's own chain (AWS_REGION, ~/.aws).
     */
    public static function region() {

        if ( defined( 'OWA_SQS_REGION' ) && OWA_SQS_REGION ) {

            return (string) OWA_SQS_REGION;
        }

        if ( self::configuredUrl()
             && preg_match( '#^https://sqs\.([a-z0-9-]+)\.amazonaws\.com(\.cn)?/#', self::configuredUrl(), $m ) ) {

            return $m[1];
        }

        $region = \OWA\Core\CoreAPI::getSetting( 'sqs', 'region' );

        if ( $region ) {

            return (string) $region;
        }

        return getenv( 'AWS_REGION' ) ?: ( getenv( 'AWS_DEFAULT_REGION' ) ?: null );
    }

    /** Where the credentials come from, for the settings screen. Never the secret. */
    public static function credentialSource() {

        return self::configCredentials()
            ? 'OWA_SQS_KEY and OWA_SQS_SECRET in owa-config.php'
            : 'the AWS default chain: environment, ~/.aws, then the instance or task role';
    }

    /** @return array|null key and secret from owa-config.php */
    private static function configCredentials() {

        if ( defined( 'OWA_SQS_KEY' ) && defined( 'OWA_SQS_SECRET' ) && OWA_SQS_KEY && OWA_SQS_SECRET ) {

            return array( 'key' => (string) OWA_SQS_KEY, 'secret' => (string) OWA_SQS_SECRET );
        }

        return null;
    }

    /**
     * The SDK client, one per process.
     *
     * @return \Aws\Sqs\SqsClient
     * @throws \Exception when no region is configured
     */
    public static function client() {

        static $clients = array();

        $region = self::region();

        if ( ! $region ) {

            throw new \Exception( 'No AWS region: set it on the SQS settings screen, or OWA_SQS_REGION in owa-config.php.' );
        }

        $args        = array( 'region' => $region, 'version' => self::API_VERSION );
        $credentials = self::configCredentials();

        if ( $credentials ) {

            $args['credentials'] = $credentials;
        }

        // A test's mock: a fresh client each time, with dummy credentials so none are looked up.
        if ( self::$handler ) {

            $args['handler']     = self::$handler;
            $args['credentials'] = $credentials ?: array( 'key' => 'test', 'secret' => 'test' );

            return new \Aws\Sqs\SqsClient( $args );
        }

        return $clients[ $region ] ??= new \Aws\Sqs\SqsClient( $args );
    }

    /**
     * Create both queues, or find them, and record the outcome for the
     * settings screen (PLAN 2.30.4a).
     *
     * @return array ok, error, main (url), dlq (url), at
     */
    public static function provision() {

        $result = array( 'ok' => false, 'error' => null, 'main' => null, 'dlq' => null, 'at' => time() );

        try {

            $queue = new SqsQueue( array( 'max_receives' => \OWA\Module\Base\Classes\TrackerIngest::MAX_RECEIVES ) );

            $result['ok']    = $queue->provision();
            $result['error'] = $queue->lastError();
            $result['main']  = $queue->url( false );
            $result['dlq']   = $queue->deadLetterQueue()->url( false );

        } catch ( \Throwable $t ) {

            $result['error'] = $t->getMessage();
        }

        try {

            \OWA\Core\CoreAPI::persistSetting( 'sqs', 'provisioned', $result );
            \OWA\Core\CoreAPI::configSingleton()->save();

        } catch ( \Throwable $t ) {

            // A logging node with static config only: the outcome is still returned.
        }

        return $result;
    }
}

?>
