<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What this server is configured to do, and what stops it: `php cli.php
 * cmd=server-check`.
 *
 * Prints the role (Core\ServerRole), the request types it serves, whether it
 * runs the scheduler, the tracking intake's queue backend, what owa-config.php
 * is missing for the role, and retired constants still set.
 *
 * On a collector with a queue backend it also sends a test message to the
 * queue, receives it and deletes it. A message it receives that is not its own
 * is released at once for the drain. The test message is not a tracker
 * envelope, so a drain that takes it first dead-letters it. With
 * OWA_TRACKING_URL set, a collector also requests its own log.php through it,
 * with no event, which logs nothing.
 *
 * Fails (exit 1) when something is missing or the round trip did not complete.
 */
class ServerCheckCli extends \OWA\Core\Controller\Cli {

    /** How long the round trip waits to receive its own message, in seconds. */
    const ROUND_TRIP_SECONDS = 10;

    /** A check that fails exits 1, for a deploy script or a monitor. */
    protected $exit_on_failure = true;

    /** @var callable|null fn( $url ) => [ status, content type ]. TESTS ONLY. */
    public static $fetch = null;

    /** See \OWA\Core\Controller\Cli::serverRoles(). A diagnostic: every server can check itself. */
    public static function serverRoles() {

        return \OWA\Core\ServerRole::ALL;
    }

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Prints this server\'s role, what it serves, the tracking queue backend and anything owa-config.php is missing for the role. On a collector, sends a test message to the queue and receives and deletes it, and with OWA_TRACKING_URL set requests its own log.php through it. Exits 1 when something is missing or a test fails.',
            'arguments'   => array(),
        );
    }

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $role     = \OWA\Core\ServerRole::current();
        $defined  = \OWA\Core\ServerRole::definedConstants();
        $backends = \OWA\Core\ModuleActivation::queueBackends();
        $problems = $role->configProblems( $defined, $backends, \OWA\Core\ModuleActivation::queueBackendConstants() );

        $lines = array(
            'Server role:      ' . $role->name(),
            'Serves:           ' . implode( ', ', $role->requestTypes() ),
            'Scheduler:        ' . ( $role->runsScheduler() ? 'runs here' : 'does not run here' ),
            'Database:         ' . ( $role->hasDatabase() ? 'yes' : 'none' ),
            'Queue backend:    ' . ( $backends
                ? implode( ', ', array_map( fn ( $type, $constant ) => $type . ' (' . $constant . ')', array_keys( $backends ), $backends ) )
                : 'file' ),
            'Tracking events:  ' . ( \OWA\Module\Base\Classes\TrackerIngest::isQueued() ? 'queued' : 'written as they arrive' ),
        );

        $lines[] = 'Missing:          ' . ( $problems ? '' : 'nothing' );

        foreach ( $problems as $problem ) {

            $lines[] = '  ' . $problem;
        }

        foreach ( \OWA\Core\ServerRole::upgradeNotices( $defined ) as $constant => $notice ) {

            $lines[] = sprintf( 'Retired constant: %s. %s', $constant, $notice );
        }

        $failed = (bool) $problems;

        if ( ! $problems && $role->is( \OWA\Core\ServerRole::COLLECTOR ) && $backends ) {

            $trip     = $this->roundTrip( \OWA\Module\Base\Classes\TrackerIngest::queue() );
            $lines[]  = 'Queue round trip: ' . $trip['message'];
            $failed   = $failed || ! $trip['ok'];
        }

        if ( ! $problems && $role->is( \OWA\Core\ServerRole::COLLECTOR )
             && (string) ( $defined['OWA_TRACKING_URL'] ?? '' ) !== '' ) {

            $reach   = $this->trackingUrlCheck( \OWA\Module\Base\Classes\TrackerBundle::trackingUrl() . 'log.php' );
            $lines[] = 'Tracking URL:     ' . $reach['message'];
            $failed  = $failed || ! $reach['ok'];
        }

        $this->write( $lines );

        if ( $failed ) {

            return $this->fail( 'Server check failed: see above.' );
        }
    }

    /**
     * Request log.php at the tracking URL, with no event: it answers with its
     * 1x1 GIF and logs nothing.
     *
     * @param string $url
     * @return array{ok: bool, message: string}
     */
    public function trackingUrlCheck( $url ) {

        list( $status, $type ) = self::$fetch ? call_user_func( self::$fetch, $url ) : self::fetch( $url );

        if ( (int) $status === 200 && stripos( (string) $type, 'image/gif' ) === 0 ) {

            return array( 'ok' => true, 'message' => sprintf( 'ok: %s answers.', $url ) );
        }

        return array( 'ok' => false, 'message' => sprintf( '%s answered %s%s, not log.php\'s image.', $url,
            $status ? 'HTTP ' . (int) $status : 'nothing', $type ? ' (' . $type . ')' : '' ) );
    }

    /** @return array{0: int, 1: string} the status and content type of a GET, 0 and '' when it failed */
    private static function fetch( $url ) {

        $context = stream_context_create( array( 'http' => array(
            'method'        => 'GET',
            'timeout'       => 10,
            'ignore_errors' => true,
            'header'        => "User-Agent: Open Web Analytics server-check\r\n",
        ) ) );

        $stream = @fopen( $url, 'rb', false, $context );

        if ( ! $stream ) {

            return array( 0, '' );
        }

        // Every response's headers, a followed redirect's included.
        $meta    = stream_get_meta_data( $stream );
        $headers = is_array( $meta['wrapper_data'] ?? null ) ? $meta['wrapper_data'] : array();

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

        return array( $status, $type );
    }

    /**
     * Send a test message, receive it and delete it.
     *
     * @return array{ok: bool, message: string}
     */
    public function roundTrip( \OWA\Core\IntakeQueue $queue ) {

        $token    = bin2hex( random_bytes( 8 ) );
        $started  = microtime( true );
        $envelope = array(
            // Not ENVELOPE_VERSION: a drain that takes it does not ingest it.
            'v'          => 0,
            'type'       => 'owa-server-check',
            'properties' => array( 'check' => $token ),
            'queued_at'  => time(),
        );

        if ( ! $queue->send( $envelope ) ) {

            return array( 'ok' => false, 'message' => 'failed to send: '
                . ( \OWA\Module\Base\Classes\TrackerIngest::queueError( $queue ) ?: 'refused' ) );
        }

        $deadline = $started + self::ROUND_TRIP_SECONDS;

        do {

            if ( method_exists( $queue, 'clearLastError' ) ) {

                $queue->clearLastError();
            }

            $messages = $queue->receive( 10, 30 );
            $error    = \OWA\Module\Base\Classes\TrackerIngest::queueError( $queue );

            if ( ! $messages && $error ) {

                return array( 'ok' => false, 'message' => 'sent, but receiving failed: ' . $error );
            }

            $mine = null;

            foreach ( $messages as $message ) {

                if ( ( $message->envelope['properties']['check'] ?? null ) === $token ) {

                    $mine = $message;

                } else {

                    // A beacon, or another check's message: back to the queue at once.
                    $queue->release( $message, 0 );
                }
            }

            if ( $mine ) {

                if ( ! $queue->ack( $mine ) ) {

                    return array( 'ok' => false, 'message' => 'sent and received, but deleting failed: '
                        . ( \OWA\Module\Base\Classes\TrackerIngest::queueError( $queue ) ?: 'refused' ) );
                }

                return array( 'ok' => true, 'message' => sprintf(
                    'ok: sent, received and deleted a test message in %.1f s.', microtime( true ) - $started ) );
            }

            if ( microtime( true ) < $deadline ) {

                usleep( 250000 );
            }

        } while ( microtime( true ) < $deadline );

        return array( 'ok' => false, 'message' => sprintf(
            'sent, but not received back within %d seconds. A drain may have taken it first.', self::ROUND_TRIP_SECONDS ) );
    }
}

?>
