<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * Finishing an install. The new release's code, in the first run of cli.php after
 * the copy (updater.php `preboot`), which booted it before the command it
 * was asked for.
 *
 * The install marker says booted first: a boot that fails before then
 * counts, and the third puts the installed release back (updater.php). Then
 * the steps, each named in the marker as it starts (STEPS): the schema
 * updates (cmd=core-update-schema, each recording its version as it
 * completes), the tracking bundles (cmd=publish-trackers: a release can
 * change a setting's default with nothing to update) and a release check of
 * its own as a self-test. Then the notice that it is installed, and the
 * clean-up. Nothing is undone once the new release has booted: the schema
 * may have moved on.
 *
 * A step that fails is a failed install, and a broken one: recorded in
 * last-failure.json with the step, its notice raised at once (InstallNotices),
 * and cli.php exits 1. A hand run of cmd=core-update-schema runs the steps
 * after the schema updates again (rest()), and finishes the install. The
 * marker goes either way, and pending schema updates stop OWA as they always
 * do.
 *
 * The process holds the install lock throughout (InstallLock::$held_here,
 * and the hold in InstallLock::$hold, which cmd=core-update-schema touches
 * between updates), so cmd=core-update-schema knows it runs inside the
 * install. The schema updates' lease is the install's own, its owner kept
 * in the marker (schema_lease): a Finish killed during them leaves it held
 * for an hour, and the next takes it back and carries on.
 */
class Finish {

    /** Finish's steps, in order, as the marker and last-failure.json name them, and the install's log line for each. */
    const STEPS = [
        'schema'    => 'Applying its schema updates (cmd=core-update-schema).',
        'trackers'  => 'Republishing its tracking bundles (cmd=publish-trackers).',
        'self_test' => 'Running its own release check.',
    ];

    /**
     * @param  \owa                $owa    booted
     * @param  array<string, mixed> $marker the install marker
     * @param  array<string, mixed> $hold   the install lock, which preboot took
     * @return void
     */
    public static function run( $owa, array $marker, array $hold ) {

        $u    = Downloads::updater();
        $root = \OWA\Core\Env::root();
        $v    = (string) ( $marker['to'] ?? '' );

        InstallLock::$held_here = true;
        InstallLock::$hold      = $hold;

        $owa->setCurrentUser( 'admin' );

        /*
         * The marker's updates directory, unless it is now a link: the web
         * server can rename the directory and put a link in its place, and
         * cleanup() deletes in it. Then the directory is looked for as on any
         * other run (Downloads), which refuses a link.
         */
        $updates   = (string) ( $marker['updates'] ?? '' );
        $downloads = new Downloads( $updates !== '' && ! is_link( rtrim( $updates, '/' ) ) ? $updates : null );

        // Kept in the marker: a Finish run again takes back what a killed one held (CoreUpdateSchemaCli).
        $lease  = (string) ( $marker['schema_lease'] ?? '' ) ?: bin2hex( random_bytes( 8 ) );
        $marker = [ 'phase' => 'booted', 'schema_lease' => $lease ] + $marker;

        // Each step named in the marker, and logged, as it starts: Finish stopping three times records where.
        $at = static function ( $step ) use ( &$marker, $u, $root ) {

            $marker['step'] = $step;
            $marker         = $u['logged']( $marker, self::STEPS[ $step ] );
            $u['writeMarker']( $root, $marker );
            InstallLock::touch();
        };

        try {

            $at( 'schema' );
            InstallLock::$schema_lease = $lease;

            $update = self::command( '\\OWA\\Module\\Base\\Controller\\CoreUpdateSchemaCli' );

            if ( $update['outcome'] !== 'ok' || CoreAPI::isSchemaUpdateRequired() ) {

                throw new \RuntimeException( $update['message'] ?: 'Schema updates are still pending.' );
            }

            self::rest( $at );
            self::done( $downloads, $v );

        } catch ( \Throwable $e ) {

            self::failed( $downloads, $marker, $e->getMessage() );
        }

        @unlink( $u['marker']( $root ) );
        $u['unlock']( $hold );

        InstallLock::$held_here    = false;
        InstallLock::$hold         = null;
        InstallLock::$schema_lease = null;
    }

    /**
     * The steps after the schema updates, each started with $at( step ): the
     * tracking bundles and the self-test. Throws on the first that fails.
     *
     * @param  callable(string): void $at
     * @return void
     */
    public static function rest( callable $at ) {

        $at( 'trackers' );
        $publish = self::command( '\\OWA\\Module\\Base\\Controller\\TrackerPublishCli' );

        if ( $publish['outcome'] !== 'ok' ) {

            throw new \RuntimeException( $publish['message'] ?: 'The tracking bundles were not published.' );
        }

        $at( 'self_test' );
        self::selfTest();
    }

    /**
     * The install is complete: its notice, and the clean-up.
     *
     * @param  Downloads $downloads
     * @param  string    $v
     * @return void
     */
    public static function done( Downloads $downloads, $v ) {

        InstallNotices::send( [
            'source'     => ReleaseCheck::SOURCE,
            'source_key' => 'installed:' . $v,
            'title'      => sprintf( CoreAPI::t( 'OWA %s is installed' ), $v ),
            'body'       => sprintf( CoreAPI::t( 'OWA %s is installed and its schema updates are applied.' ), $v ),
            'severity'   => 'info',
            'email'      => (bool) CoreAPI::getSetting( 'base', 'auto_update_email_success' ),
        ] );

        self::cleanup( $downloads, $v );
        fwrite( STDOUT, sprintf( "OWA %s is installed.\n", $v ) );
    }

    /**
     * A step failed: recorded with the step $m names, its notice raised, and
     * cli.php exits 1.
     *
     * @param  Downloads            $downloads
     * @param  array<string, mixed> $m     the marker, or one made for a hand run: to, from, step
     * @param  string               $error
     * @return void
     */
    public static function failed( Downloads $downloads, array $m, $error ) {

        // The one writer of last-failure.json, the installer's too; a write that fails says so on STDERR.
        Downloads::updater()['recordFailure']( $downloads->root(), [ 'phase' => 'booted' ] + $m, $error, false, true );

        $failure = $downloads->failure() ?? [ 'version' => (string) ( $m['to'] ?? '' ), 'from' => (string) ( $m['from'] ?? '' ),
            'error' => $error, 'booted' => true, 'stage' => 'finish', 'step' => (string) ( $m['step'] ?? 'schema' ) ];

        InstallNotices::forFailure( $failure, [ InstallNotices::class, 'send' ] );
        fwrite( STDERR, implode( "\n\n", InstallNotices::describe( $failure )['lines'] ) . "\n" );

        if ( ! \OWA\Core\Controller\Cli::$exit_code ) {

            \OWA\Core\Controller\Cli::$exit_code = 1;
        }
    }

    /**
     * Run a CLI command's controller in this process, as cli.php would, and
     * say how it ended.
     *
     * @param  string $class
     * @return array{outcome: string, message: string, retry: bool}
     */
    private static function command( $class ) {

        $cli = new $class( [] );
        $cli->doAction();

        return $cli->getCliOutcome();
    }

    /**
     * The new release's own release check, once: a release that cannot list
     * the releases cannot install the one that fixes it. A failure of the
     * code throws; one of the network does not.
     *
     * @return void
     */
    private static function selfTest() {

        try {

            ( new ReleaseCheck() )->run( true );

        } catch ( \RuntimeException $e ) {

            return;
        }
    }

    /**
     * After a successful install: the failure record, and every tarball but
     * this release's, which the next install puts back if it fails.
     *
     * @param  Downloads $downloads
     * @param  string    $v
     * @return void
     */
    public static function cleanup( Downloads $downloads, $v ) {

        $downloads->clearFailure();
        $downloads->prune( [ $v ] );
    }
}
