<?php
/**
 * Automatic updates, Steps 2 and 3: the hand-off and the updater.
 *
 * Plain PHP with no OWA class, no global function, constant or variable: it
 * returns closures, so it can be loaded more than once in a process and loads
 * nothing a newer release would have to replace. Every release ships it.
 *
 *   handoff  Step 2. The INSTALLED release's copy, run by cli.php before OWA
 *            loads. Is an install due? Then take the install lock, check that
 *            the staging is the scheduler user's alone and its updater.php is
 *            the one its manifest lists, and run that release's `install`.
 *   install  Step 3. The STAGED release's copy, in the same process. Write
 *            install.json (the claim), back up and copy the release over the
 *            OWA directory (cli.php last), then boot the new OWA and run Step 4
 *            (Classes\AutoUpdate\Finish::run()).
 *   dataDir  Where owa-data/ is, read without OWA: OWA_DATA_DIR written as a
 *            string literal in owa-config.php, or owa-data/ in the OWA
 *            directory. Step 1's preflight refuses an install where this does
 *            not find the directory the booted OWA uses.
 *
 * THE FROZEN CONTRACT between an installed release and the next one is
 * `install`'s: it is given one array (root, updates, version, retry, manual,
 * now, lock) and returns null (boot OWA as if nothing happened) or an exit
 * code. Its fields, like those of staged.json, stage.json, install.json and
 * manifest.json, are only ever added to.
 *
 * Each attempt is counted at the claim, with retry_after set on the back-off
 * then (15 minutes, 2 hours, a day, then weekly), so a process that dies has
 * already waited its turn. A copy error, or a new release that does not boot,
 * is restored at once from backup/: the originals of every file replaced or
 * removed, each backed up once and never overwritten, and backup/added.json,
 * the files the release added, written once before the first copy. After the
 * new OWA has booted (install.json booted: true) nothing is restored.
 */

return [

    'dataDir' => static function ( $root ) {

        $root   = rtrim( (string) $root, '/' );
        $config = @file_get_contents( $root . '/owa-config.php' );

        if ( is_string( $config ) && preg_match( '/^\s*define\s*\(\s*[\'"]OWA_DATA_DIR[\'"]\s*,\s*([\'"])([^\'"]+)\1\s*\)\s*;/m', $config, $m ) ) {

            return rtrim( $m[2], '/' ) . '/';
        }

        return $root . '/owa-data/';
    },

    /* Delete a directory and everything in it; links are removed, not followed. */
    'remove' => static function ( $dir ) {

        if ( is_link( $dir ) || is_file( $dir ) ) {

            return @unlink( $dir );
        }

        if ( ! is_dir( $dir ) ) {

            return true;
        }

        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST );

        foreach ( $it as $path => $info ) {

            $info->isDir() && ! $info->isLink() ? @rmdir( (string) $path ) : @unlink( (string) $path );
        }

        return @rmdir( $dir );
    },

    'backoff' => static function ( $attempts ) {

        return [ 1 => 900, 2 => 7200, 3 => 86400 ][ (int) $attempts ] ?? 604800;
    },

    /*
     * Step 2. Returns null to boot normally, or the exit code to end with.
     *
     * cmd=self-update-install version=<v> is handled here too, before OWA
     * loads, so the new release boots in a process that holds no old code:
     * that version installs now, whatever staged.json says, with fresh
     * attempts.
     */
    'handoff' => static function ( $root, array $argv ) {

        $u     = require __FILE__;
        $root  = rtrim( (string) $root, '/' );
        $only  = null;

        if ( in_array( 'cmd=self-update-install', $argv, true ) ) {

            foreach ( $argv as $arg ) {

                if ( str_starts_with( (string) $arg, 'version=' ) ) {

                    $only = substr( (string) $arg, 8 );
                }
            }

            if ( $only === null || $only === '' ) {

                fwrite( STDERR, "version= names the release to install, such as version=2.0.3.\n" );

                return 2;
            }

            // A checkout is updated with its version control, never by copying a release over it.
            for ( $up = $root; $up !== '' && $up !== '.'; $up = dirname( $up ) === $up ? '' : dirname( $up ) ) {

                foreach ( [ '.git', '.svn', '.hg', '.bzr' ] as $marker ) {

                    if ( @file_exists( $up . '/' . $marker ) ) {

                        fwrite( STDERR, sprintf( "OWA runs from a version control checkout (%s): update it with that.\n", $up . '/' . $marker ) );

                        return 1;
                    }
                }
            }
        }

        $data  = $u['dataDir']( $root );
        $dir   = $data . 'updates';
        $lock  = $data . 'updating';
        $quiet = in_array( 'cmd=schedule-run', $argv, true );

        if ( ! is_dir( $dir ) && $only === null ) {

            return null;
        }

        $h = @fopen( $lock, 'c' );

        if ( $h === false ) {

            return null;
        }

        // Held: an install (or cmd=update) is running. Every other run waits.
        if ( ! flock( $h, LOCK_SH | LOCK_NB ) ) {

            fclose( $h );

            if ( ! $quiet ) {

                fwrite( STDERR, "OWA is installing an update; try again in a minute.\n" );
            }

            return $quiet ? 0 : 75;
        }

        flock( $h, LOCK_UN );

        $now = time();
        $due = $only !== null ? [ 'version' => (string) $only, 'retry' => false ] : $u['due']( $dir, $now );

        if ( $due === null ) {

            fclose( $h );

            return null;
        }

        $version = $due['version'];
        $problem = $u['checkStaging']( $dir, $version );

        if ( $problem === null && $only !== null ) {

            $stage = json_decode( (string) @file_get_contents( $dir . '/' . $version . '/stage.json' ), true );

            if ( empty( $stage['manifest_checked'] ) || empty( $stage['preflight']['passed'] ) ) {

                $problem = sprintf( 'OWA %s is not staged and checked: run cmd=self-update-stage version=%s first.', $version, $version );
            }
        }

        if ( $problem !== null ) {

            fclose( $h );

            if ( $only !== null ) {

                fwrite( STDERR, $problem . "\n" );

                return 1;
            }

            return null;
        }

        if ( ! flock( $h, LOCK_EX | LOCK_NB ) ) {

            fclose( $h );

            return $quiet ? 0 : 75;
        }

        // The lock file's time is the claim's: a lock held two hours is hung.
        @touch( $lock );

        $install = ( require $dir . '/' . $version . '/release/updater.php' )['install'];

        $code = $install( [
            'root'    => $root,
            'updates' => $dir,
            'version' => $version,
            'retry'   => (bool) $due['retry'],
            'manual'  => $only !== null,
            'now'     => $now,
            'lock'    => $h,
        ] );

        if ( $code === null ) {

            flock( $h, LOCK_UN );
            fclose( $h );
        }

        return $code;
    },

    /*
     * Which install is due, or null: a <version>/ whose install.json is
     * installing and past retry_after (a retry, whatever the window; while
     * one waits for its retry, nothing new starts), else the <version>/ that
     * staged.json points at, staged and passed, inside its window.
     */
    'due' => static function ( $dir, $now ) {

        $read = static function ( $file ) {

            $data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            return is_array( $data ) ? $data : null;
        };

        foreach ( (array) glob( $dir . '/*/install.json' ) as $file ) {

            $install = $read( (string) $file );

            if ( ( $install['state'] ?? null ) === 'installing' ) {

                return (int) ( $install['retry_after'] ?? 0 ) <= $now
                    ? [ 'version' => basename( dirname( (string) $file ) ), 'retry' => true ]
                    : null;
            }
        }

        $staged  = $read( $dir . '/staged.json' );
        $version = (string) ( $staged['version'] ?? '' );

        if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {

            return null;
        }

        $stage = $read( $dir . '/' . $version . '/stage.json' );

        if ( empty( $stage['manifest_checked'] ) || empty( $stage['preflight']['passed'] ) || ! empty( $stage['manual_install'] ) ) {

            return null;
        }

        $window = $staged['window'] ?? null;

        if ( is_array( $window ) ) {

            try {

                $tz = new \DateTimeZone( (string) ( $window['timezone'] ?? 'UTC' ) );

            } catch ( \Exception $e ) {

                $tz = new \DateTimeZone( 'UTC' );
            }

            $t     = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
            $hm    = $t->format( 'H:i' );
            $start = (string) ( $window['start'] ?? '00:00' );
            $end   = (string) ( $window['end'] ?? '24:00' );
            $day   = (int) $t->format( 'N' );

            // A window that crosses midnight starts on its day and ends on the next.
            $inside = $start <= $end ? ( $hm >= $start && $hm < $end ) : ( $hm >= $start || $hm < $end );
            $on_day = $start <= $end || $hm >= $start ? $day : ( $day === 1 ? 7 : $day - 1 );

            if ( ! $inside || ! in_array( $on_day, array_map( 'intval', (array) ( $window['days'] ?? [] ) ), true ) ) {

                return null;
            }
        }

        return [ 'version' => $version, 'retry' => false ];
    },

    /*
     * Why the staging may not be run, or null: updates/ and <version>/ owned
     * by this user and writable by no one else, and release/updater.php too,
     * with the SHA-256 its manifest lists.
     */
    'checkStaging' => static function ( $dir, $version ) {

        if ( ! function_exists( 'posix_geteuid' ) ) {

            return 'Automatic updates need PHP\'s posix extension in the CLI.';
        }

        if ( ! preg_match( '/^\d+\.\d+\.\d+$/', (string) $version ) ) {

            return sprintf( '%s is not a release version.', $version );
        }

        $uid     = posix_geteuid();
        $release = $dir . '/' . $version . '/release';
        $updater = $release . '/updater.php';

        foreach ( [ $dir, $dir . '/' . $version, $updater ] as $path ) {

            clearstatcache( true, $path );

            if ( ! file_exists( $path ) ) {

                return sprintf( '%s does not exist: stage the release first.', $path );
            }

            if ( @fileowner( $path ) !== $uid || ( @fileperms( $path ) & 0022 ) ) {

                return sprintf( '%s must be owned by the user running cli.php and writable by no one else.', $path );
            }
        }

        $manifest = json_decode( (string) @file_get_contents( $release . '/manifest.json' ), true );
        $expected = (string) ( $manifest['files']['updater.php']['sha256'] ?? '' );

        if ( $expected === '' || ! hash_equals( $expected, (string) hash_file( 'sha256', $updater ) ) ) {

            return sprintf( '%s does not match its manifest.', $updater );
        }

        return null;
    },

    /*
     * Step 3. Returns null to boot (the old release, after a refusal or a
     * restore) or the exit code once Step 4 has run.
     */
    'install' => static function ( array $c ) {

        $u        = require __FILE__;
        $root     = rtrim( (string) $c['root'], '/' );
        $dir      = $c['updates'] . '/' . $c['version'];
        $release  = $dir . '/release';
        $state    = $dir . '/install.json';
        $backup   = $dir . '/backup';
        $read     = static function ( $file ) {

            $data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            return is_array( $data ) ? $data : null;
        };
        $write    = static function ( $file, array $data ) {

            $tmp = $file . '.tmp' . getmypid();
            file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
            chmod( $tmp, 0600 );
            rename( $tmp, $file );
        };
        $sha      = static fn ( $file ) => is_file( $file ) ? (string) hash_file( 'sha256', $file ) : '';
        $manifest = $read( $release . '/manifest.json' );
        $files    = (array) ( $manifest['files'] ?? [] );
        $previous = $read( $state );

        // A new install: the staging may be hours old, so every file is
        // checked again. A mismatch is a fact about the staging, not an
        // attempt: delete it, and Step 1 stages it again.
        if ( $previous === null ) {

            foreach ( $files as $path => $entry ) {

                if ( isset( $entry['sha256'] ) && ! hash_equals( (string) $entry['sha256'], $sha( $release . '/' . $path ) ) ) {

                    $staged = $read( $c['updates'] . '/staged.json' );

                    if ( ( $staged['version'] ?? null ) === $c['version'] ) {

                        @unlink( $c['updates'] . '/staged.json' );
                    }

                    $u['remove']( $dir );
                    fwrite( STDERR, sprintf( "OWA %s's staging no longer matches its manifest (%s); it is deleted and will be downloaded again.\n", $c['version'], $path ) );

                    return null;
                }
            }
        }

        $attempts = $c['manual'] ? 1 : (int) ( $previous['attempts'] ?? 0 ) + 1;
        $install  = [
            'format'      => 1,
            'state'       => 'installing',
            'version'     => $c['version'],
            'attempts'    => $attempts,
            'claimed_at'  => (int) $c['now'],
            'retry_after' => (int) $c['now'] + $u['backoff']( $attempts ),
            'copied'      => false,
            'booted'      => false,
            'error'       => $previous['error'] ?? null,
        ];
        $write( $state, $install );

        $record = static function ( array $change ) use ( &$install, $write, $state ) {

            $install = $change + $install;
            $write( $state, $install );
        };

        if ( ! is_dir( $backup ) ) {

            mkdir( $backup, 0700 );
        }

        // What the release adds, as it stands before the first copy: written
        // once, so a later attempt (after a dead process) keeps the first
        // attempt's answer rather than what that attempt had already added.
        $added_file = $backup . '/added.json';

        if ( ! is_file( $added_file ) ) {

            $added = array_values( array_filter( array_merge( array_keys( $files ), [ 'manifest.json' ] ),
                static fn ( $p ) => ! file_exists( $root . '/' . $p ) && ! is_link( $root . '/' . $p ) ) );
            $fh    = @fopen( $added_file, 'x' );

            if ( $fh !== false ) {

                fwrite( $fh, json_encode( $added ) );
                fclose( $fh );
            }
        }

        $restore = static function () use ( $root, $backup, $read ) {

            $failed = [];
            $it     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $backup, \FilesystemIterator::SKIP_DOTS ) );

            foreach ( $it as $path => $info ) {

                $rel = substr( (string) $path, strlen( $backup ) + 1 );

                if ( $rel === 'added.json' || ! $info->isFile() ) {

                    continue;
                }

                $target = $root . '/' . $rel;
                @mkdir( dirname( $target ), 0755, true );

                if ( ! @copy( (string) $path, $target . '.restore' ) || ! @chmod( $target . '.restore', fileperms( (string) $path ) & 0777 )
                        || ! @rename( $target . '.restore', $target ) ) {

                    $failed[] = $rel;
                }
            }

            foreach ( (array) $read( $backup . '/added.json' ) as $rel ) {

                if ( is_file( $root . '/' . $rel ) && ! @unlink( $root . '/' . $rel ) ) {

                    $failed[] = (string) $rel;
                }
            }

            return $failed;
        };

        $run = (object) [ 'finished' => false ];

        // A fatal error before the new OWA has booted (a parse error in it, a
        // missing class) ends the process: restore here, from the shutdown.
        register_shutdown_function( static function () use ( &$install, $run, $restore, $record ) {

            if ( $run->finished || ! $install['copied'] ) {

                return;
            }

            $error = error_get_last();

            // Once booted nothing is restored: the schema may have moved on.
            if ( $install['booted'] ) {

                $record( [ 'error' => 'The install stopped: ' . ( $error ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] : 'no error was reported' ) ] );

                return;
            }

            $left  = $restore();
            $record( [ 'copied' => false, 'error' => sprintf( 'The new release stopped before it booted: %s%s',
                $error ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] : 'no error was reported',
                $left ? sprintf( '. Restoring %d file(s) failed, such as %s', count( $left ), $left[0] ) : '' ) ] );
        } );

        if ( function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' ) && function_exists( 'pcntl_async_signals' ) ) {

            pcntl_async_signals( true );
            pcntl_signal( SIGALRM, static function () use ( $record ) {

                $record( [ 'error' => 'The install ran for 24 hours and was stopped.' ] );
                exit( 1 );
            } );
            pcntl_alarm( 86400 );
        }

        // Step 3: back up and copy, cli.php last; then remove what the release no longer has.
        $old   = $read( $root . '/manifest.json' );
        $paths = array_keys( $files );
        usort( $paths, static fn ( $a, $b ) => [ $a === 'cli.php', $a ] <=> [ $b === 'cli.php', $b ] );

        try {

            foreach ( $paths as $path ) {

                $entry  = (array) $files[ $path ];
                $source = $release . '/' . $path;
                $target = $root . '/' . $path;

                if ( ! isset( $entry['sha256'] ) || ( is_file( $target ) && ! is_link( $target ) && $sha( $target ) === $entry['sha256'] ) ) {

                    continue;
                }

                if ( file_exists( $target ) || is_link( $target ) ) {

                    $copy = $backup . '/' . $path;

                    if ( ! file_exists( $copy ) ) {

                        @mkdir( dirname( $copy ), 0700, true );

                        if ( ! @copy( $target, $copy . '.tmp' ) || ! @chmod( $copy . '.tmp', fileperms( $target ) & 0777 ) || ! @rename( $copy . '.tmp', $copy ) ) {

                            throw new \RuntimeException( sprintf( 'Could not back up %s.', $path ) );
                        }
                    }
                }

                if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {

                    throw new \RuntimeException( sprintf( 'Could not create the directory for %s.', $path ) );
                }

                $mode = file_exists( $target ) ? fileperms( $target ) & 0777 : octdec( (string) ( $entry['mode'] ?? '0644' ) ) & 0777;
                $tmp  = $target . '.owa-update';

                if ( ! @copy( $source, $tmp ) || ! @chmod( $tmp, $mode ) || ! @rename( $tmp, $target ) ) {

                    @unlink( $tmp );

                    throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
                }
            }

            // The manifest lists every file but itself: the next install reads
            // this one to know what it removes.
            $copy = $backup . '/manifest.json';

            if ( is_file( $root . '/manifest.json' ) && ! file_exists( $copy ) && ! @copy( $root . '/manifest.json', $copy ) ) {

                throw new \RuntimeException( 'Could not back up manifest.json.' );
            }

            if ( ! @copy( $release . '/manifest.json', $root . '/manifest.json.owa-update' ) || ! @rename( $root . '/manifest.json.owa-update', $root . '/manifest.json' ) ) {

                throw new \RuntimeException( 'Could not write manifest.json.' );
            }

            foreach ( array_keys( (array) ( $old['files'] ?? [] ) ) as $path ) {

                $target = $root . '/' . $path;

                if ( isset( $files[ $path ] ) || ! is_file( $target ) ) {

                    continue;
                }

                $copy = $backup . '/' . $path;

                if ( ! file_exists( $copy ) ) {

                    @mkdir( dirname( $copy ), 0700, true );

                    if ( ! @copy( $target, $copy ) ) {

                        throw new \RuntimeException( sprintf( 'Could not back up %s, which the release removes.', $path ) );
                    }
                }

                if ( ! @unlink( $target ) ) {

                    throw new \RuntimeException( sprintf( 'Could not remove %s, which the release no longer has.', $path ) );
                }
            }

        } catch ( \Throwable $e ) {

            $left = $restore();
            $record( [ 'error' => $e->getMessage() . ( $left ? sprintf( ' Restoring %d file(s) failed, such as %s.', count( $left ), $left[0] ) : ' The previous release was restored.' ) ] );
            fwrite( STDERR, sprintf( "Installing OWA %s failed: %s\n", $c['version'], $install['error'] ) );

            $run->finished = true;

            return null;
        }

        $record( [ 'copied' => true ] );

        // Step 4, in the new code. Nothing of OWA is loaded in this process,
        // so this is the new release as a fresh process would see it.
        require $root . '/owa.php';

        $finish = '\\OWA\\Module\\Base\\Classes\\AutoUpdate\\Finish';
        $code   = (int) $finish::run( $c + [ 'install' => $state, 'record' => $record ] );

        $run->finished = true;

        return $code;
    },
];
