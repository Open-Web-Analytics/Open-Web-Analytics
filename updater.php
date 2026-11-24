<?php
/**
 * Automatic updates: what runs before OWA loads, and the copy.
 *
 * Plain PHP with no OWA class, no global function, constant or variable: it
 * returns closures, so it can be loaded more than once in a process. Every
 * release ships it.
 *
 *   preboot     cli.php, before OWA loads: what the install marker says to
 *               do. Nothing (boot), wait for the installer, put the
 *               installed release back, or boot the new release and finish
 *               its install (Classes\AutoUpdate\Finish).
 *   apply       the copy: a release's files from its tarball over the OWA
 *               directory, then the files of the release it replaces that it
 *               does not ship, deleted; Core/Env.php, the version, last. The
 *               installer (Classes\AutoUpdate\Install) calls the copy it
 *               loaded before the copy began, and a copy that fails is put
 *               back by the same closure from the installed release's tarball.
 *   serveMaintenance, dropsBeacon
 *               index.php, api/index.php and log.php until the new release
 *               has booted: while the files may be mixed, and until it is
 *               known to boot. Once booted, OWA's pending schema updates
 *               stop what they always stop, and beacons are queued.
 *   updatesDirs, updatesDir
 *               the updates directory, owa-data/updates-<32 hex>/ (Downloads).
 *   tryLock, unlock, isLocked, lockState, stillMine, touchLock
 *               the install lock, owa-data/updating (InstallLock).
 *
 * THE INSTALL MARKER is owa-update.php in the OWA directory, beside
 * owa-config.php, as WordPress puts .maintenance beside wp-config.php: a
 * PHP file that exits, so a request for it shows nothing, then one line of
 * JSON. The installer writes it before the copy, and it is deleted when the
 * new release has finished, or the installed release is back. It is kept in
 * the OWA directory, never the data directory, because whoever can write
 * there can already change OWA's code: the marker is trusted as the code
 * is, however the server's users and directories are arranged.
 *
 *   {format, from, to, phase, since, lock, updates, from_tar, to_tar,
 *    from_sha256, to_sha256, boots}
 *   phase  copying  the files are mixed. With the lock held, the installer
 *                   is copying; with it free, the copy was killed, and the
 *                   next run of cli.php puts the installed release back.
 *          copied   every file is the new release's, and it has not booted.
 *                   boots counts the runs of cli.php that tried; at the third
 *                   the installed release is put back.
 *          booted   the new release booted; Finish has not completed.
 *
 * Putting the installed release back unpacks from_tar, the installed
 * release's tarball, then deletes what to_tar ships and from_tar does not.
 * Each is read once into a private copy and checked against the SHA-256 the
 * marker names, which the installing release proved against the keys it
 * trusts: the data directory, where the tarballs are, is not trusted, and
 * the new release's keys may not be the old one's. to_tar that does not
 * check only means the new release's added files stay. The failure is recorded in the
 * updates directory's last-failure.json: automatic updates skip that
 * release (Downloads).
 *
 * THE FROZEN CONTRACT between an installed release and the next: the
 * marker, written by the installed release and read by the next; cli.php
 * calling preboot; Finish::run(). The marker's fields, like
 * last-failure.json's, are only ever added to.
 */

return ( static function () {

    $marker = static fn ( $root ) => rtrim( (string) $root, '/' ) . '/owa-update.php';

    /* The marker's fields; [] when the file is there and cannot be read; null when there is none. */
    $readMarker = static function ( $root ) use ( $marker ) {

        $raw = @file_get_contents( $marker( $root ) );

        if ( $raw === false ) {

            return file_exists( $marker( $root ) ) ? [] : null;
        }

        $json = substr( $raw, (int) strpos( $raw, "\n" ) + 1 );
        $data = json_decode( $json, true );

        return is_array( $data ) ? $data : [];
    };

    /*
     * A new file, opened with $flags ('x': never one that is there). $path is
     * a name made afresh ($fresh): PHP resolves links in a path before it
     * opens it, so a link the web user put at a name known in advance would
     * be followed, a dangling one to create the file where it points. The
     * file is renamed into place after: rename() replaces a link, never
     * follows it. Made with $mode through the umask, not chmod() after,
     * which follows a link; where umask() is disabled, with the process's
     * own (on Windows it changes nothing, and neither would chmod()).
     */
    $fresh = static function ( $path ) {

        return $path . '-' . bin2hex( random_bytes( 16 ) );
    };

    $createFile = static function ( $path, $mode, $flags = 'xb' ) {

        $umask = function_exists( 'umask' ) ? umask( 0777 & ~ $mode ) : null;
        $h     = @fopen( (string) $path, $flags );

        if ( $umask !== null ) {

            umask( $umask );
        }

        return $h;
    };

    /* Write a file under a fresh name and rename it into place. */
    $writeFile = static function ( $file, $contents, $mode = 0644 ) use ( $createFile, $fresh ) {

        $tmp = $fresh( $file . '.tmp' );
        $h   = $createFile( $tmp, $mode );
        $ok  = $h !== false && @fwrite( $h, $contents ) === strlen( $contents );
        $ok  = $h !== false && fclose( $h ) && $ok;

        if ( ! $ok || ! @rename( $tmp, $file ) ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $file ) );
        }
    };

    /* Whether the copy writes $rel: not owa-data/, the installation's own, which the web user writes too (a new installation unpacks it from the tarball). */
    $copies = static function ( $rel ) {

        return $rel !== 'owa-data' && strncmp( (string) $rel, 'owa-data/', 9 ) !== 0;
    };

    $writeMarker = static function ( $root, array $data ) use ( $marker, $writeFile ) {

        $writeFile( $marker( $root ), "<?php exit; ?>\n" . json_encode( $data, JSON_UNESCAPED_SLASHES ) . "\n" );
    };

    /* A failed install, in the updates directory's last-failure.json (Downloads): automatic updates skip that release. */
    $recordFailure = static function ( $updates, $from, $to, $error, $booted = false ) use ( $writeFile ) {

        $failure = [ 'format' => 1, 'version' => (string) $to, 'from' => (string) $from, 'error' => (string) $error, 'at' => time(), 'booted' => (bool) $booted ];

        try {

            $writeFile( rtrim( (string) $updates, '/' ) . '/last-failure.json', json_encode( $failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

        } catch ( \RuntimeException $e ) {

            fwrite( STDERR, $e->getMessage() . "\n" );
        }
    };

    /* Delete a directory and everything in it; links are removed, not followed. */
    $remove = static function ( $dir ) {

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
    };

    /* Every updates-<32 hex>/ in the data directory, not a link; there should be one at most. */
    $updatesDirs = static function ( $data ) {

        return array_values( array_filter( (array) glob( rtrim( (string) $data, '/' ) . '/updates-*', GLOB_ONLYDIR ),
            static fn ( $d ) => ! is_link( (string) $d ) && (bool) preg_match( '#/updates-[0-9a-f]{32}$#', (string) $d ) ) );
    };

    /*
     * The updates directory, or null: none, or more than one (System Health
     * says so, and nothing installs). With $create, made when there is none:
     * mode 0711, a name mkdir() refuses to reuse, and looked for again after,
     * so it is the only one.
     */
    $updatesDir = static function ( $data, $create = false ) use ( $updatesDirs ) {

        $found = $updatesDirs( $data );

        if ( count( $found ) === 1 ) {

            return (string) $found[0];
        }

        if ( $found || ! $create ) {

            return null;
        }

        $dir = rtrim( (string) $data, '/' ) . '/updates-' . bin2hex( random_bytes( 16 ) );

        // The mode through the umask: chmod() after would follow a link put there in between.
        $umask = function_exists( 'umask' ) ? umask( 0066 ) : null;
        $made  = @mkdir( $dir, 0711 );

        if ( $umask !== null ) {

            umask( $umask );
        }

        if ( ! $made ) {

            return null;
        }
        $found = $updatesDirs( $data );

        return count( $found ) === 1 ? (string) $found[0] : null;
    };

    /*
     * The install lock, owa-data/updating. It exists while a process holds
     * it: the installer from its checks to the end of the copy, Finish, a
     * release being put back, or Step 1 while it writes staged.json or
     * deletes a staging. Its one line says who ("install 2.0.1, pid 4242,
     * since ..."). Whoever finds it held stops and tries again on a later
     * run.
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
     *    between steps (touchLock).
     *
     * A left-over file is taken over by flocking it, if it is still the file
     * named updating (the same inode), and writing the new holder's line into
     * it: it is never missing, so no third process can slip in. A link is
     * never opened: it is deleted, and the lock made new. Without flock()
     * nothing takes a file over atomically: it is moved aside and deleted,
     * and the taker stops if what moved was another process's (put back
     * with link()). The taker then reads the file again a second later and
     * stops if its line is gone, which a third process taking it in that
     * instant would do.
     */
    $lock_stale = 600;

    /* 'free' (no file), 'held', or 'left' (its holder is gone). */
    $lockState = static function ( $file, $no_flock = false ) use ( $lock_stale ) {

        clearstatcache( true, $file );

        if ( is_link( $file ) ) {

            return 'left';
        }

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

        return $time !== false && time() - $time < $lock_stale ? 'held' : 'left';
    };

    $isLocked = static fn ( $data ) => $lockState( rtrim( (string) $data, '/' ) . '/updating' ) === 'held';

    /* Whether the lock file is still this holder's (its token), $wait seconds on. */
    $stillMine = static function ( array $hold, $wait = 0 ) {

        if ( $wait > 0 ) {

            sleep( (int) $wait );
        }

        return ! is_link( (string) $hold['file'] ) && str_contains( (string) @file_get_contents( (string) $hold['file'] ), ', token ' . $hold['token'] );
    };

    /*
     * The lock, or null when another process holds it. $who goes in the file
     * for the operator: "install 2.0.1", "release check". $no_flock: as where
     * the filesystem has none (a test's).
     */
    $tryLock = static function ( $data, $who = 'install', $no_flock = false ) use ( $lockState, $stillMine, $createFile ) {

        $file  = rtrim( (string) $data, '/' ) . '/updating';
        $stale = false;

        switch ( $lockState( $file, $no_flock ) ) {

            case 'held':

                return null;

            case 'left':

                if ( is_link( $file ) ) {

                    // Never followed: whatever it names is no lock.
                    if ( ! @unlink( $file ) ) {

                        return null;
                    }

                    break;
                }

                $token = bin2hex( random_bytes( 8 ) );
                $line  = sprintf( "%s, pid %d, since %s UTC, token %s\n", $who, getmypid(), gmdate( 'Y-m-d H:i:s' ), $token );
                $h     = @fopen( $file, 'r+' );

                if ( $h !== false ) {

                    $would_block = 0;

                    if ( ! $no_flock && flock( $h, LOCK_EX | LOCK_NB, $would_block ) ) {

                        // Taken, if what it flocked is still the file named updating, and no link:
                        // another process may have replaced it in between.
                        clearstatcache( true, $file );
                        $mine = @lstat( $file );
                        $held = fstat( $h );

                        if ( $mine !== false && $held !== false && $mine['ino'] === $held['ino'] && $mine['dev'] === $held['dev'] ) {

                            ftruncate( $h, 0 );
                            rewind( $h );
                            fwrite( $h, $line );
                            fflush( $h );
                            @touch( $file );

                            return [ 'handle' => $h, 'file' => $file, 'line' => $line, 'token' => $token ];
                        }

                        flock( $h, LOCK_UN );
                        fclose( $h );

                        return null;
                    }

                    fclose( $h );

                    if ( ! $no_flock && ( $would_block || PHP_OS_FAMILY === 'Windows' ) ) {

                        return null;
                    }

                    // No flock() here: nothing can take a stale file over atomically.
                    // Moved aside and deleted; put back with link() only, never
                    // over a file another process made meanwhile.
                    $old   = @file_get_contents( $file );
                    $aside = $file . '.left-' . bin2hex( random_bytes( 8 ) );

                    if ( @rename( $file, $aside ) ) {

                        // What moved is another process's new lock: give it back and stop.
                        if ( @file_get_contents( $aside ) !== $old ) {

                            @link( $aside, $file );
                            @unlink( $aside );

                            return null;
                        }

                        @unlink( $aside );
                    }

                    $stale = true;

                } elseif ( file_exists( $file ) ) {

                    return null;
                }
        }

        $token = bin2hex( random_bytes( 8 ) );
        $line  = sprintf( "%s, pid %d, since %s UTC, token %s\n", $who, getmypid(), gmdate( 'Y-m-d H:i:s' ), $token );
        $tmp   = $file . '.new-' . bin2hex( random_bytes( 8 ) );
        $h     = $createFile( $tmp, 0644, 'x+' );

        if ( $h === false ) {

            return null;
        }

        fwrite( $h, $line );
        fflush( $h );

        // A new file: refused only where the filesystem has no flock().
        $flocked = ! $no_flock && flock( $h, LOCK_EX | LOCK_NB );
        $placed  = @link( $tmp, $file );
        @unlink( $tmp );

        // No hard links here: made in place, and flocked straight after.
        if ( ! $placed && ! file_exists( $file ) && ! is_link( $file ) ) {

            fclose( $h );
            $h = $createFile( $file, 0644, 'x+' );

            if ( $h === false ) {

                return null;
            }

            fwrite( $h, $line );
            fflush( $h );
            $flocked = ! $no_flock && flock( $h, LOCK_EX | LOCK_NB );
            $placed  = true;
        }

        if ( ! $placed || ! $flocked ) {

            fclose( $h );
        }

        if ( ! $placed ) {

            return null;
        }

        $hold = [ 'handle' => $flocked ? $h : null, 'file' => $file, 'line' => $line, 'token' => $token ];

        // A stale file taken over without flock(): another process moving
        // files in the same instant could have lost this one. Asked again a
        // moment later; gone means it is another's, left as it is.
        if ( $stale && ! $stillMine( $hold, 1 ) ) {

            return null;
        }

        return $hold;
    };

    /*
     * Delete the file, if it is still this holder's, then let the flock go:
     * the other way round, another process would find it unflocked in between
     * and take it over. Windows cannot delete a file held open, nor read one
     * it has locked, so there it lets go first.
     */
    $unlock = static function ( array $hold ) use ( $stillMine ) {

        $file = (string) $hold['file'];
        $h    = $hold['handle'];

        if ( $h !== null && PHP_OS_FAMILY !== 'Windows' ) {

            if ( $stillMine( $hold ) ) {

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

        if ( $stillMine( $hold ) ) {

            @unlink( $file );
        }
    };

    /* The holder, between steps: the file's time is its last step, and without flock() it keeps the file from going stale. */
    $touchLock = static function ( array $hold ) {

        @touch( (string) $hold['file'] );
    };

    /*
     * The copy: $tar's files over $root, each that differs written beside
     * its target and renamed over it (keeping the target's mode), then each
     * of $old_files the release does not ship deleted, with the directories
     * that leaves empty; Core/Env.php, which says the version, renamed into
     * place last. Nothing in owa-data/ is written or deleted ($copies).
     * $tar is a handle, read from the start; Tar is loaded by the caller.
     * Throws at the first failure, leaving the files mixed.
     *
     * @param resource     $tar
     * @param list<string> $old_files the replaced release's files, relative to $root
     */
    $apply = static function ( $root, $tar, array $old_files ) use ( $copies, $createFile, $fresh ) {

        $root  = rtrim( (string) $root, '/' );
        $files = [];
        $dirs  = [];
        $env   = null;

        if ( $root === '' || ! is_file( $root . '/owa_env.php' ) ) {

            throw new \RuntimeException( sprintf( '%s is not an OWA directory.', $root ) );
        }

        rewind( $tar );

        try {

            \OWA\Module\Base\Classes\AutoUpdate\Tar::each( $tar, static function ( $rel, $type, $mode, $size, $read ) use ( $root, &$files, &$dirs, &$env, $copies, $createFile, $fresh ) {

                if ( ! $copies( $rel ) ) {

                    return;
                }

                $target = $root . '/' . $rel;

                if ( $type === 'dir' ) {

                    $dirs[ $rel ] = true;

                    if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) ) {

                        throw new \RuntimeException( sprintf( 'Could not create %s.', $rel ) );
                    }

                    return;
                }

                $files[ $rel ] = true;

                // Read whole first: a file that is already this one is left as it is,
                // so putting a release back writes only what the copy changed.
                $buf = fopen( 'php://temp/maxmemory:1048576', 'w+b' );
                $read( $buf );
                rewind( $buf );
                $ctx = hash_init( 'sha256' );
                hash_update_stream( $ctx, $buf );
                rewind( $buf );

                $exists = is_file( $target ) && ! is_link( $target );

                if ( $exists && hash_file( 'sha256', $target ) === hash_final( $ctx ) ) {

                    fclose( $buf );

                    return;
                }

                if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {

                    fclose( $buf );

                    throw new \RuntimeException( sprintf( 'Could not create the directory for %s.', $rel ) );
                }

                $tmp = $fresh( $target . '.owa-update' );
                $out = $createFile( $tmp, 0600 );

                if ( $out === false ) {

                    fclose( $buf );

                    throw new \RuntimeException( sprintf( 'Could not write %s.', $rel ) );
                }

                $copied = stream_copy_to_stream( $buf, $out ) === $size;
                fclose( $buf );
                fclose( $out );

                if ( ! $copied ) {

                    @unlink( $tmp );

                    throw new \RuntimeException( sprintf( 'Writing %s failed; the disk may be full.', $rel ) );
                }

                $keep = $exists ? fileperms( $target ) & 0777 : $mode & 0755;

                if ( ! @chmod( $tmp, $keep ) ) {

                    @unlink( $tmp );

                    throw new \RuntimeException( sprintf( 'Could not write %s.', $rel ) );
                }

                if ( $rel === 'Core/Env.php' ) {

                    $env = [ $tmp, $target ];

                    return;
                }

                if ( ! @rename( $tmp, $target ) ) {

                    @unlink( $tmp );

                    throw new \RuntimeException( sprintf( 'Could not write %s.', $rel ) );
                }
            } );

            if ( ! isset( $files['Core/Env.php'] ) ) {

                throw new \RuntimeException( 'The release has no Core/Env.php.' );
            }

            foreach ( $old_files as $rel ) {

                $rel = (string) $rel;

                if ( isset( $files[ $rel ] ) || $rel === '' || str_contains( '/' . $rel . '/', '/../' ) || ! $copies( $rel ) ) {

                    continue;
                }

                $path = $root . '/' . $rel;

                if ( ( is_file( $path ) || is_link( $path ) ) && ! @unlink( $path ) ) {

                    throw new \RuntimeException( sprintf( 'Could not delete %s, which the release does not ship.', $rel ) );
                }

                for ( $dir = dirname( $rel ); $dir !== '.' && ! isset( $dirs[ $dir ] ) && @rmdir( $root . '/' . $dir ); $dir = dirname( $dir ) ) {
                }
            }

            if ( $env !== null && ! @rename( $env[0], $env[1] ) ) {

                throw new \RuntimeException( 'Could not write Core/Env.php.' );
            }

        } catch ( \Throwable $e ) {

            // Core/Env.php, written ahead and kept back, goes with a copy that failed.
            if ( $env !== null ) {

                @unlink( $env[0] );
            }

            throw $e;
        }
    };

    /*
     * Put the installed release back, before OWA loads: from_tar unpacked
     * over the OWA directory once its signature checks, then what to_tar
     * ships and from_tar does not deleted, when to_tar's checks too. Holding
     * the lock. Returns the exit code; the marker goes once it is done.
     */
    $putBack = static function ( $root, array $m, $why ) use ( $tryLock, $unlock, $apply, $marker, $recordFailure ) {

        $from = (string) ( $m['from'] ?? '' );
        $hold = $tryLock( dirname( (string) ( $m['lock'] ?? '' ) ), sprintf( 'putting back %s', $from ) );

        if ( $hold === null ) {

            fwrite( STDERR, "OWA is installing an update; try again in a minute.\n" );

            return 75;
        }

        try {

            if ( ! class_exists( '\\OWA\\Module\\Base\\Classes\\AutoUpdate\\Tar', false ) ) {

                require rtrim( (string) $root, '/' ) . '/modules/Base/Classes/AutoUpdate/Tar.php';
            }

            // Read once into memory or a temporary file only this process holds,
            // hashed as it is read: what is unpacked is what was checked.
            $open = static function ( $tar, $version, $sha256 ) {

                clearstatcache( true, (string) $tar );
                // Never opened unless it is a plain file: opening a FIFO would wait for a writer.
                $in = is_link( (string) $tar ) || ! is_file( (string) $tar ) ? false : @fopen( (string) $tar, 'rb' );
                $st = $in === false ? false : fstat( $in );

                if ( $in === false || $st === false || ( $st['mode'] & 0170000 ) !== 0100000 ) {

                    throw new \RuntimeException( sprintf( '%s is missing, or is not a plain file.', $tar ) );
                }

                $copy = fopen( 'php://temp/maxmemory:1048576', 'w+b' );
                $ctx  = hash_init( 'sha256' );
                $ok   = true;

                while ( $ok && ! feof( $in ) ) {

                    $chunk = fread( $in, 1048576 );
                    $ok    = $chunk !== false && fwrite( $copy, $chunk ) === strlen( $chunk );
                    $ok && hash_update( $ctx, $chunk );
                }

                fclose( $in );
                $actual = hash_final( $ctx );

                // A read or write that failed leaves a copy whose SHA-256 is not the one recorded.
                if ( ! $ok || ! rewind( $copy ) || ! hash_equals( strtolower( (string) $sha256 ), $actual ) ) {

                    fclose( $copy );

                    throw new \RuntimeException( $ok
                        ? sprintf( '%s is not the tarball OWA %s was installed from: its SHA-256 is %s.', $tar, $version, $actual )
                        : sprintf( 'Copying %s failed; the disk holding PHP\'s temporary directory may be full.', $tar ) );
                }

                return $copy;
            };

            $h   = $open( $m['from_tar'] ?? '', $from, $m['from_sha256'] ?? '' );
            $old = [];

            try {

                $old = \OWA\Module\Base\Classes\AutoUpdate\Tar::files( $open( $m['to_tar'] ?? '', (string) ( $m['to'] ?? '' ), $m['to_sha256'] ?? '' ) );

            } catch ( \RuntimeException $e ) {

                // What the new release added stays: nothing proves which files it shipped.
            }

            $apply( $root, $h, $old );
            fclose( $h );

        } catch ( \Throwable $e ) {

            $unlock( $hold );
            // The tarball here may be what failed: the release is fetched again.
            fwrite( STDERR, sprintf( "Putting OWA %s back failed: %s\nOWA cannot run until it is put back by hand: download https://updates.openwebanalytics.com/download/%s/owa_%s_packaged.tar, unpack it over %s as the user the scheduler runs as, then delete %s.\n",
                $from, $e->getMessage(), $from, $from, $root, $marker( $root ) ) );

            return 1;
        }

        @unlink( $marker( $root ) );
        $recordFailure( (string) ( $m['updates'] ?? '' ), $from, (string) ( $m['to'] ?? '' ), sprintf( '%s OWA %s was put back.', $why, $from ) );
        $unlock( $hold );
        fwrite( STDERR, sprintf( "Installing OWA %s failed: %s OWA %s was put back.\n", $m['to'] ?? '', $why, $from ) );

        return 0;
    };

    return [

        'updatesDirs' => $updatesDirs,
        'updatesDir'  => $updatesDir,
        'lock_stale'  => $lock_stale,
        'lockState'   => $lockState,
        'isLocked'    => $isLocked,
        'tryLock'     => $tryLock,
        'stillMine'   => $stillMine,
        'unlock'      => $unlock,
        'touchLock'   => $touchLock,
        'remove'      => $remove,
        'fresh'       => $fresh,
        'createFile'  => $createFile,
        'writeFile'   => $writeFile,
        'copies'      => $copies,
        'apply'       => $apply,
        'marker'      => $marker,
        'readMarker'  => $readMarker,
        'writeMarker' => $writeMarker,
        'recordFailure' => $recordFailure,
        'putBack'     => $putBack,

        /* The installed release's version, read as text from Core/Env.php; null when it is not a release version (a checkout says master). */
        'installedVersion' => static function ( $root ) {

            $env = (string) @file_get_contents( rtrim( (string) $root, '/' ) . '/Core/Env.php' );

            return preg_match( "/const VERSION = '(\d+\.\d+\.\d+)';/", $env, $m ) ? $m[1] : null;
        },

        /*
         * cli.php, before OWA loads. Returns null to boot as usual, an exit
         * code to end with, or ['finish' => marker, 'hold' => lock] to boot
         * and run Finish first, whatever the command.
         */
        'preboot' => static function ( $root, array $argv ) use ( $readMarker, $writeMarker, $lockState, $tryLock, $unlock, $putBack, $marker ) {

            $m = $readMarker( $root );

            if ( $m === null ) {

                return null;
            }

            $quiet = in_array( 'cmd=schedule-run', $argv, true );
            $busy  = static function () use ( $quiet ) {

                if ( ! $quiet ) {

                    fwrite( STDERR, "OWA is installing an update; try again in a minute.\n" );
                }

                return $quiet ? 0 : 75;
            };

            $phase = (string) ( $m['phase'] ?? '' );

            if ( ! in_array( $phase, [ 'copying', 'copied', 'booted' ], true ) ) {

                fwrite( STDERR, sprintf( "%s cannot be read, so OWA does not know whether an update is half installed. If no install is running, delete it.\n", $marker( $root ) ) );

                return 1;
            }

            if ( $phase === 'copying' ) {

                return $lockState( (string) ( $m['lock'] ?? '' ) ) === 'held'
                    ? $busy()
                    : $putBack( $root, $m, 'The copy stopped before it finished.' );
            }

            if ( $phase === 'copied' && (int) ( $m['boots'] ?? 0 ) >= 2 ) {

                return $putBack( $root, $m, sprintf( 'OWA %s did not boot.', $m['to'] ?? '' ) );
            }

            $hold = $tryLock( dirname( (string) ( $m['lock'] ?? '' ) ), sprintf( 'finishing %s', $m['to'] ?? '' ) );

            if ( $hold === null ) {

                return $busy();
            }

            if ( $phase === 'copied' ) {

                $m['boots'] = (int) ( $m['boots'] ?? 0 ) + 1;

                try {

                    $writeMarker( $root, $m );

                } catch ( \RuntimeException $e ) {

                    $unlock( $hold );
                    fwrite( STDERR, $e->getMessage() . "\n" );

                    return 1;
                }
            }

            return [ 'finish' => $m, 'hold' => $hold ];
        },

        /*
         * index.php and api/index.php: answer 503 until the new release has
         * booted, and say so; true when it answered. $rest: the REST API's
         * JSON envelope.
         */
        'serveMaintenance' => static function ( $root, $rest = false ) use ( $readMarker ) {

            $m = $readMarker( $root );

            if ( $m === null || ( $m['phase'] ?? '' ) === 'booted' ) {

                return false;
            }

            // Two hours on, something is wrong: the next run of cli.php says what.
            $stuck = time() - (int) ( $m['since'] ?? time() ) >= 7200;

            http_response_code( 503 );
            header( 'Retry-After: 60' );
            header( 'Cache-Control: no-store' );

            if ( $rest ) {

                header( 'Content-Type: application/json; charset=utf-8' );
                echo json_encode( [
                    'requestId'    => null,
                    'httpResponse' => [ 'status_code' => 503 ],
                    'error'        => [ [
                        'code'     => 'busy',
                        'headline' => 'OWA is updating.',
                        'msg'      => $stuck ? 'The update has not finished for over two hours and may be stuck.' : 'Try again in a minute.',
                    ] ],
                    'data'         => null,
                ] );

                return true;
            }

            header( 'Content-Type: text/html; charset=utf-8' );
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>OWA is updating</title></head><body>'
                . '<p>OWA is updating. This takes a minute or two.</p>'
                . ( $stuck ? '<p>The update has not finished for over two hours and may be stuck. The administrator can see why by running php cli.php cmd=core-update-status.</p>' : '' )
                . '</body></html>';

            return true;
        },

        /* log.php, its response already sent: whether to drop this beacon, because the new release has not booted yet. */
        'dropsBeacon' => static function ( $root ) use ( $readMarker ) {

            $m = $readMarker( $root );

            return $m !== null && ( $m['phase'] ?? '' ) !== 'booted';
        },
    ];
} )();
