<?php
namespace OWA\Module\Base\Controller;

/**
 * What this server is configured to do, and what stops it: `php cli.php
 * cmd=server-check`.
 *
 * Prints the role (Core\ServerRole), the request types it serves, what
 * owa-config.php is missing for the role and retired constants still set.
 * Then, when nothing is missing, each check the role uses (checks()):
 *
 *   Database      full, api   connects and runs SELECT 1
 *   Scheduler     full        cron is running schedule-run (SchedulerHealth)
 *   Queue         full        with SQS: one GetQueueAttributes call, for the
 *                             approximate depth and the redrive policy
 *   Queue send    collector   sends one test message (TrackerIngest::CHECK_TYPE),
 *                             which the drain deletes
 *   Tracking URL  collector   with OWA_TRACKING_URL: GET its log.php, no event
 *   REST API      full, api   GET a route with no credentials, then with an
 *                             invalid Bearer token: two different 401s
 *
 * No check receives from a queue, and none writes to the database.
 *
 * Fails (exit 1) when something is missing or a check fails.
 */
class ServerCheckCli extends \OWA\Core\Controller\Cli {

    /** The REST route the REST API check requests. It needs a signed-in user. */
    const REST_ROUTE = 'base/v1/sites';

    /** A check that fails exits 1, for a deploy script or a monitor. */
    protected $exit_on_failure = true;

    /**
     * @var callable|null fn( $url, $headers ) => [ status, content type, body ]. TESTS ONLY.
     */
    public static $fetch = null;

    /** See \OWA\Core\Controller\Cli::serverRoles(). A diagnostic: every server can check itself. */
    public static function serverRoles() {

        return \OWA\Core\ServerRole::ALL;
    }

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Prints this server\'s role, what it serves and anything owa-config.php is missing for the role, then runs the checks the role uses: the database, the scheduler, the tracking queue, the tracking URL and the REST API. On a collector it sends one test message to the queue, which the drain deletes. Exits 1 when something is missing or a check fails.',
            'arguments'   => [],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function action() {

        list( $lines, $failed ) = $this->report(
            \OWA\Core\ServerRole::current(),
            \OWA\Core\ServerRole::definedConstants(),
            \OWA\Core\ModuleActivation::queueBackends() );

        $this->write( $lines );

        if ( $failed ) {

            return $this->fail( \OWA\Core\CoreAPI::t( 'Server check failed: see above.' ) );
        }
    }

    /**
     * The report: the role, the configuration, then each check's line.
     *
     * @param \OWA\Core\ServerRole $role
     * @param array<string, mixed>  $defined  the OWA_ constants
     * @param array<string, string> $backends the queue backends configured, type => constant
     * @return array{0: list<string>, 1: bool} the lines, and whether anything failed
     */
    public function report( \OWA\Core\ServerRole $role, array $defined, array $backends ) {

        $problems = $role->configProblems( $defined, $backends, \OWA\Core\ModuleActivation::queueBackendConstants() );

        $lines = [
            self::line( 'Server role', $role->name() ),
            self::line( 'Serves', implode( ', ', $role->requestTypes() ) ),
            self::line( 'Configuration', $problems ? \OWA\Core\CoreAPI::t( 'incomplete' ) : \OWA\Core\CoreAPI::t( 'ok' ) ),
        ];

        foreach ( $problems as $problem ) {

            $lines[] = '  ' . $problem;
        }

        foreach ( \OWA\Core\ServerRole::upgradeNotices( $defined ) as $constant => $notice ) {

            $lines[] = sprintf( 'Retired constant: %s. %s', $constant, $notice );
        }

        if ( $problems ) {

            return [ $lines, true ];
        }

        $failed = false;

        foreach ( $this->checks( $role, $defined, $backends ) as $label => $check ) {

            $result  = $check();
            $lines[] = self::line( $label, $result['message'] );
            $failed  = $failed || ! $result['ok'];
        }

        return [ $lines, $failed ];
    }

    /**
     * The checks this role uses, label => fn() => {ok, message}, in the order
     * they run. A combined role ('collector,api') runs each of its roles'.
     *
     * @param \OWA\Core\ServerRole $role
     * @param array<string, mixed>  $defined
     * @param array<string, string> $backends
     * @return array<string, callable(): array{ok: bool, message: string}>
     */
    protected function checks( \OWA\Core\ServerRole $role, array $defined, array $backends ) {

        $full      = $role->is( \OWA\Core\ServerRole::FULL );
        $collector = $role->is( \OWA\Core\ServerRole::COLLECTOR );
        $api       = $role->is( \OWA\Core\ServerRole::API );
        $checks    = [];

        if ( $role->hasDatabase() ) {

            $checks['Database'] = fn () => $this->databaseCheck();
        }

        if ( $full ) {

            $checks['Scheduler'] = fn () => $this->schedulerCheck();
        }

        if ( $full || $collector ) {

            $checks['Queue backend'] = fn () => [ 'ok' => true, 'message' => $backends
                ? implode( ', ', array_map( fn ( $type, $constant ) => $type . ' (' . $constant . ')', array_keys( $backends ), $backends ) )
                : 'file' ];
        }

        if ( $full && $backends ) {

            $checks['Queue'] = fn () => $this->queueCheck( $this->queue() );
        }

        if ( $collector ) {

            $checks['Queue send'] = fn () => $this->sendCheck( $this->queue() );

            if ( (string) ( $defined['OWA_TRACKING_URL'] ?? '' ) !== '' ) {

                $checks['Tracking URL'] = fn () => $this->trackingUrlCheck(
                    \OWA\Module\Base\Classes\TrackerBundle::trackingUrl() . 'log.php' );
            }
        }

        if ( $full || $api ) {

            $checks['REST API'] = fn () => $this->restCheck( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'rest_api_url' ) );
        }

        return $checks;
    }

    /**
     * @param string $label
     * @param string $value
     * @return string the label and value, the values aligned
     */
    private static function line( $label, $value ) {

        return str_pad( $label . ':', 18 ) . $value;
    }

    /** @return \OWA\Core\IntakeQueue the tracking intake */
    protected function queue() {

        return \OWA\Module\Base\Classes\TrackerIngest::queue();
    }

    /**
     * Connect and run SELECT 1.
     *
     * @return array{ok: bool, message: string}
     */
    protected function databaseCheck() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->canConnect() ) {

            return [ 'ok' => false, 'message' => \OWA\Core\CoreAPI::t( 'cannot connect with the OWA_DB_ constants in owa-config.php.' ) ];
        }

        $row = $db->get_row( 'SELECT 1 AS one' );

        if ( (int) ( is_array( $row ) ? ( $row['one'] ?? 0 ) : 0 ) !== 1 ) {

            return [ 'ok' => false, 'message' => \OWA\Core\CoreAPI::t( 'connected, but SELECT 1 failed.' ) ];
        }

        $version = (string) $db->serverVersion();

        return [ 'ok' => true, 'message' => $version !== ''
            ? sprintf( \OWA\Core\CoreAPI::t( 'ok: connected to %s.' ), $version )
            : \OWA\Core\CoreAPI::t( 'ok: connected.' ) ];
    }

    /**
     * Whether cron is running schedule-run (SchedulerHealth::problem()).
     *
     * @return array{ok: bool, message: string}
     */
    protected function schedulerCheck() {

        $problem = \OWA\Module\Base\Classes\SchedulerHealth::problem();

        if ( $problem ) {

            return [ 'ok' => false, 'message' => sprintf( '%s %s %s', $problem['headline'], $problem['message'],
                \OWA\Module\Base\Classes\SchedulerHealth::cronLine() ) ];
        }

        return [ 'ok' => true, 'message' => \OWA\Core\CoreAPI::t( 'ok: cron is running schedule-run.' ) ];
    }

    /**
     * The queue's approximate depth and dead-letter setup, from one
     * GetQueueAttributes call (SqsQueue::describe()). A backend without
     * describe() is not asked.
     *
     * @param \OWA\Core\IntakeQueue $queue
     * @return array{ok: bool, message: string}
     */
    public function queueCheck( \OWA\Core\IntakeQueue $queue ) {

        if ( ! method_exists( $queue, 'describe' ) ) {

            return [ 'ok' => true, 'message' => \OWA\Core\CoreAPI::t( 'not checked: this backend reports no attributes.' ) ];
        }

        $d = $queue->describe();

        if ( $d['messages'] === null ) {

            return [ 'ok' => false, 'message' => sprintf( \OWA\Core\CoreAPI::t( 'reading the queue\'s attributes failed: %s' ), $d['error'] ) ];
        }

        $waiting = sprintf( \OWA\Core\CoreAPI::tn( 'about %d message waiting', 'about %d messages waiting', $d['messages'] ), $d['messages'] );

        if ( $d['dead_letter'] === null ) {

            return [ 'ok' => false, 'message' => sprintf(
                \OWA\Core\CoreAPI::t( '%s, and no dead-letter queue: the queue has no redrive policy. cmd=tracker-ingest-provision sets one.' ), $waiting ) ];
        }

        return [ 'ok' => true, 'message' => sprintf( \OWA\Core\CoreAPI::t( 'ok: %1$s; a message goes to %2$s after %3$d receives.' ),
            $waiting, $d['dead_letter'], (int) $d['max_receives'] ) ];
    }

    /**
     * Send one test message. The drain deletes it (TrackerIngest::ingestMessage()).
     *
     * @param \OWA\Core\IntakeQueue $queue
     * @return array{ok: bool, message: string}
     */
    public function sendCheck( \OWA\Core\IntakeQueue $queue ) {

        $envelope = [
            // Not ENVELOPE_VERSION: no version of the drain ingests it.
            'v'          => 0,
            'type'       => \OWA\Module\Base\Classes\TrackerIngest::CHECK_TYPE,
            'properties' => [ 'check' => bin2hex( random_bytes( 8 ) ) ],
            'queued_at'  => time(),
        ];

        if ( ! $queue->send( $envelope ) ) {

            return [ 'ok' => false, 'message' => sprintf( \OWA\Core\CoreAPI::t( 'failed to send a test message: %s' ),
                \OWA\Module\Base\Classes\TrackerIngest::queueError( $queue ) ?: \OWA\Core\CoreAPI::t( 'refused' ) ) ];
        }

        return [ 'ok' => true, 'message' => \OWA\Core\CoreAPI::t( 'ok: sent a test message, which the drain deletes.' ) ];
    }

    /**
     * Request a REST route twice: with no credentials it answers 401 with
     * Controller::notAuthenticatedMessage(); with an invalid Bearer token, 401
     * with Controller::refusedAppPasswordMessage(). The second shows the web
     * server passes the Authorization header to PHP. Neither writes anything:
     * a token not shaped like one is never looked up (Auth::authByAppPassword()).
     *
     * @param string $api_url rest_api_url
     * @return array{ok: bool, message: string}
     */
    public function restCheck( $api_url ) {

        if ( $api_url === '' ) {

            return [ 'ok' => false, 'message' => \OWA\Core\CoreAPI::t( 'rest_api_url is not set.' ) ];
        }

        $url = $api_url . ( strpos( $api_url, '?' ) === false ? '?' : '&' ) . 'owa_rest_params=' . self::REST_ROUTE;

        list( $status, , $body ) = $this->request( $url, [] ) + [ 0, '', '' ];
        $msg = self::restErrorMessage( (string) $body );

        if ( (int) $status !== 401 || $msg !== \OWA\Core\Controller::notAuthenticatedMessage() ) {

            return [ 'ok' => false, 'message' => sprintf( \OWA\Core\CoreAPI::t( '%1$s answered %2$s without credentials, not the REST API\'s 401.' ),
                $url, $status ? 'HTTP ' . (int) $status : \OWA\Core\CoreAPI::t( 'nothing' ) ) ];
        }

        list( $status, , $body ) = $this->request( $url, [ 'Authorization: Bearer x' ] ) + [ 0, '', '' ];
        $msg = self::restErrorMessage( (string) $body );

        if ( (int) $status === 401 && $msg === \OWA\Core\Controller::notAuthenticatedMessage() ) {

            return [ 'ok' => false, 'message' => sprintf( \OWA\Core\CoreAPI::t( '%s answered a request with an Authorization header as one without credentials: the web server does not pass the header to PHP, so application passwords cannot work.' ), $url ) ];
        }

        if ( (int) $status !== 401 || $msg !== \OWA\Core\Controller::refusedAppPasswordMessage() ) {

            return [ 'ok' => false, 'message' => sprintf( \OWA\Core\CoreAPI::t( '%1$s answered %2$s to an invalid application password, not the REST API\'s 401.' ),
                $url, $status ? 'HTTP ' . (int) $status : \OWA\Core\CoreAPI::t( 'nothing' ) ) ];
        }

        return [ 'ok' => true, 'message' => sprintf( \OWA\Core\CoreAPI::t( 'ok: %s refuses a request without credentials, and one with an invalid application password as such.' ), $url ) ];
    }

    /**
     * The msg of the first error in a REST response envelope.
     *
     * @param string $body
     * @return string|null null when the body is not an envelope with one
     */
    private static function restErrorMessage( $body ) {

        $json = json_decode( $body, true );

        foreach ( (array) ( is_array( $json ) ? ( $json['error'] ?? [] ) : [] ) as $error ) {

            if ( is_array( $error ) && isset( $error['msg'] ) && is_string( $error['msg'] ) ) {

                return $error['msg'];
            }
        }

        return null;
    }

    /**
     * @param string   $url
     * @param string[] $headers
     * @return array{0: int, 1: string, 2: string} status, content type, body
     */
    private function request( $url, array $headers ) {

        return self::$fetch ? (array) call_user_func( self::$fetch, $url, $headers ) : self::fetch( $url, $headers );
    }

    /**
     * Request log.php at the tracking URL, with no event: it answers with its
     * 1x1 GIF and logs nothing.
     *
     * @param string $url
     * @return array{ok: bool, message: string}
     */
    public function trackingUrlCheck( $url ) {

        list( $status, $type ) = $this->request( $url, [] ) + [ 0, '' ];

        if ( (int) $status === 200 && stripos( (string) $type, 'image/gif' ) === 0 ) {

            return [ 'ok' => true, 'message' => sprintf( 'ok: %s answers.', $url ) ];
        }

        return [ 'ok' => false, 'message' => sprintf( '%s answered %s%s, not log.php\'s image.', $url,
            $status ? 'HTTP ' . (int) $status : 'nothing', $type ? ' (' . $type . ')' : '' ) ];
    }

    /**
     * @param  string   $url
     * @param  string[] $headers
     * @return array{0: int, 1: string, 2: string} the status, content type and body of a GET; 0, '' and '' when it failed
     */
    private static function fetch( $url, array $headers = [] ) {

        $context = stream_context_create( [ 'http' => [
            'method'        => 'GET',
            'timeout'       => 10,
            'ignore_errors' => true,
            'header'        => implode( "\r\n", array_merge( [ 'User-Agent: Open Web Analytics server-check' ], $headers ) ) . "\r\n",
        ] ] );

        $stream = @fopen( $url, 'rb', false, $context );

        if ( ! $stream ) {

            return [ 0, '', '' ];
        }

        // Every response's headers, a followed redirect's included.
        $meta    = stream_get_meta_data( $stream );
        $headers = is_array( $meta['wrapper_data'] ?? null ) ? $meta['wrapper_data'] : [];
        $body    = (string) stream_get_contents( $stream, 65536 );

        fclose( $stream );

        $status = 0;
        $type   = '';

        foreach ( $headers as $header ) {

            if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {

                // After a redirect, the last status is the answer.
                $status = (int) $m[1];
                $type   = '';

            } elseif ( stripos( $header, 'Content-Type:' ) === 0 ) {

                $type = trim( substr( $header, 13 ) );
            }
        }

        return [ $status, $type, $body ];
    }
}
