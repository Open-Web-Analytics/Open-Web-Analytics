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
 * cmd=core-update-schema (the schema updates, each recording its version as
 * it completes), cmd=publish-trackers (a release can change a setting's
 * default with nothing to update), the success notice, a release check of
 * its own as a self-test, and the clean-up. Nothing is undone once the new
 * release has booted: the schema may have moved on. A failure records its
 * error in last-failure.json and raises the notice it calls for; the marker
 * goes either way, and pending schema updates stop OWA as they always do.
 *
 * The process holds the install lock throughout (InstallLock::$held_here,
 * and the hold in InstallLock::$hold, which cmd=core-update-schema touches
 * between updates), so cmd=core-update-schema knows it runs inside the
 * install. The schema updates' lease is the install's own, its owner kept
 * in the marker (schema_lease): a Finish killed during them leaves it held
 * for an hour, and the next takes it back and carries on.
 */
class Finish {

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

        $downloads = new Downloads( (string) ( $marker['updates'] ?? '' ) ?: null );
        $notify  = [ InstallNotices::class, 'send' ];
        $failed  = false;

        try {

            // Kept in the marker: a Finish run again takes back what a killed one held (CoreUpdateSchemaCli).
            $lease = (string) ( $marker['schema_lease'] ?? '' );

            if ( ( $marker['phase'] ?? '' ) !== 'booted' || $lease === '' ) {

                $lease = $lease ?: bin2hex( random_bytes( 8 ) );
                $u['writeMarker']( $root, [ 'phase' => 'booted', 'schema_lease' => $lease ] + $marker );
            }

            InstallLock::$schema_lease = $lease;

            $update = self::command( '\\OWA\\Module\\Base\\Controller\\CoreUpdateSchemaCli' );

            if ( $update['outcome'] !== 'ok' || CoreAPI::isSchemaUpdateRequired() ) {

                throw new \RuntimeException( sprintf( 'cmd=core-update-schema did not complete: %s', $update['message'] ?: 'schema updates are still pending' ) );
            }

            InstallLock::touch();
            $publish = self::command( '\\OWA\\Module\\Base\\Controller\\TrackerPublishCli' );
            InstallLock::touch();

            $notify( [
                'source'     => ReleaseCheck::SOURCE,
                'source_key' => 'installed:' . $v,
                'title'      => sprintf( CoreAPI::t( 'OWA %s is installed' ), $v ),
                'body'       => sprintf( CoreAPI::t( 'OWA %s is installed and its schema updates are applied.' ), $v )
                    . ( $publish['outcome'] === 'ok' ? '' : ' ' . sprintf( CoreAPI::t( 'Republishing the tracking bundles failed (%s); run php cli.php cmd=publish-trackers.' ), $publish['message'] ) ),
                'severity'   => 'info',
                'email'      => (bool) CoreAPI::getSetting( 'base', 'auto_update_email_success' ),
            ] );

        } catch ( \Throwable $e ) {

            $failed  = true;
            $failure = [ 'version' => $v, 'from' => (string) ( $marker['from'] ?? '' ), 'error' => $e->getMessage(), 'at' => time(), 'booted' => true ];

            try {

                $downloads->writeFailure( $failure );

            } catch ( \RuntimeException $write ) {

                CoreAPI::debug( 'Recording the install\'s error: ' . $write->getMessage() );
            }

            InstallNotices::forFailure( $failure, $notify );
            fwrite( STDERR, sprintf( "Finishing the install of OWA %s: %s\n", $v, $e->getMessage() ) );
        }

        if ( ! $failed ) {

            self::selfTest( $v, $notify );
            self::cleanup( $downloads, $v );
            fwrite( STDOUT, sprintf( "OWA %s is installed.\n", $v ) );
        }

        @unlink( $u['marker']( $root ) );
        $u['unlock']( $hold );

        InstallLock::$held_here    = false;
        InstallLock::$hold         = null;
        InstallLock::$schema_lease = null;
    }

    /**
     * Run a CLI command's controller in this process, as cli.php would, and
     * say how it ended.
     *
     * @param  string $class
     * @return array{outcome: string, message: string}
     */
    private static function command( $class ) {

        $cli = new $class( [] );
        $cli->doAction();

        return $cli->getCliOutcome();
    }

    /**
     * The new release's own release check, once: a release that cannot list
     * the releases cannot install the one that fixes it. A failure of the
     * code (not of the network) is a critical notice.
     *
     * @param  string                                $v
     * @param  callable(array<string, mixed>): mixed $notify
     * @return void
     */
    private static function selfTest( $v, callable $notify ) {

        try {

            ( new ReleaseCheck() )->run( true );

        } catch ( \RuntimeException $e ) {

            return;

        } catch ( \Throwable $e ) {

            $notify( [
                'source'     => ReleaseCheck::SOURCE,
                'source_key' => 'self_test:' . $v,
                'title'      => sprintf( CoreAPI::t( 'OWA %s cannot check for its own updates' ), $v ),
                'body'       => $e->getMessage() . "\n\n" . CoreAPI::t( 'Its release check failed straight after it installed, so it may not install the release that fixes this. Update it by hand from the next release.' ),
                'severity'   => 'critical',
                'email'      => true,
            ] );
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
