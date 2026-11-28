<?php
namespace OWA\Module\Sqs\Classes;

/**
 * What the SQS intake needs to know about this install: its
 * region, its credentials, and its queues' names.
 *
 * owa-config.php constants, all optional:
 *
 *   OWA_SQS_REGION      the region; else the one in OWA_SQS_QUEUE_URL, else the
 *                       stored region, else AWS_REGION or AWS_DEFAULT_REGION
 *                       in the environment
 *   OWA_SQS_KEY,
 *   OWA_SQS_SECRET      credentials; else the AWS SDK's default chain
 *   OWA_SQS_TOKEN       the session token that goes with temporary (STS)
 *                       credentials in OWA_SQS_KEY and OWA_SQS_SECRET
 *   OWA_SQS_QUEUE_URL   the main queue's URL; its dead-letter queue is the
 *                       same URL with -dlq. Else the name is derived (queueName()).
 *                       Setting it activates the module and makes sqs the
 *                       tracking intake's backend (Core\ModuleActivation).
 */
final class Sqs {

    /** The API version the client is pinned to. */
    const API_VERSION = '2012-11-05';

    /** The names' prefix; the rest is derived. */
    const PREFIX = 'owa-tracker-ingest-';

    /** @var callable|null a handler for the SDK client. TESTS ONLY: Aws\MockHandler. */
    public static $handler = null;

    /**
     * HTTP limits on log.php's path (request type tracking), where a beacon
     * that cannot be queued in time goes to the file intake instead
     * (TrackerIngest::send()): a second to connect, three for the answer, and
     * one retry.
     */
    const LOGGER_HTTP    = [ 'connect_timeout' => 1, 'timeout' => 3 ];
    const LOGGER_RETRIES = 1;

    /** Everywhere else -- the drain, replay, provisioning -- nothing is waiting on the answer. */
    const CLI_HTTP    = [ 'connect_timeout' => 5, 'timeout' => 30 ];
    const CLI_RETRIES = 3;

    /**
     * The main queue's name: the last part of OWA_SQS_QUEUE_URL when that is
     * set, else derived.
     *
     * Derived from what identifies where the beacons land -- the database
     * host and its name -- and CoreAPI::NS, once the ns setting, which keeps
     * the names queues were created under. Another server computes the same
     * name from its own owa-config.php, and two installs on one server with
     * different databases do not share a queue. Not
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

        $id = implode( '|', [
            (string) \OWA\Core\CoreAPI::getSetting( 'base', 'db_host' ),
            (string) \OWA\Core\CoreAPI::getSetting( 'base', 'db_name' ),
            \OWA\Core\CoreAPI::NS,
        ] );

        return self::PREFIX . substr( hash( 'sha256', $id ), 0, 12 );
    }

    /**
     * The dead-letter queue's name.
     *
     * @param  string $name
     * @return string
     */
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

        return \OWA\Core\RequestType::is( \OWA\Core\RequestType::TRACKING );
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
     * OWA_SQS_REGION, else the region in OWA_SQS_QUEUE_URL, else the stored
     * region, else AWS_REGION or AWS_DEFAULT_REGION in the environment, else
     * null. The region in an AWS profile (~/.aws/config) is not read.
     *
     * @return string|null
     */
    public static function region() {

        // OWA_SQS_REGION is the setting's config_constant: defined, it is the setting.
        $region   = (string) \OWA\Core\CoreAPI::getSetting( 'sqs', 'region' );
        $governed = \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( 'sqs', 'region' ) !== '';

        if ( $governed && $region !== '' ) {

            return $region;
        }

        $from_url = self::regionFromUrl( (string) self::configuredUrl() );

        if ( $from_url !== null ) {

            return $from_url;
        }

        if ( $region !== '' ) {

            return $region;
        }

        return getenv( 'AWS_REGION' ) ?: ( getenv( 'AWS_DEFAULT_REGION' ) ?: null );
    }

    /**
     * The region in a queue URL's host: sqs.<region>.amazonaws.com, and the
     * FIPS, VPC endpoint, China and legacy <region>.queue forms.
     *
     * @param string $url
     * @return string|null
     */
    public static function regionFromUrl( $url ) {

        $host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );

        if ( ! preg_match( '/\.amazonaws\.com(\.cn)?$/D', $host ) ) {

            return null;
        }

        foreach ( explode( '.', $host ) as $label ) {

            if ( preg_match( '/^[a-z]{2}(-[a-z]+)+-\d+$/D', $label ) ) {

                return $label;
            }
        }

        return null;
    }

    /**
     * Where the credentials come from, for the settings screen. Never the secret.
     *
     * @return string
     */
    public static function credentialSource() {

        $credentials = self::configCredentials();

        if ( ! $credentials ) {

            return 'the AWS default chain: environment, ~/.aws, then the instance or task role';
        }

        return isset( $credentials['token'] )
            ? 'OWA_SQS_KEY, OWA_SQS_SECRET and OWA_SQS_TOKEN in owa-config.php'
            : 'OWA_SQS_KEY and OWA_SQS_SECRET in owa-config.php';
    }

    /** @return array{key: string, secret: string, token?: string}|null key, secret and any session token from owa-config.php */
    private static function configCredentials() {

        if ( defined( 'OWA_SQS_KEY' ) && defined( 'OWA_SQS_SECRET' ) && OWA_SQS_KEY && OWA_SQS_SECRET ) {

            $credentials = [ 'key' => (string) OWA_SQS_KEY, 'secret' => (string) OWA_SQS_SECRET ];

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
     * @return array<string, mixed> the SqsClient constructor's arguments
     */
    public static function clientArgs( $region ) {

        $logger = self::isLogger();
        $args   = [
            'region'  => (string) $region,
            'version' => self::API_VERSION,
            'http'    => $logger ? self::LOGGER_HTTP : self::CLI_HTTP,
            'retries' => $logger ? self::LOGGER_RETRIES : self::CLI_RETRIES,
        ];

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

        static $clients = [];

        $region = self::region();

        if ( ! $region ) {

            throw new \Exception( 'No AWS region: set it on the SQS settings screen, or OWA_SQS_REGION in owa-config.php, or give OWA_SQS_QUEUE_URL its region\'s host.' );
        }

        $args = self::clientArgs( $region );

        // A test's mock: a fresh client each time, with dummy credentials so none are looked up.
        if ( self::$handler ) {

            $args['handler']       = self::$handler;
            $args['credentials'] ??= [ 'key' => 'test', 'secret' => 'test' ];

            return new \Aws\Sqs\SqsClient( $args );
        }

        return $clients[ $region . '|' . ( self::isLogger() ? 'logger' : 'other' ) ] ??= new \Aws\Sqs\SqsClient( $args );
    }

    /**
     * Create both queues, or find them, and record the outcome for the
     * settings screen.
     *
     * @return array{ok: bool, error: string|null, main: string|null, dlq: string|null, at: int}
     */
    public static function provision() {

        $result = [ 'ok' => false, 'error' => null, 'main' => null, 'dlq' => null, 'at' => time() ];

        try {

            $queue = new SqsQueue( [ 'max_receives' => \OWA\Module\Base\Classes\TrackerIngest::MAX_RECEIVES ] );

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

            // A server without a database (a collector): the outcome is still returned.
        }

        return $result;
    }
}
