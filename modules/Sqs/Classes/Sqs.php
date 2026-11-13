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
 *
 * owa-config.php constants, all optional:
 *
 *   OWA_SQS_REGION      the region; else the stored region, else AWS_REGION or
 *                       AWS_DEFAULT_REGION in the environment
 *   OWA_SQS_KEY,
 *   OWA_SQS_SECRET      credentials; else the AWS SDK's default chain
 *   OWA_SQS_TOKEN       the session token that goes with temporary (STS)
 *                       credentials in OWA_SQS_KEY and OWA_SQS_SECRET
 *   OWA_SQS_QUEUE_URL   the main queue's URL; its dead-letter queue is the
 *                       same URL with -dlq. Else the name is derived (queueName()).
 */
final class Sqs {

    /** The API version the client is pinned to. */
    const API_VERSION = '2012-11-05';

    /** The names' prefix; the rest is derived. */
    const PREFIX = 'owa-tracker-ingest-';

    /** @var callable|null a handler for the SDK client. TESTS ONLY: Aws\MockHandler. */
    public static $handler = null;

    /**
     * HTTP limits on log.php's path (instance_role logger), where a beacon
     * that cannot be queued in time goes to the file intake instead
     * (TrackerIngest::send()): a second to connect, three for the answer, and
     * one retry.
     */
    const LOGGER_HTTP    = array( 'connect_timeout' => 1, 'timeout' => 3 );
    const LOGGER_RETRIES = 1;

    /** Everywhere else -- the drain, replay, provisioning -- nothing is waiting on the answer. */
    const CLI_HTTP    = array( 'connect_timeout' => 5, 'timeout' => 30 );
    const CLI_RETRIES = 3;

    /**
     * The main queue's name: the last part of OWA_SQS_QUEUE_URL when that is
     * set, else derived.
     *
     * Derived from what identifies where the beacons land -- the database
     * host and its name -- and ns, the prefix on OWA's cookie and URL
     * parameter names, so a logging node computes the same name from its own
     * owa-config.php and two installs on one server do not share a queue. Not
     * generateInstanceSpecificHash(): that is made from the credentials,
     * which installs on one server often share.
     *
     * @return string
     */
    public static function queueName() {

        $url = self::configuredUrl();

        if ( $url !== null ) {

            return basename( (string) parse_url( $url, PHP_URL_PATH ) );
        }

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

    /** @return string|null OWA_SQS_QUEUE_URL, when set */
    public static function configuredUrl() {

        return defined( 'OWA_SQS_QUEUE_URL' ) && OWA_SQS_QUEUE_URL ? rtrim( (string) OWA_SQS_QUEUE_URL, '/' ) : null;
    }

    /**
     * The URL OWA_SQS_QUEUE_URL gives a queue: itself for the main queue, with
     * -dlq for its dead-letter queue, null for any other name.
     *
     * @param  string $name
     * @return string|null
     */
    public static function configuredUrlFor( $name ) {

        $url = self::configuredUrl();

        if ( $url === null ) {

            return null;
        }

        $main = basename( (string) parse_url( $url, PHP_URL_PATH ) );

        if ( $name === $main ) {

            return $url;
        }

        return $name === self::deadLetterName( $main ) ? self::deadLetterName( $url ) : null;
    }

    /**
     * Whether this request is log.php's: a queue missing there is reported,
     * not created, and SQS gets the short HTTP limits.
     *
     * @return bool
     */
    public static function isLogger() {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'instance_role' ) === 'logger';
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

    /**
     * OWA_SQS_REGION, else the stored region, else AWS_REGION or
     * AWS_DEFAULT_REGION in the environment, else null. The region in an AWS
     * profile (~/.aws/config) is not read.
     */
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

        $credentials = self::configCredentials();

        if ( ! $credentials ) {

            return 'the AWS default chain: environment, ~/.aws, then the instance or task role';
        }

        return isset( $credentials['token'] )
            ? 'OWA_SQS_KEY, OWA_SQS_SECRET and OWA_SQS_TOKEN in owa-config.php'
            : 'OWA_SQS_KEY and OWA_SQS_SECRET in owa-config.php';
    }

    /** @return array|null key, secret and any session token from owa-config.php */
    private static function configCredentials() {

        if ( defined( 'OWA_SQS_KEY' ) && defined( 'OWA_SQS_SECRET' ) && OWA_SQS_KEY && OWA_SQS_SECRET ) {

            $credentials = array( 'key' => (string) OWA_SQS_KEY, 'secret' => (string) OWA_SQS_SECRET );

            if ( defined( 'OWA_SQS_TOKEN' ) && OWA_SQS_TOKEN ) {

                $credentials['token'] = (string) OWA_SQS_TOKEN;
            }

            return $credentials;
        }

        return null;
    }

    /**
     * The SDK client's arguments for a region: the credentials from
     * owa-config.php when set, and the HTTP limits for this request's role.
     *
     * @param  string $region
     * @return array
     */
    public static function clientArgs( $region ) {

        $logger = self::isLogger();
        $args   = array(
            'region'  => (string) $region,
            'version' => self::API_VERSION,
            'http'    => $logger ? self::LOGGER_HTTP : self::CLI_HTTP,
            'retries' => $logger ? self::LOGGER_RETRIES : self::CLI_RETRIES,
        );

        $credentials = self::configCredentials();

        if ( $credentials ) {

            $args['credentials'] = $credentials;
        }

        return $args;
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

        $args = self::clientArgs( $region );

        // A test's mock: a fresh client each time, with dummy credentials so none are looked up.
        if ( self::$handler ) {

            $args['handler']       = self::$handler;
            $args['credentials'] ??= array( 'key' => 'test', 'secret' => 'test' );

            return new \Aws\Sqs\SqsClient( $args );
        }

        return $clients[ $region . '|' . ( self::isLogger() ? 'logger' : 'other' ) ] ??= new \Aws\Sqs\SqsClient( $args );
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
