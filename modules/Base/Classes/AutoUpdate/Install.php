<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The install, in the booted process that decides it: the release check
 * (ReleaseCheck) when a release is due, or cmd=core-update --version / --force by hand
 * (byHand()).
 *
 * prepare() takes the install lock and opens both tarballs, proving each
 * through its handle (Downloads::open()): the release's, and the installed
 * release's, which a failed copy puts back. The copy waits for the end of
 * the process: cli.php calls run() once the command, and the scheduler's
 * other jobs, have finished and recorded what they did.
 *
 * PHP cannot unload a class, so once the copy starts this process loads
 * nothing more: run() saves the settings, restores PHP's own error and
 * exception handlers, registers an autoloader ahead of Composer's that
 * refuses every class, writes the install marker, copies with the
 * updater.php closures it loaded before the copy began (`apply`), and exits.
 * The next run of cli.php boots the new release and finishes the install
 * (updater.php `preboot`, Finish). A copy that fails is put back at once from
 * the installed release's tarball, one that is killed by the next run of
 * cli.php, and either is recorded in last-failure.json: automatic updates
 * skip that release until a newer one, or an install by hand.
 */
class Install {

    const OK      = 'ok';
    const REFUSED = 'refused';
    const BUSY    = 'busy';
    const FAILED  = 'failed';

    /** @var array<string, mixed>|null what run() installs, once prepare() has proved it */
    public static $pending = null;

    /**
     * Take the lock and prove both tarballs; set $pending. The caller has
     * judged the release.
     *
     * @param  Downloads $downloads
     * @param  string    $v         the release to install, downloaded
     * @param  string    $installed the installed release, downloaded
     * @param  string    $root      the OWA directory
     * @param  bool      $quiet     say nothing after the copy (the scheduler's runs)
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    public static function prepare( Downloads $downloads, $v, $installed, $root, $quiet ) {

        $u = Downloads::updater();

        if ( $u['readMarker']( $root ) !== null ) {

            return self::answer( self::BUSY, 'An install is under way; php cli.php cmd=core-update-status says where it is.' );
        }

        $hold = $u['tryLock']( dirname( InstallLock::path() ), sprintf( 'install %s', $v ) );

        if ( $hold === null ) {

            return self::answer( self::BUSY, 'Another install holds the install lock; try again in a minute.' );
        }

        try {

            $to         = $downloads->open( $v );
            $from       = $downloads->open( $installed );
            $to_files   = Tar::files( $to );
            $from_files = Tar::files( $from );

        } catch ( \Throwable $e ) {

            $u['unlock']( $hold );

            return self::answer( self::FAILED, sprintf( 'Installing %s failed before anything changed: %s', $v, $e->getMessage() ) );
        }

        self::$pending = [
            'root'       => rtrim( (string) $root, '/' ),
            'from'       => (string) $installed,
            'to'         => (string) $v,
            'to_h'       => $to,
            'from_h'     => $from,
            'to_files'   => $to_files,
            'from_files' => $from_files,
            'hold'       => $hold,
            'updates'    => $downloads->root(),
            'from_tar'   => $downloads->tarball( $installed ),
            'to_tar'     => $downloads->tarball( $v ),
            'quiet'      => (bool) $quiet,
            'u'          => $u,
        ];

        return self::answer( self::OK, sprintf( 'Installing OWA %s over %s: the files are copied when this run has finished, and the next run of cli.php finishes the install.', $v, $installed ) );
    }

    /**
     * cmd=core-update --force, or --version=<x.y.z> [--force], installed now
     * whatever the automatic update settings say. --force alone: the newest
     * release in this major from the listing, downloaded if it is not (and,
     * without the listing, the newest already downloaded). --version: that
     * release's tarball and signature fetched from the download URL by its
     * version, every time, with no listing (Releases::byTag()). A release not
     * newer than the installed one only with $force, and never one older than
     * the database schema. A failure recorded for it is cleared: whoever
     * installs by hand has dealt with it.
     *
     * @param  string|null          $version
     * @param  bool                 $force
     * @param  Downloads|null       $downloads
     * @param  array<string, mixed> $context ReleaseCheck's, for a test
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    public static function byHand( $version = null, $force = false, $downloads = null, array $context = [] ) {

        $downloads = $downloads ?? new Downloads();
        $check     = new ReleaseCheck( $downloads, $context );
        $c         = $check->context;
        $root      = (string) $c['root'];
        $installed = (string) $c['installed'];

        $checkout = Vcs::checkout( $root );

        if ( $checkout !== null ) {

            return self::answer( self::REFUSED, sprintf( 'OWA runs from a version control checkout (%s): update it with that.', $checkout ) );
        }

        if ( $c['role'] !== \OWA\Core\ServerRole::FULL ) {

            return self::answer( self::REFUSED, sprintf( 'This server\'s role is %s; OWA is installed from a full server only.', $c['role'] ) );
        }

        if ( Version::parse( $installed ) === null || ( $version !== null && Version::parse( (string) $version ) === null ) ) {

            return self::answer( self::REFUSED, sprintf( 'Both versions must be release versions: installed %s, asked for %s.', $installed, $version ?? 'the newest' ) );
        }

        if ( $version !== null && ( Version::step( $installed, $version ) === Version::MAJOR || Version::step( $version, $installed ) === Version::MAJOR ) ) {

            return self::answer( self::REFUSED, sprintf( '%s is another major version: that is a migration, done by hand from its release notes.', $version ) );
        }

        $list = null;

        if ( $version !== null ) {

            $release = Releases::byTag( $version, $installed, (string) $c['download'] );
            $v       = (string) $version;

        } else {

            try {

                $list = $c['releases'] ?? Releases::fetch( Releases::listingUrl( (string) $c['url'], $installed ) );

            } catch ( \RuntimeException $e ) {

                $list = null;
            }

            $release = $list === null ? null : Releases::choose( $list, $installed, Version::MINOR );
            $v       = (string) ( $release['version'] ?? ( $downloads->versions()[0] ?? '' ) );

            if ( $v === '' || Version::compare( $v, $installed ) <= 0 ) {

                return self::answer( self::REFUSED, $list === null
                    ? 'The releases could not be listed, and no newer release has been downloaded.'
                    : sprintf( 'No release is newer than %s.', $installed ) );
            }
        }

        if ( Version::compare( $v, $installed ) <= 0 && ! $force ) {

            return self::answer( self::REFUSED, sprintf( '%s is not newer than the installed %s; add --force to install it anyway.', $v, $installed ) );
        }

        if ( $release !== null && ( $version !== null || ! $downloads->has( $v ) ) ) {

            try {

                $downloads->download( $release );

            } catch ( \RuntimeException $e ) {

                return self::answer( self::FAILED, sprintf( 'Downloading %s failed: %s', $v, $e->getMessage() ) );
            }
        }

        $missing = $check->ensureInstalled( $list );

        if ( $missing !== null ) {

            return self::answer( self::REFUSED, $missing );
        }

        $judged = $check->judge( $v );

        if ( $judged['rollback'] ) {

            return self::answer( self::REFUSED, sprintf( 'This database is newer than %s expects. Undo these updates first, newest first, with the code installed now:', $v ), $judged['rollback'] );
        }

        if ( $judged['problems'] ) {

            return self::answer( self::REFUSED, sprintf( '%s cannot be installed here:', $v ), $judged['problems'] );
        }

        $result = self::prepare( $downloads, $v, $installed, $root, false );

        if ( $result['outcome'] === self::OK ) {

            $downloads->clearFailure();
        }

        return $result;
    }

    /**
     * The copy, at the end of the process, then exit. Nothing when nothing
     * is pending. Never returns otherwise.
     *
     * @return void
     */
    public static function run() {

        $p = self::$pending;

        if ( $p === null ) {

            return;
        }

        self::$pending = null;
        $u             = $p['u'];
        $root          = $p['root'];

        // This process's own work, before anything changes.
        try {

            CoreAPI::configSingleton()->saveIfDirty();

        } catch ( \Throwable $e ) {

            CoreAPI::debug( 'Saving settings before the copy: ' . $e->getMessage() );
        }

        $marker = [
            'format'   => 1,
            'from'     => $p['from'],
            'to'       => $p['to'],
            'phase'    => 'copying',
            'since'    => time(),
            'lock'     => $p['hold']['file'],
            'updates'  => $p['updates'],
            'from_tar' => $p['from_tar'],
            'to_tar'   => $p['to_tar'],
            'boots'    => 0,
        ];

        try {

            $u['writeMarker']( $root, $marker );

        } catch ( \RuntimeException $e ) {

            $u['recordFailure']( $p['updates'], $p['from'], $p['to'], $e->getMessage() . ' Nothing was installed.' );
            $u['unlock']( $p['hold'] );
            fwrite( STDERR, sprintf( "Installing OWA %s failed: %s Nothing was installed.\n", $p['to'], $e->getMessage() ) );

            exit( 1 );
        }

        // From here this process loads nothing: whatever it would load is the new release's.
        set_error_handler( null );
        set_exception_handler( null );
        spl_autoload_register( static function ( $class ) {

            throw new \LogicException( sprintf( '%s was to be loaded after the copy began; it is refused, so no process runs two releases at once.', $class ) );
        }, true, true );

        try {

            $u['apply']( $root, $p['to_h'], $p['from_files'] );

        } catch ( \Throwable $e ) {

            $why = rtrim( $e->getMessage(), '.' ) . '.';

            try {

                $u['apply']( $root, $p['from_h'], $p['to_files'] );
                @unlink( $u['marker']( $root ) );
                $u['recordFailure']( $p['updates'], $p['from'], $p['to'], sprintf( '%s OWA %s was put back.', $why, $p['from'] ) );
                fwrite( STDERR, sprintf( "Installing OWA %s failed: %s OWA %s was put back.\n", $p['to'], $why, $p['from'] ) );

            } catch ( \Throwable $back ) {

                // The marker stays, saying copying: the next run of cli.php tries again.
                $u['recordFailure']( $p['updates'], $p['from'], $p['to'], sprintf( '%s Putting OWA %s back failed too: %s', $why, $p['from'], $back->getMessage() ) );
                fwrite( STDERR, sprintf( "Installing OWA %s failed: %s Putting OWA %s back failed too: %s\n", $p['to'], $why, $p['from'], $back->getMessage() ) );
            }

            $u['unlock']( $p['hold'] );

            exit( 1 );
        }

        $marker['phase'] = 'copied';

        try {

            $u['writeMarker']( $root, $marker );

        } catch ( \RuntimeException $e ) {

            // Still saying copying, with the lock let go: the next run puts the installed release back.
            fwrite( STDERR, $e->getMessage() . "\n" );
        }

        $u['unlock']( $p['hold'] );

        if ( ! $p['quiet'] ) {

            fwrite( STDOUT, sprintf( "OWA %s's files are in place. The next run of cli.php boots it and finishes the install: run php cli.php cmd=core-update-status, or wait a minute for the scheduler.\n", $p['to'] ) );
        }

        exit( 0 );
    }

    /**
     * @param  string       $outcome
     * @param  string       $message
     * @param  list<string> $lines
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    private static function answer( $outcome, $message, array $lines = [] ) {

        return [ 'outcome' => $outcome, 'message' => $message, 'lines' => $lines ];
    }
}
