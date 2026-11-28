<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The install, in the booted process that decides it: the release check
 * (ReleaseCheck) when a release is due, or cmd=core-install --version or
 * --file by hand (byHand(), fromFile()).
 *
 * prepare() takes the install lock, proves the release's tarball
 * (Downloads::open(), a private copy checked against its signature) and
 * decides what the copy changes (updater.php `plan`): what it overwrites,
 * and in the directories the release ships, the files it does not ship,
 * which go. The copy reads that private copy, never the file on disk. The
 * copy waits for the end of the process: cli.php calls run() once the
 * command, and the scheduler's other jobs, have finished and recorded what
 * they did.
 *
 * PHP cannot unload a class, so neither this process nor any other run of
 * cli.php loads one from the new release while it runs the old: run() saves
 * the settings, writes the install marker and waits for the other runs of
 * cli.php to end, backs up every file the copy overwrites or deletes
 * (`backup`) and writes their paths and hashes in the marker, restores PHP's
 * own error and exception handlers, registers an autoloader ahead of
 * Composer's that refuses every class, copies with the updater.php closures
 * it loaded before the copy began (`apply`), and exits.
 * The next run of cli.php boots the new release and finishes the install
 * (updater.php `preboot`, Finish). A copy that fails is put back at once from
 * the backup (`restore`), one that is killed by the next run of cli.php,
 * and either is recorded in last-failure.json: automatic updates
 * try that release again 6 hours on, 3 attempts in all, then skip it until
 * a newer one, or an install by hand (updater.php `recordFailure`).
 */
class Install {

    const OK      = 'ok';
    const REFUSED = 'refused';
    const BUSY    = 'busy';
    const FAILED  = 'failed';

    /** @var array<string, mixed>|null what run() installs, once prepare() has proved it */
    public static $pending = null;

    /** Seconds run() waits for the other runs of cli.php to end before it copies. */
    const WAIT = 300;

    /**
     * Take the lock, or the caller's hold of it, prove the release's tarball
     * and decide what the copy changes; set $pending, which holds the lock
     * until the copy. Refused when the updates directory's disk has no room
     * for the backup. On any other outcome the lock is let go, the caller's
     * hold too. The caller has judged the release.
     *
     * @param  Downloads $downloads
     * @param  string    $v         the release to install, downloaded
     * @param  string    $installed the installed release, downloaded
     * @param  string    $root      the OWA directory
     * @param  bool         $quiet     say nothing after the copy (the scheduler's runs)
     * @param  list<string> $log       what the check did, which starts the install's log (the marker's)
     * @param  array<string, mixed>|null $hold the install lock the caller took (lock()); null takes it here
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    public static function prepare( Downloads $downloads, $v, $installed, $root, $quiet, array $log = [], $hold = null ) {

        $u = Downloads::updater();

        if ( $u['readMarker']( $root ) !== null ) {

            if ( $hold !== null ) {

                $u['unlock']( $hold );
            }

            return self::answer( self::BUSY, 'An install is under way; php cli.php cmd=core-update-status says where it is.' );
        }

        $hold = $hold ?? $u['tryLock']( dirname( InstallLock::path() ), sprintf( 'install %s', $v ) );

        if ( $hold === null ) {

            return self::answer( self::BUSY, 'Another install holds the install lock; try again in a minute.' );
        }

        // A run that started before an install finished still runs the release that install replaced.
        $on_disk = $u['installedVersion']( $root );

        if ( $on_disk !== (string) $installed ) {

            $u['unlock']( $hold );

            return self::answer( self::BUSY, sprintf( 'This run started with OWA %1$s, and Core/Env.php now says %2$s: nothing was done.', $installed, $on_disk ?? 'no release version' ) );
        }

        if ( ! $downloads->canRecord() ) {

            $u['unlock']( $hold );

            return self::answer( self::FAILED, sprintf( 'Installing %s failed before anything changed: the updates directory cannot be written (is the disk full?), so a failed install could not be recorded.', $v ) );
        }

        try {

            // The private copy Downloads proved: what is copied is what was checked.
            $to   = $downloads->open( $v );
            $plan = $u['plan']( $root, $to, self::keeps( $root ) );

        } catch ( \Throwable $e ) {

            $u['unlock']( $hold );

            return self::answer( self::FAILED, sprintf( 'Installing %s failed before anything changed: %s', $v, $e->getMessage() ) );
        }

        $room = Preflight::backup( (int) $plan['bytes'], $downloads->root() );

        if ( $room ) {

            $u['unlock']( $hold );

            return self::answer( self::FAILED, sprintf( 'Installing %s failed before anything changed: %s', $v, implode( ' ', $room ) ) );
        }

        self::$pending = [
            'root'    => rtrim( (string) $root, '/' ),
            'from'    => (string) $installed,
            'to'      => (string) $v,
            'to_h'    => $to,
            'plan'    => $plan,
            'hold'    => $hold,
            'updates' => $downloads->root(),
            'quiet'   => (bool) $quiet,
            'u'       => $u,
            'log'     => array_reduce( array_merge( $log, [ sprintf( 'Installing OWA %s over %s: its tarball proved, the install lock held; the copy writes %d files and deletes %d.',
                $v, $installed, count( $plan['writes'] ), count( $plan['deletes'] ) ) ] ),
                static fn ( $m, $line ) => $u['logged']( $m, $line ), [] )['log'],
        ];

        return self::answer( self::OK, sprintf( 'Installing OWA %s over %s: the files are copied when this run has finished, and the next run of cli.php finishes the install.', $v, $installed ) );
    }

    /**
     * What the copy never deletes (updater.php `keeps`): the paths it names,
     * and this installation's data directory and tracking bundles, wherever
     * OWA_DATA_DIR and OWA_PUBLIC_PATH put them.
     *
     * @param  string $root
     * @return list<string>
     */
    public static function keeps( $root ) {

        return Downloads::updater()['keeps']( $root, \OWA\Core\Env::dataDir(), (string) CoreAPI::getSetting( 'base', 'public_path' ) );
    }

    /**
     * cmd=core-install --version=<x.y.z> [--force]: that release installed
     * now, whatever the automatic update settings say, its tarball and
     * signature fetched from the download URL by its version (Releases::byTag())
     * unless they are held already. Any release whose
     * requirements and upgrades_from this installation meets
     * (ReleaseCheck::judge()), of any major version. The installed release
     * again is reinstalled, copying the files that differ; an older one only
     * with $force, and never one older than the database schema. A failure
     * recorded for it is cleared: whoever installs by hand has dealt with it.
     * A refused release's tarball stays, as the pending one (Downloads), so
     * trying it again downloads nothing. The install lock is held from
     * before the download until the copy has it (installNamed()).
     *
     * @param  string               $version
     * @param  bool                 $force
     * @param  Downloads|null       $downloads
     * @param  array<string, mixed> $context ReleaseCheck's, for a test
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    public static function byHand( $version, $force = false, $downloads = null, array $context = [] ) {

        $v = (string) $version;

        return self::installNamed( $v, $force, $downloads ?? new Downloads(), $context,
            static function ( Downloads $downloads, array $c ) use ( $v ) {

                // Held already, as one refused before: proved again when it is opened.
                if ( $downloads->has( $v ) ) {

                    return null;
                }

                try {

                    $downloads->download( Releases::byTag( $v, (string) $c['installed'], (string) $c['download'] ) );

                } catch ( \RuntimeException $e ) {

                    return sprintf( 'Downloading %s failed: %s', $v, $e->getMessage() );
                }

                return null;
            },
            sprintf( 'php cli.php cmd=core-install --version=%s%s, by hand.', $v, $force ? ' --force' : '' ) );
    }

    /**
     * cmd=core-install --file=<tarball> [--sig=<path>] [--force]: the release
     * in a tarball on this server installed, as byHand() installs one it
     * downloads. Its version is read from its release.php, else its
     * Core/Env.php; its signature is <tarball>.sig unless $sig names it, and
     * is checked against the trusted keys (Signature) before anything else.
     * A tarball that is not signed, or not by a trusted key, is refused. The
     * proved tarball and its signature are put in the updates directory
     * (Downloads::import()), and from there it installs as a download does.
     * It makes no network request.
     *
     * @param  string               $file
     * @param  string|null          $sig
     * @param  bool                 $force
     * @param  Downloads|null       $downloads
     * @param  array<string, mixed> $context ReleaseCheck's, for a test
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    public static function fromFile( $file, $sig = null, $force = false, $downloads = null, array $context = [] ) {

        // The operator's own path: a link to the tarball is followed.
        $path     = (string) ( realpath( (string) $file ) ?: $file );
        $sig_path = (string) ( $sig !== null ? ( realpath( (string) $sig ) ?: $sig ) : $path . '.sig' );

        if ( ! is_file( $path ) ) {

            return self::answer( self::REFUSED, sprintf( 'There is no file %s.', $file ) );
        }

        try {

            [ $copy, $sha ] = Downloads::privateCopy( $path );

        } catch ( \RuntimeException $e ) {

            return self::answer( self::REFUSED, $e->getMessage() );
        }

        try {

            try {

                $v = self::tarballVersion( $copy );

            } catch ( \RuntimeException $e ) {

                return self::answer( self::REFUSED, sprintf( '%1$s is not a release tarball: %2$s', basename( $path ), $e->getMessage() ) );
            }

            if ( $v === null ) {

                return self::answer( self::REFUSED, sprintf( '%s names no release version: it has no release.php or Core/Env.php that says one.', basename( $path ) ) );
            }

            if ( ! is_file( $sig_path ) ) {

                return self::answer( self::REFUSED, sprintf( '%1$s is not signed: there is no %2$s. Give its signature with --sig=<path>.', basename( $path ), $sig_path ) );
            }

            $signature = (string) file_get_contents( $sig_path );

            try {

                Signature::verify( $v, $sha, $signature );

            } catch ( \RuntimeException $e ) {

                return self::answer( self::REFUSED, sprintf( '%1$s is refused: %2$s', basename( $path ), $e->getMessage() ) );
            }

            return self::installNamed( $v, $force, $downloads ?? new Downloads(), $context,
                static function ( Downloads $downloads ) use ( $v, $copy, $signature ) {

                    try {

                        $downloads->import( $v, $copy, $signature );

                    } catch ( \RuntimeException $e ) {

                        return sprintf( 'Putting %s in the updates directory failed: %s', $v, $e->getMessage() );
                    }

                    return null;
                },
                sprintf( 'php cli.php cmd=core-install --file=%s%s, by hand.', $path, $force ? ' --force' : '' ) );

        } finally {

            fclose( $copy );
        }
    }

    /**
     * The release version a tarball says it is: its release.php's, else the
     * VERSION its Core/Env.php declares; null when neither says one.
     *
     * @param  resource $tar
     * @return string|null
     * @throws \RuntimeException when it is not a tarball a release may be
     */
    public static function tarballVersion( $tar ) {

        rewind( $tar );
        $info = \OWA\Core\VersionInfo::decode( Tar::member( $tar, \OWA\Core\VersionInfo::FILE ) );
        $v    = is_array( $info ) ? (string) ( $info['version'] ?? '' ) : '';

        if ( $v === '' ) {

            rewind( $tar );
            $v = preg_match( "/const VERSION = '(\d+\.\d+\.\d+)';/", (string) Tar::member( $tar, 'Core/Env.php' ), $m ) ? $m[1] : '';
        }

        rewind( $tar );

        return Version::parse( $v ) !== null ? $v : null;
    }

    /**
     * byHand() and fromFile(): the release judged, $fetch putting its tarball
     * in the updates directory, and the install prepared.
     *
     * @param  string                                               $v
     * @param  bool                                                 $force
     * @param  Downloads                                            $downloads
     * @param  array<string, mixed>                                 $context
     * @param  callable(Downloads, array<string, mixed>): ?string   $fetch    why it failed, or null
     * @param  string                                               $how      the install log's first line
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    private static function installNamed( $v, $force, Downloads $downloads, array $context, callable $fetch, $how ) {

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

        if ( Version::parse( $installed ) === null || Version::parse( $v ) === null ) {

            return self::answer( self::REFUSED, sprintf( 'Both versions must be release versions: installed %s, asked for %s.', $installed, $v ) );
        }

        // The installed release again reinstalls it; an older one only with --force.
        if ( Version::compare( $v, $installed ) < 0 && ! $force ) {

            return self::answer( self::REFUSED, sprintf( '%s is older than the installed %s; add --force to install it anyway.', $v, $installed ) );
        }

        $foreign = $check->foreignProblems();

        if ( $foreign ) {

            return self::answer( self::FAILED, implode( ' ', $foreign ) );
        }

        if ( Downloads::updater()['readMarker']( $root ) !== null ) {

            return self::answer( self::BUSY, 'An install is under way; php cli.php cmd=core-update-status says where it is.' );
        }

        // Held from before anything is fetched or deleted until the copy has it.
        $hold = self::lock( sprintf( 'install %s', $v ) );

        if ( $hold === null ) {

            return self::answer( self::BUSY, 'Another install holds the install lock; try again in a minute.' );
        }

        $handed = false;

        try {

            $failed = $fetch( $downloads, $c );

            if ( $failed !== null ) {

                return self::answer( self::FAILED, $failed );
            }

            // The release named is the pending one, refused or not: any other goes.
            $downloads->prune( [ $v ] );

            $judged = $check->judge( $v );

            if ( $judged['rollback'] ) {

                return self::answer( self::REFUSED, sprintf( 'This database is newer than %s expects. Undo these updates first, newest first, with the code installed now:', $v ), $judged['rollback'] );
            }

            if ( $judged['problems'] ) {

                return self::answer( self::REFUSED, sprintf( '%s cannot be installed here:', $v ), $judged['problems'] );
            }

            // prepare() takes the hold: it hands it to the copy, or lets it go.
            $handed = true;
            $result = self::prepare( $downloads, $v, $installed, $root, false, [ $how ], $hold );

        } finally {

            if ( ! $handed ) {

                self::unlock( $hold );
            }
        }

        if ( $result['outcome'] === self::OK ) {

            $downloads->clearFailure();
        }

        return $result;
    }

    /**
     * The install lock, taken now (updater.php `tryLock`), or null when
     * another process holds it.
     *
     * @param  string $who what takes it, for the lock file's line
     * @return array<string, mixed>|null
     */
    public static function lock( $who ) {

        return Downloads::updater()['tryLock']( dirname( InstallLock::path() ), (string) $who );
    }

    /**
     * Let go of a hold lock() gave.
     *
     * @param  array<string, mixed> $hold
     * @return void
     */
    public static function unlock( array $hold ) {

        Downloads::updater()['unlock']( $hold );
    }

    /**
     * The copy, at the end of the process, then exit. Nothing when nothing
     * is pending. Never returns otherwise.
     *
     * No other run of cli.php may be going when the files change. The
     * marker says waiting, which stops every run that starts, and it waits
     * up to WAIT seconds for those already going to end (updater.php
     * `running`, `alone`); when they have not, it installs nothing, for the
     * next release check to try again.
     *
     * @param  resource|false|null $running this run's, as updater.php `running` gave it
     * @return void
     */
    public static function run( $running = null ) {

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
            'format'      => 1,
            'from'        => $p['from'],
            'to'          => $p['to'],
            'phase'       => 'waiting',
            'since'       => time(),
            'lock'        => $p['hold']['file'],
            'updates'     => $p['updates'],
            'boots'       => 0,
            'log'         => $p['log'] ?? [],
        ];

        try {

            $marker = $u['logged']( $marker, sprintf( 'Waiting up to %d seconds for other runs of cli.php to end.', self::WAIT ) );
            $u['writeMarker']( $root, $marker );
            $alone = $u['alone']( $running, self::WAIT );

            if ( $alone ) {

                // Every file the copy overwrites or deletes, and its SHA-256 in the marker, which the web user cannot write.
                $marker          = $u['backup']( $root, $p['updates'], $p['plan'] ) + $marker;
                $marker          = $u['logged']( $marker, sprintf( 'Backed up the %d files the copy overwrites or deletes in %s.', count( $marker['saved'] ), $marker['backup'] ) );
                $marker['phase'] = 'copying';
                $marker          = $u['logged']( $marker, sprintf( 'Copying OWA %s\'s files over %s\'s.', $p['to'], $p['from'] ) );
                $u['writeMarker']( $root, $marker );
            }

        } catch ( \RuntimeException $e ) {

            @unlink( $u['marker']( $root ) );
            $u['removeBackups']( $p['updates'] );
            $u['recordFailure']( $p['updates'], $marker, $e->getMessage() . ' Nothing was installed.' );
            $u['unlock']( $p['hold'] );
            fwrite( STDERR, sprintf( "Installing OWA %s failed: %s Nothing was installed.\n", $p['to'], $e->getMessage() ) );

            exit( 1 );
        }

        if ( ! $alone ) {

            @unlink( $u['marker']( $root ) );
            $u['unlock']( $p['hold'] );
            $why = sprintf( 'Other runs of cli.php were still going after %d seconds, so OWA %s was not installed; the next release check tries again.', self::WAIT, $p['to'] );
            CoreAPI::notice( $why );

            if ( ! $p['quiet'] ) {

                fwrite( STDERR, $why . "\n" );
            }

            exit( $p['quiet'] ? 0 : 75 );
        }

        // cli.php is let go: the copy may replace it, and Windows renames nothing held open.
        if ( is_resource( $running ) ) {

            flock( $running, LOCK_UN );
            fclose( $running );
        }

        // From here this process loads nothing: whatever it would load is the new release's.
        set_error_handler( null );
        set_exception_handler( null );
        spl_autoload_register( static function ( $class ) {

            throw new \LogicException( sprintf( '%s was to be loaded after the copy began; it is refused, so no process runs two releases at once.', $class ) );
        }, true, true );

        try {

            $u['apply']( $root, $p['to_h'], $p['plan'] );

        } catch ( \Throwable $e ) {

            $why = rtrim( $e->getMessage(), '.' ) . '.';

            try {

                $u['restore']( $root, $marker );
                @unlink( $u['marker']( $root ) );
                $u['recordFailure']( $p['updates'], $marker, sprintf( '%s OWA %s was put back.', $why, $p['from'] ) );
                fwrite( STDERR, sprintf( "Installing OWA %s failed: %s OWA %s was put back.\n", $p['to'], $why, $p['from'] ) );

            } catch ( \Throwable $back ) {

                // The marker stays, saying copying: the next run of cli.php tries again.
                $u['recordFailure']( $p['updates'], $marker, sprintf( '%s Putting OWA %s back failed too: %s', $why, $p['from'], $back->getMessage() ) );
                fwrite( STDERR, sprintf( "Installing OWA %s failed: %s Putting OWA %s back failed too: %s\n", $p['to'], $why, $p['from'], $back->getMessage() ) );
            }

            $u['unlock']( $p['hold'] );

            exit( 1 );
        }

        $marker['phase'] = 'copied';
        $marker          = $u['logged']( $marker, 'Copied. The next run of cli.php starts it.' );

        try {

            $u['writeMarker']( $root, $marker );

        } catch ( \RuntimeException $e ) {

            // Still saying copying, with the lock let go: the next run puts the installed release back, and records why.
            $u['unlock']( $p['hold'] );
            fwrite( STDERR, sprintf( "OWA %s's files are in place, but the install marker could not say so: %s The next run of cli.php puts OWA %s back.\n",
                $p['to'], rtrim( $e->getMessage(), '.' ) . '.', $p['from'] ) );

            exit( 1 );
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
