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
     * A queue's URL built without asking SQS, when the credentials say which
     * account they belong to: the SDK knows it from AWS_ACCOUNT_ID,
     * aws_account_id in a profile, SSO, an ECS task role, and an instance
     * role whose metadata service reports it. A queue name is unique per
     * account and region, not globally, so the URL needs it.
     *
     * The endpoint is the client's, so the partition (.com, .com.cn) is right.
     *
     * @param  string $name
     * @return string|null  null when the account is not known
     */
    public static function urlFromCredentials( $name ) {

        try {

            $client  = self::client();
            $account = $client->getCredentials()->wait()->getAccountId();

        } catch ( \Throwable $t ) {

            return null;
        }

        return $account ? rtrim( (string) $client->getEndpoint(), '/' ) . '/' . $account . '/' . $name : null;
    }

    /** OWA_SQS_REGION, else the setting, else null for the SDK's own chain (AWS_REGION, ~/.aws). */
    public static function region() {

        if ( defined( 'OWA_SQS_REGION' ) && OWA_SQS_REGION ) {

            return (string) OWA_SQS_REGION;
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
