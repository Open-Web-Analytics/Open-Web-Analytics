<?php
/**
 * Automatic updates, Steps 2 and 3: the hand-off and the updater.
 *
 * Plain PHP with no OWA class, no global function, constant or variable: it
 * returns closures, so it can be loaded more than once in a process and loads
 * nothing a newer release would have to replace. Every release ships it.
 *
 *   handoff     Step 2. The INSTALLED release's copy, run by cli.php before
 *               OWA loads. Is an install due? Then take the install lock,
 *               check that the staging is the scheduler user's alone, and run
 *               that release's `install`.
 *   install     Step 3. The STAGED release's copy, in the same process. Claim
 *               install/install.json, back up and copy the release over the
 *               OWA directory (cli.php last), delete what it does not ship,
 *               check every file, then boot the new OWA and run Step 4
 *               (Classes\AutoUpdate\Finish::run()).
 *   updatesDir  Where the updates live: owa-data/updates-<32 hex>/, a random
 *               name so the web cannot guess it, found by looking for it,
 *               never derived. Booted OWA reads it through the same closure
 *               (the updates_dir setting); Step 1 creates it.
 *
 * The staged release is its own list of files: there is no manifest. It was
 * proved by the tarball's SHA-256 against GitHub's digest (Step 1), and the
 * live tree is checked against it after the copy.
 *
 * What the release does not ship is deleted, except what belongs to this
 * installation: owa-config.php, owa-data/ and the data directory, the
 * tracking bundles (public/tracker/, or under OWA_PUBLIC_PATH), and module
 * directories that are neither the release's nor a retired one of OWA's own.
 *
 * THE FROZEN CONTRACT between an installed release and the next is
 * `install`'s: one array (root, updates, data, public, version, manual, now,
 * lock) in, null (boot OWA as if nothing happened) or an exit code out. Its
 * fields, like staged.json's and install.json's, are only ever added to.
 *
 * Each attempt is counted at the claim, retry_after set then on the back-off
 * (1, 3 and 10 minutes, 15 minutes, 2 hours, a day, then weekly), so a
 * process that dies retries soon and an install that keeps dying slows
 * down. A copy error, or a new release that does not boot, is undone at once
 * from install/backup/, the originals of every file replaced or deleted, each
 * backed up once. The files the copy added stay until the next attempt's
 * copy, which ships them or deletes them. Once the new OWA has booted
 * (install.json booted: true) nothing is undone.
 */

return [

    /* OWA's own modules that releases no longer ship: deleted, not kept as third-party. */
    'retired_modules' => [ 'FileCache', 'Hello', 'MemcachedCache', 'RemoteQueue' ],

    /*
     * What stops owa-config.php being included before OWA loads: $this, or
     * an OWA class's name as written; null when nothing does. Boot refuses
     * $this too. An OWA class would fail here, where none is loaded, or load
     * the installed release's class into the process that then requires the
     * new release.
     */
    'configRefused' => static function ( $root ) {

        $file = rtrim( (string) $root, '/' ) . '/owa-config.php';

        if ( ! is_file( $file ) ) {

            return null;
        }

        foreach ( (array) @token_get_all( (string) file_get_contents( $file ) ) as $token ) {

            if ( ! is_array( $token ) ) {

                continue;
            }

            if ( $token[0] === T_VARIABLE && $token[1] === '$this' ) {

                return '$this';
            }

            if ( ( $token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NAME_QUALIFIED )
                    && stripos( ltrim( $token[1], '\\' ), 'OWA\\' ) === 0 ) {

                return $token[1];
            }
        }

        return null;
    },

    /*
     * owa-config.php, included as boot includes it (include_once, from a
     * static closure), so boot finds it done; null when configRefused says
     * it cannot be.
     *
     * @return array{data: string, public: string}|null null when it cannot be read
     */
    'config' => static function ( $root ) {

        $root = rtrim( (string) $root, '/' );
        $file = $root . '/owa-config.php';

        if ( is_file( $file ) ) {

            if ( ( require __FILE__ )['configRefused']( $root ) !== null ) {

                return null;
            }

            ( static function ( $__owa_config_file ) {

                include_once $__owa_config_file;
            } )( $file );
        }

        $data   = defined( 'OWA_DATA_DIR' ) && (string) constant( 'OWA_DATA_DIR' ) !== '' ? (string) constant( 'OWA_DATA_DIR' ) : $root . '/owa-data';
        $public = defined( 'OWA_PUBLIC_PATH' ) && (string) constant( 'OWA_PUBLIC_PATH' ) !== '' ? (string) constant( 'OWA_PUBLIC_PATH' ) : $root . '/public';

        return [ 'data' => rtrim( $data, '/' ), 'public' => rtrim( $public, '/' ) ];
    },

    /* Every updates-<32 hex>/ in the data directory; there should be one at most. */
    'updatesDirs' => static function ( $data ) {

        return array_values( array_filter( (array) glob( rtrim( (string) $data, '/' ) . '/updates-*', GLOB_ONLYDIR ),
            static fn ( $d ) => (bool) preg_match( '#/updates-[0-9a-f]{32}$#', (string) $d ) ) );
    },

    /*
     * The updates directory, or null: none, or more than one (System Health
     * says so, and nothing installs). With $create, Step 1 makes it when
     * there is none: mode 0700, a name mkdir() refuses to reuse, and looked
     * for again after, so it is the only one.
     */
    'updatesDir' => static function ( $data, $create = false ) {

        $u     = require __FILE__;
        $found = $u['updatesDirs']( $data );

        if ( count( $found ) === 1 ) {

            return (string) $found[0];
        }

        if ( $found || ! $create ) {

            return null;
        }

        $dir = rtrim( (string) $data, '/' ) . '/updates-' . bin2hex( random_bytes( 16 ) );

        if ( ! @mkdir( $dir, 0700 ) ) {

            return null;
        }

        @chmod( $dir, 0700 );
        $found = $u['updatesDirs']( $data );

        return count( $found ) === 1 ? (string) $found[0] : null;
    },

    /*
     * The install lock, owa-data/updating. It exists while a process holds it:
     * an install for all of its attempt, or Step 1 while it writes staged.json
     * or deletes a staging. Its one line says who ("install 2.0.1, pid 4242,
     * since ..."). Whoever finds it held stops and tries again on a later run:
     * every other CLI run, Step 1, an install, cmd=update by hand, and the
     * entry points.
     *
     * Taken by creating it, which only one process can do (link() of a file
     * made and flocked under a temporary name, which fails when updating
     * exists), and given up by deleting it. A holder that dies leaves it
     * behind, and whether one has is told two ways:
     *  - flock(): the holder flocks it while it runs, and the kernel lets go
     *    when it ends, however it ends. A file nobody flocks is left over.
     *  - where the filesystem has no flock() (it fails without saying another
     *    process holds it; Windows never says, and has flock()): a file not
     *    touched for lock_stale seconds is left over. The holder touches it
     *    between steps (touchLock), not during one statement: a schema update
     *    running longer lets a second attempt start, which the updates-apply
     *    job lease stops at its schema updates.
     * touchLock dates it in both, so its time is the holder's last step. A
     * left-over file is moved aside, and put back if what moved was not it,
     * before the lock is taken.
     */
    'lock_stale' => 600,

    /* 'free' (no file), 'held', or 'left' (its holder is gone). */
    'lockState' => static function ( $file, $no_flock = false ) {

        clearstatcache( true, $file );
        $h = @fopen( $file, 'r' );

        if ( $h === false ) {

            return file_exists( $file ) ? 'held' : 'free';
        }

        // Asked shared, so two runs asking at once never take each other for a holder.
        $would_block = 0;
        $free        = ! $no_flock && flock( $h, LOCK_SH | LOCK_NB, $would_block );

        if ( $free ) {

            flock( $h, LOCK_UN );
        }

        fclose( $h );

        if ( $free ) {

            return 'left';
        }

        if ( ! $no_flock && ( $would_block || PHP_OS_FAMILY === 'Windows' ) ) {

            return 'held';
        }

        $time = @filemtime( $file );

        return $time !== false && time() - $time < ( require __FILE__ )['lock_stale'] ? 'held' : 'left';
    },

    'isLocked' => static function ( $data ) {

        return ( require __FILE__ )['lockState']( rtrim( (string) $data, '/' ) . '/updating' ) === 'held';
    },

    /*
     * The lock, or null when another process holds it. $who goes in the file
     * for the operator: "install", "release check".
     */
    'tryLock' => static function ( $data, $who = 'install' ) {

        $u    = require __FILE__;
        $file = rtrim( (string) $data, '/' ) . '/updating';

        switch ( $u['lockState']( $file ) ) {

            case 'held':

                return null;

            case 'left':

                $old   = @file_get_contents( $file );
                $aside = $file . '.left-' . bin2hex( random_bytes( 8 ) );

                if ( @rename( $file, $aside ) ) {

                    // Another process took it over first: what moved is its lock. Put it back.
                    if ( @file_get_contents( $aside ) !== $old ) {

                        @link( $aside, $file ) || @rename( $aside, $file );
                    }

                    @unlink( $aside );
                }
        }

        $line = sprintf( "%s, pid %d, since %s UTC, %s\n", $who, getmypid(), gmdate( 'Y-m-d H:i:s' ), bin2hex( random_bytes( 8 ) ) );
        $tmp  = $file . '.new-' . bin2hex( random_bytes( 8 ) );
        $h    = @fopen( $tmp, 'x+' );

        if ( $h === false ) {

            return null;
        }

        fwrite( $h, $line );
        fflush( $h );

        // A new file: refused only where the filesystem has no flock().
        $flocked = flock( $h, LOCK_EX | LOCK_NB );
        $placed  = @link( $tmp, $file );
        @unlink( $tmp );

        // No hard links here: made in place, and flocked straight after.
        if ( ! $placed && ! file_exists( $file ) ) {

            fclose( $h );
            $h = @fopen( $file, 'x+' );

            if ( $h === false ) {

                return null;
            }

            fwrite( $h, $line );
            fflush( $h );
            $flocked = flock( $h, LOCK_EX | LOCK_NB );
            $placed  = true;
        }

        if ( ! $placed || ! $flocked ) {

            fclose( $h );
        }

        return $placed ? [ 'handle' => $flocked ? $h : null, 'file' => $file, 'line' => $line ] : null;
    },

    /*
     * Delete the file, if it is still this holder's, then let the flock go:
     * the other way round, another process would find it unflocked in between
     * and take it over. Windows cannot delete a file held open, nor read one
     * it has locked, so there it lets go first.
     */
    'unlock' => static function ( array $hold ) {

        $file = (string) $hold['file'];
        $h    = $hold['handle'];

        if ( $h !== null && PHP_OS_FAMILY !== 'Windows' ) {

            if ( @file_get_contents( $file ) === $hold['line'] ) {

                @unlink( $file );
            }

            flock( $h, LOCK_UN );
            fclose( $h );

            return;
        }

        if ( $h !== null ) {

            flock( $h, LOCK_UN );
            fclose( $h );
        }

        if ( @file_get_contents( $file ) === $hold['line'] ) {

            @unlink( $file );
        }
    },

    /*
     * The holder calls this between steps, whichever way it holds the lock:
     * the file's time is then its last step, so a lock held and not touched
     * for long is an install stuck in one step (its claim is the file's line,
     * and install.json's claimed_at). Without flock() it keeps the file from
     * going stale.
     */
    'touchLock' => static function ( array $hold ) {

        @touch( (string) $hold['file'] );
    },

    'backoff' => static function ( $attempts ) {

        return [ 1 => 60, 2 => 180, 3 => 600, 4 => 900, 5 => 7200, 6 => 86400 ][ (int) $attempts ] ?? 604800;
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

    /*
     * Step 2. Returns null to boot normally, or the exit code to end with.
     *
     * cmd=self-update-install version=<v> is handled here too, before OWA
     * loads, so the new release boots in a process that holds no old code:
     * that version installs now, whatever staged.json says.
     */
    'handoff' => static function ( $root, array $argv ) {

        $u    = require __FILE__;
        $root = rtrim( (string) $root, '/' );
        $only = null;

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
            for ( $up = $root; $up !== ''; $up = dirname( $up ) === $up ? '' : dirname( $up ) ) {

                foreach ( [ '.git', '.svn', '.hg', '.bzr' ] as $marker ) {

                    if ( @file_exists( $up . '/' . $marker ) ) {

                        fwrite( STDERR, sprintf( "OWA runs from a version control checkout (%s): update it with that.\n", $up . '/' . $marker ) );

                        return 1;
                    }
                }
            }
        }

        $config = $u['config']( $root );

        if ( $config === null && $only !== null ) {

            fwrite( STDERR, sprintf( "owa-config.php uses %s. It is read before OWA loads: use plain values there, or install by hand.\n", $u['configRefused']( $root ) ) );

            return 1;
        }

        $dir = $config === null ? null : $u['updatesDir']( $config['data'] );

        if ( $dir === null ) {

            if ( $only !== null ) {

                fwrite( STDERR, "Nothing is staged: run cmd=self-update-stage version=" . $only . " first.\n" );

                return 1;
            }

            return null;
        }

        $data  = $config['data'];
        $quiet = in_array( 'cmd=schedule-run', $argv, true );
        $busy  = static function () use ( $quiet ) {

            if ( ! $quiet ) {

                fwrite( STDERR, "OWA is installing an update; try again in a minute.\n" );
            }

            return $quiet ? 0 : 75;
        };

        // Locked: an install is running, or Step 1 is writing. Every other run waits.
        if ( $u['isLocked']( $data ) ) {

            return $busy();
        }

        // What to install: a version, or the exit code to end with. Decided
        // once without the lock, so a run with nothing due never takes it,
        // and again holding it, since Step 1 may have changed things between.
        $decide = static function () use ( $u, $dir, $only ) {

            $current = json_decode( (string) @file_get_contents( $dir . '/install/install.json' ), true );
            $current = is_array( $current ) ? $current : null;

            if ( $only !== null ) {

                // An unfinished install comes first: it is the one to carry on with.
                $unfinished = (string) ( $current['version'] ?? $only );

                if ( $unfinished !== $only ) {

                    fwrite( STDERR, sprintf( "An install of OWA %s is unfinished; run cmd=self-update-install version=%s first.\n", $unfinished, $unfinished ) );

                    return 1;
                }

                $version = $only;

            } else {

                $version = $u['due']( $dir, time() );
            }

            if ( $version === null ) {

                return null;
            }

            $gone    = ! is_dir( $dir . '/' . $version . '/release' );
            $problem = $gone && $current !== null ? null : $u['checkStaging']( $dir, $version );

            if ( $problem !== null ) {

                if ( $only !== null ) {

                    fwrite( STDERR, $problem . "\n" );

                    return 1;
                }

                return null;
            }

            return [ $version, $gone ];
        };

        $due = $decide();

        if ( ! is_array( $due ) ) {

            return $due;
        }

        $hold = $u['tryLock']( $data, sprintf( 'install %s', $due[0] ) );

        if ( $hold === null ) {

            return $busy();
        }

        $due = $decide();

        if ( ! is_array( $due ) ) {

            $u['unlock']( $hold );

            return $due;
        }

        [ $version, $gone ] = $due;

        $context = [
            'root'    => $root,
            'updates' => $dir,
            'data'    => $data,
            'public'  => $config['public'],
            'version' => $version,
            'manual'  => $only !== null,
            'now'     => time(),
            'lock'    => $hold,
        ];

        // An unfinished install whose staging is gone cannot go on: undo it,
        // and the old release runs. Step 1 stages the release again.
        if ( $gone ) {

            $u['undo']( $context, 'Its staging is gone, so it was undone; it is staged and installed again.' );
            $u['unlock']( $hold );

            return null;
        }

        $code = ( require $dir . '/' . $version . '/release/updater.php' )['install']( $context );

        if ( $code === null ) {

            $u['unlock']( $hold );
        }

        return $code;
    },

    /*
     * Which version is due, or null: the unfinished install
     * (install/install.json) once past its retry_after, whatever the window,
     * and nothing new while one waits; else the release staged.json points
     * at, inside its window.
     */
    'due' => static function ( $dir, $now ) {

        $read = static function ( $file ) {

            $data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            return is_array( $data ) ? $data : null;
        };

        $install = $read( $dir . '/install/install.json' );

        if ( $install !== null ) {

            $v = (string) ( $install['version'] ?? '' );

            return preg_match( '/^\d+\.\d+\.\d+$/', $v ) && (int) ( $install['retry_after'] ?? 0 ) <= $now ? $v : null;
        }

        $staged  = $read( $dir . '/staged.json' );
        $version = (string) ( $staged['version'] ?? '' );

        if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ! is_dir( $dir . '/' . $version . '/release' ) ) {

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

        return $version;
    },

    /*
     * Why the staging may not be run, or null: the updates directory,
     * <version>/ and release/updater.php owned by this user and writable by
     * no one else.
     */
    'checkStaging' => static function ( $dir, $version ) {

        if ( ! function_exists( 'posix_geteuid' ) ) {

            return 'Automatic updates need PHP\'s posix extension in the CLI.';
        }

        if ( ! preg_match( '/^\d+\.\d+\.\d+$/', (string) $version ) ) {

            return sprintf( '%s is not a release version.', $version );
        }

        $uid = posix_geteuid();

        foreach ( [ $dir, $dir . '/' . $version, $dir . '/' . $version . '/release/updater.php' ] as $path ) {

            clearstatcache( true, $path );

            if ( ! file_exists( $path ) ) {

                return sprintf( '%s does not exist: stage the release first.', $path );
            }

            if ( @fileowner( $path ) !== $uid || ( @fileperms( $path ) & 0022 ) ) {

                return sprintf( '%s must be owned by the user running cli.php and writable by no one else.', $path );
            }
        }

        return null;
    },

    /*
     * Put back every file in install/backup/ and record why: the old release
     * runs until the retry.
     *
     * @return list<string> the files that could not be put back
     */
    'undo' => static function ( array $c, $why ) {

        $backup = $c['updates'] . '/install/backup';
        $state  = $c['updates'] . '/install/install.json';
        $failed = [];

        if ( is_dir( $backup ) ) {

            $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $backup, \FilesystemIterator::SKIP_DOTS ) );

            foreach ( $it as $path => $info ) {

                if ( ! $info->isFile() ) {

                    continue;
                }

                $rel    = substr( (string) $path, strlen( $backup ) + 1 );
                $target = rtrim( $c['root'], '/' ) . '/' . $rel;

                @mkdir( dirname( $target ), 0755, true );

                if ( ! @copy( (string) $path, $target . '.owa-undo' ) || ! @chmod( $target . '.owa-undo', fileperms( (string) $path ) & 0777 )
                        || ! @rename( $target . '.owa-undo', $target ) ) {

                    $failed[] = $rel;
                }
            }
        }

        $install = json_decode( (string) @file_get_contents( $state ), true );

        if ( is_array( $install ) ) {

            $install['copied'] = false;
            $install['error']  = $why . ( $failed ? sprintf( ' Putting back %d file(s) failed, such as %s.', count( $failed ), $failed[0] ) : ' The previous release was put back.' );
            $tmp = $state . '.tmp' . getmypid();
            file_put_contents( $tmp, json_encode( $install, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
            chmod( $tmp, 0600 );
            rename( $tmp, $state );
        }

        return $failed;
    },

    /*
     * Step 3. Returns null to boot (the old release, after an undo) or the
     * exit code once Step 4 has run.
     */
    'install' => static function ( array $c ) {

        $u       = require __FILE__;
        $root    = rtrim( (string) $c['root'], '/' );
        $release = $c['updates'] . '/' . $c['version'] . '/release';
        $dir     = $c['updates'] . '/install';
        $state   = $dir . '/install.json';
        $backup  = $dir . '/backup';
        $write   = static function ( $file, array $data ) {

            $tmp = $file . '.tmp' . getmypid();
            file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
            chmod( $tmp, 0600 );
            rename( $tmp, $file );
        };
        $sha     = static fn ( $file ) => is_file( $file ) && ! is_link( $file ) ? (string) hash_file( 'sha256', $file ) : '';

        // The copy deletes what the release does not ship: both trees must be OWA's.
        foreach ( [ $root, $release ] as $tree ) {

            if ( $tree === '' || $tree === '/' || ! is_file( $tree . '/owa_env.php' ) || ! is_file( $tree . '/cli.php' ) ) {

                fwrite( STDERR, sprintf( "%s is not an OWA directory; nothing was installed.\n", $tree ) );

                return 1;
            }
        }

        if ( ! is_dir( $dir ) ) {

            mkdir( $dir, 0700 );
        }

        $previous = json_decode( (string) @file_get_contents( $state ), true );
        $previous = is_array( $previous ) && ( $previous['version'] ?? null ) === $c['version'] ? $previous : null;
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

        // The release's files, relative to its root (Step 1's reader admits
        // plain files and directories only).
        $files = [];
        $dirs  = [];
        $it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $release, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST );

        foreach ( $it as $path => $info ) {

            $rel = substr( (string) $path, strlen( $release ) + 1 );

            if ( $info->isDir() && ! $info->isLink() ) {

                $dirs[ $rel ] = true;

            } elseif ( $info->isFile() && ! $info->isLink() ) {

                $files[ $rel ] = true;
            }
        }

        $modules = [];

        foreach ( array_keys( $dirs ) as $rel ) {

            if ( preg_match( '#^modules/([^/]+)$#', $rel, $m ) ) {

                $modules[ $m[1] ] = true;
            }
        }

        // What belongs to this installation, not to any release: never deleted.
        // owa-data/ by name too: with OWA_DATA_DIR elsewhere it may still hold
        // what was there before the data directory moved.
        $own  = [ $c['data'], $root . '/owa-data', $c['public'] . '/tracker', $c['updates'] ];
        $keep = static function ( $abs, $rel ) use ( $own, $modules, $u ) {

            if ( $rel === 'owa-config.php' ) {

                return true;
            }

            foreach ( $own as $path ) {

                $path = rtrim( (string) $path, '/' );

                if ( $abs === $path || str_starts_with( $abs, $path . '/' ) ) {

                    return true;
                }
            }

            if ( preg_match( '#^modules/([^/]+)(/|$)#', $rel, $m ) ) {

                return ! isset( $modules[ $m[1] ] ) && ! in_array( $m[1], (array) $u['retired_modules'], true );
            }

            return false;
        };

        $backupOnce = static function ( $target, $rel ) use ( $backup ) {

            $copy = $backup . '/' . $rel;

            if ( file_exists( $copy ) ) {

                return;
            }

            @mkdir( dirname( $copy ), 0700, true );

            if ( ! @copy( $target, $copy . '.tmp' ) || ! @chmod( $copy . '.tmp', fileperms( $target ) & 0777 ) || ! @rename( $copy . '.tmp', $copy ) ) {

                throw new \RuntimeException( sprintf( 'Could not back up %s.', $rel ) );
            }
        };

        $run = (object) [ 'finished' => false ];

        // A fatal error before the new OWA has booted (a parse error in it, a
        // missing class) ends the process: undo here, from the shutdown.
        register_shutdown_function( static function () use ( &$install, $run, $u, $c, $record ) {

            if ( $run->finished || ! $install['copied'] ) {

                return;
            }

            $error = error_get_last();
            $what  = $error ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] : 'no error was reported';

            // Once booted nothing is undone: the schema may have moved on.
            if ( $install['booted'] ) {

                $record( [ 'error' => 'The install stopped: ' . $what ] );

                return;
            }

            $u['undo']( $c, 'The new release stopped before it booted: ' . $what . '.' );
        } );

        if ( function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' ) && function_exists( 'pcntl_async_signals' ) ) {

            pcntl_async_signals( true );
            pcntl_signal( SIGALRM, static function () use ( $record ) {

                $record( [ 'error' => 'The install ran for 24 hours and was stopped.' ] );
                exit( 1 );
            } );
            pcntl_alarm( 86400 );
        }

        $paths = array_keys( $files );
        usort( $paths, static fn ( $a, $b ) => [ $a === 'cli.php', $a ] <=> [ $b === 'cli.php', $b ] );

        try {

            // Copy each file that differs, its original backed up first; cli.php last.
            foreach ( $paths as $rel ) {

                $source = $release . '/' . $rel;
                $target = $root . '/' . $rel;

                if ( $sha( $target ) === $sha( $source ) ) {

                    continue;
                }

                if ( file_exists( $target ) || is_link( $target ) ) {

                    $backupOnce( $target, $rel );
                }

                if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {

                    throw new \RuntimeException( sprintf( 'Could not create the directory for %s.', $rel ) );
                }

                $mode = file_exists( $target ) ? fileperms( $target ) & 0777 : fileperms( $source ) & 0777;
                $tmp  = $target . '.owa-update';

                if ( ! @copy( $source, $tmp ) || ! @chmod( $tmp, $mode ) || ! @rename( $tmp, $target ) ) {

                    @unlink( $tmp );

                    throw new \RuntimeException( sprintf( 'Could not write %s.', $rel ) );
                }
            }

            // Delete what the release does not ship and the installation does not own.
            $live = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
                    static function ( \SplFileInfo $f ) use ( $root, $keep ) {

                        $abs = (string) $f->getPathname();

                        return ! $keep( $abs, substr( $abs, strlen( $root ) + 1 ) );
                    } ),
                \RecursiveIteratorIterator::CHILD_FIRST );

            foreach ( $live as $abs => $info ) {

                $rel = substr( (string) $abs, strlen( $root ) + 1 );

                if ( $info->isDir() && ! $info->isLink() ) {

                    // A directory the release does not have, emptied above.
                    if ( ! isset( $dirs[ $rel ] ) ) {

                        @rmdir( (string) $abs );
                    }

                    continue;
                }

                if ( isset( $files[ $rel ] ) ) {

                    continue;
                }

                $backupOnce( (string) $abs, $rel );

                if ( ! @unlink( (string) $abs ) ) {

                    throw new \RuntimeException( sprintf( 'Could not delete %s, which the release does not ship.', $rel ) );
                }
            }

            // Check: every file is the release's.
            foreach ( $paths as $rel ) {

                if ( $sha( $root . '/' . $rel ) !== $sha( $release . '/' . $rel ) ) {

                    throw new \RuntimeException( sprintf( '%s does not match the release after the copy.', $rel ) );
                }
            }

        } catch ( \Throwable $e ) {

            $run->finished = true;
            $u['undo']( $c, $e->getMessage() );
            fwrite( STDERR, sprintf( "Installing OWA %s failed: %s\n", $c['version'], $e->getMessage() ) );

            return null;
        }

        $record( [ 'copied' => true ] );

        if ( isset( $c['lock'] ) && is_array( $c['lock'] ) ) {

            $u['touchLock']( $c['lock'] );
        }

        // Step 4, in the new code. Nothing of OWA is loaded in this process,
        // so this is the new release as a fresh process would see it.
        require $root . '/owa.php';

        $finish = '\\OWA\\Module\\Base\\Classes\\AutoUpdate\\Finish';
        $code   = (int) $finish::run( $c + [ 'install' => $state, 'record' => $record ] );

        $run->finished = true;

        return $code;
    },
];
