<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * Step 4: finish. The new release's code, in the process that copied it
 * (updater.php `install`), which loaded no OWA code before the copy: this is
 * the new release booting as any fresh process would.
 *
 * Boot, and record booted: true in install.json before anything changes the
 * schema (a failure before it is restored by the updater; one after it is
 * not). Then cmd=update (the schema updates, each recording its version as
 * it completes, then the tracker bundles), cmd=publish-trackers (a release can
 * change a setting's default with nothing to update), the success notice, a
 * release check of its own as a self-test, and the clean-up. A failure after
 * boot records its error in install.json, which already holds the attempt and
 * retry_after from the claim, and raises the notice the attempt calls for.
 *
 * The process holds the install lock throughout (InstallLock::$held_here),
 * so cmd=update does not try to take it again.
 */
class Finish {

    /**
     * @param  array<string, mixed> $c the updater's context, with install (install.json's path) and record (its writer)
     * @return int the exit code
     */
    public static function run( array $c ) {

        InstallLock::$held_here = true;

        $record = $c['record'];
        $owa    = new \owa( [ 'request_type' => 'cli' ] );
        $owa->setCurrentUser( 'admin' );

        $record( [ 'booted' => true ] );

        $v       = (string) $c['version'];
        $staging = new Staging( (string) $c['updates'] );
        $notify  = static fn ( array $n ) => \OWA\Module\Base\Classes\NotificationManager::notify( $n );

        try {

            $update = self::command( '\\OWA\\Module\\Base\\Controller\\UpdatesApplyCli' );

            if ( $update['outcome'] !== 'ok' || CoreAPI::isSchemaUpdateRequired() ) {

                throw new \RuntimeException( sprintf( 'cmd=update did not complete: %s', $update['message'] ?: 'schema updates are still pending' ) );
            }

            $publish = self::command( '\\OWA\\Module\\Base\\Controller\\TrackerPublishCli' );

            $notify( [
                'source'     => ReleaseCheck::SOURCE,
                'source_key' => 'installed:' . $v,
                'title'      => sprintf( CoreAPI::t( 'OWA %s is installed' ), $v ),
                'body'       => sprintf( CoreAPI::t( 'Automatic updates installed OWA %s.' ), $v )
                    . ( $publish['outcome'] === 'ok' ? '' : ' ' . sprintf( CoreAPI::t( 'Republishing the tracking bundles failed (%s); run php cli.php cmd=publish-trackers.' ), $publish['message'] ) ),
                'severity'   => 'info',
                'email'      => (bool) CoreAPI::getSetting( 'base', 'auto_update_email_success' ),
            ] );

        } catch ( \Throwable $e ) {

            $record( [ 'error' => $e->getMessage() ] );
            InstallNotices::forInstall( (array) $staging->readJson( (string) $c['install'] ), $notify );

            fwrite( STDERR, sprintf( "Installing OWA %s: %s\n", $v, $e->getMessage() ) );

            return 1;
        }

        self::selfTest( $v, $notify );
        self::cleanup( $staging, $v );

        fwrite( STDOUT, sprintf( "OWA %s is installed.\n", $v ) );

        return 0;
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
     * After a successful install: staged.json if it names exactly this
     * version, the stagings of installs it superseded (below it, holding an
     * install.json), and its own staging last, updater.php with it. Nothing
     * else: Step 1 deletes what it replaces, and a staging made by hand stays.
     *
     * @param  Staging $staging
     * @param  string  $v
     * @return void
     */
    public static function cleanup( Staging $staging, $v ) {

        if ( ( $staging->staged()['version'] ?? null ) === $v ) {

            $staging->removeStaged();
        }

        foreach ( $staging->versions() as $other ) {

            if ( Version::compare( $other, $v ) < 0 && $staging->hasInstall( $other ) ) {

                Staging::remove( $staging->dir( $other ) );
            }
        }

        Staging::remove( $staging->dir( $v ) );
    }
}
