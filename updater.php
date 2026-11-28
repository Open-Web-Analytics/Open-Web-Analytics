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
 *   keeps, plan, backup, apply
 *               the copy: what it changes, decided first (plan); every file
 *               it overwrites or deletes backed up (backup); then a release's
 *               files from its tarball over the OWA directory, and in the
 *               directories it ships, the files it does not ship deleted,
 *               but those keeps names; Core/Env.php, the version, last. The
 *               installer (Classes\AutoUpdate\Install) calls the closures it
 *               loaded before the copy began.
 *   restore, removeBackups
 *               a copy that fails, or is killed, is put back from the backup
 *               (restore), at once by the installer or by the next run of
 *               cli.php (preboot).
 *   serveMaintenance, dropsBeacon
 *               index.php, api/index.php and log.php from the copy until
 *               the new release has booted: while the files may be mixed,
 *               and until it is known to boot. Once booted, OWA's pending
 *               schema updates stop what they always stop, and beacons are
 *               queued.
 *   updatesDirs, updatesDir
 *               the updates directory, owa-data/updates-<32 hex>/ (Downloads).
 *   tryLock, unlock, isLocked, lockState, stillMine, touchLock
 *               the install lock, owa-update-lock.php (InstallLock).
 *   running, alone
 *               the shared flock() every run of cli.php holds on cli.php,
 *               which the installer holds alone before it copies.
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
 *   {format, from, to, phase, since, lock, updates, backup, saved, created,
 *    made, boots, schema_lease, finishes}
 *   backup  the backup directory, backup-<32 hex>/ in the updates directory
 *   saved   {path: {sha256, mode}} of each file the copy overwrites or
 *           deletes, as it was backed up; {path: {link}} for a link
 *   created the files the copy writes that were not there
 *   made    the directories the copy makes
 *   phase  waiting  nothing has changed: the installer waits for the runs
 *                   of cli.php already going to end (running), and every
 *                   run that starts stops. With the lock free, the
 *                   installer died waiting, and the marker is deleted.
 *          copying  the files are mixed. With the lock held, the installer
 *                   is copying; with it free, the copy was killed, and the
 *                   next run of cli.php puts the installed release back.
 *          copied   every file is the new release's, and it has not booted.
 *                   boots counts the runs of cli.php that tried; at the third
 *                   the installed release is put back.
 *          booted   the new release booted; Finish has not completed.
 *                   finishes counts the runs of cli.php that ran it again;
 *                   after the third stops, the failure is recorded and the
 *                   marker deleted.
 *                   schema_lease is the owner of the updates-apply lease
 *                   its schema updates hold, so a Finish run again after
 *                   one was killed takes that lease back.
 *
 * Putting the installed release back (restore) reads every backed-up file
 * once into a private copy and checks it against its SHA-256 in the marker
 * before it writes any: the backup is in the data directory, which is not
 * trusted, and the marker is. One that is missing or differs, and nothing
 * is written: the marker stays, and OWA does not run until the installed
 * release is unpacked over it by hand. Then each is written back, what the
 * copy created is deleted, with the directories it made, and the backup
 * goes. The failure is recorded in the updates directory's
 * last-failure.json: automatic updates skip that release (Downloads).
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
     * OWA\Core\SafeFile does the same for booted code; this file loads no class.
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

    /*
     * $file read once into php://temp (memory, then a temporary file only
     * this process holds) while its SHA-256 is taken: [the copy, rewound, its
     * SHA-256]. Only a plain file, no link, at most $max bytes. What is read
     * after is the copy, never the file again, which the web user may
     * rewrite. Downloads::privateCopy() uses it.
     */
    $privateCopy = static function ( $file, $max ) {

        $file = (string) $file;
        $name = basename( $file );
        clearstatcache( true, $file );

        if ( ! file_exists( $file ) && ! is_link( $file ) ) {

            throw new \RuntimeException( sprintf( '%s has not been downloaded.', $name ) );
        }

        // Never opened unless it is a plain file: opening a FIFO would wait for a writer.
        $in = is_link( $file ) || ! is_file( $file ) ? false : @fopen( $file, 'rb' );
        $st = $in === false ? false : fstat( $in );

        if ( $in === false || $st === false || ( $st['mode'] & 0170000 ) !== 0100000 ) {

            if ( $in !== false ) {

                fclose( $in );
            }

            throw new \RuntimeException( sprintf( '%s is not a plain file.', $name ) );
        }

        $copy  = fopen( 'php://temp/maxmemory:1048576', 'w+b' );
        $ctx   = hash_init( 'sha256' );
        $bytes = 0;

        try {

            while ( ! feof( $in ) ) {

                $chunk = fread( $in, 1048576 );

                if ( $chunk === false ) {

                    throw new \RuntimeException( sprintf( 'Reading %s failed.', $name ) );
                }

                $bytes += strlen( $chunk );

                if ( $bytes > $max ) {

                    throw new \RuntimeException( sprintf( '%s is larger than %d MB, which no release is.', $name, $max >> 20 ) );
                }

                hash_update( $ctx, $chunk );

                if ( fwrite( $copy, $chunk ) !== strlen( $chunk ) ) {

                    throw new \RuntimeException( sprintf( 'Copying %s failed; the disk holding PHP\'s temporary directory may be full.', $name ) );
                }
            }

            if ( ! rewind( $copy ) ) {

                throw new \RuntimeException( sprintf( 'The copy of %s cannot be read from the start.', $name ) );
            }

        } catch ( \Throwable $e ) {

            fclose( $in );
            fclose( $copy );

            throw $e;
        }

        fclose( $in );

        return [ $copy, hash_final( $ctx ) ];
    };

    /*
     * Whether the copy writes $rel: not owa-data/, the installation's own,
     * which the web user writes too (a new installation unpacks it from the
     * tarball), nor extensions/, third-party modules no release ships.
     */
    $copies = static function ( $rel ) {

        foreach ( [ 'owa-data', 'extensions' ] as $own ) {

            if ( $rel === $own || strncmp( (string) $rel, $own . '/', strlen( $own ) + 1 ) === 0 ) {

                return false;
            }
        }

        return true;
    };

    $writeMarker = static function ( $root, array $data ) use ( $marker, $writeFile ) {

        $writeFile( $marker( $root ), "<?php exit; ?>\n" . json_encode( $data, JSON_UNESCAPED_SLASHES ) . "\n" );
    };

    /*
     * The install's log: a line for each check and step, in memory, carried
     * from process to process in the install marker (its log), and kept in
     * the failure record, so a failure says where the install stopped. $m with
     * $line added; the last $log_lines are kept.
     */
    $log_lines = 100;

    $logged = static function ( array $m, $line ) use ( $log_lines ) {

        $m['log']   = array_values( (array) ( $m['log'] ?? [] ) );
        $m['log'][] = gmdate( 'Y-m-d H:i:s' ) . ' UTC ' . str_replace( [ "\r", "\n" ], ' ', (string) $line );
        $m['log']   = array_slice( $m['log'], - $log_lines );

        return $m;
    };

    /*
     * A failed install of the marker $m, in the updates directory's
     * last-failure.json (Downloads). Automatic updates try that release again
     * after retry_after, up to $retries attempts, $retry_wait seconds apart;
     * with retry_after null they skip it until a newer release, or an install
     * by hand. Not retried: a release that did not boot ($retry false), and
     * one that booted. attempt is the marker's since: one install recorded
     * twice (the copy failed, and putting back did too) counts once.
     *
     * stage, from the marker's phase: copy (before and during it), boot (the
     * copied release did not start) or finish (it started: Finish, whose step
     * the marker names). A finish failure is a broken install
     * (InstallNotices::describe()).
     */
    $retries    = 3;
    $retry_wait = 21600;

    $recordFailure = static function ( $updates, array $m, $error, $retry = true, $booted = false ) use ( $writeFile, $retries, $retry_wait, $logged ) {

        // The web server can rename the updates directory and put a link in its place.
        if ( (string) $updates === '' || is_link( rtrim( (string) $updates, '/' ) ) ) {

            fwrite( STDERR, "There is no updates directory to record the failure in.\n" );

            return;
        }

        $file  = rtrim( (string) $updates, '/' ) . '/last-failure.json';

        // Read only as the plain file it should be: a FIFO there would hang this run, which holds the lock.
        $was   = is_file( $file ) && ! is_link( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
        $from  = (string) ( $m['from'] ?? '' );
        $to    = (string) ( $m['to'] ?? '' );
        $since = (int) ( $m['since'] ?? 0 );
        $same  = is_array( $was ) && ( $was['version'] ?? null ) === $to && ( $was['from'] ?? null ) === $from && empty( $was['booted'] );
        $tries = $same ? (int) ( $was['attempts'] ?? 1 ) + ( (int) ( $was['attempt'] ?? 0 ) === $since ? 0 : 1 ) : 1;

        // format: raised only when a field changes meaning or goes.
        $phase   = (string) ( $m['phase'] ?? '' );
        $failure = [
            'format'      => 1,
            'version'     => $to,
            'from'        => $from,
            'stage'       => $booted || $phase === 'booted' ? 'finish' : ( $phase === 'copied' ? 'boot' : 'copy' ),
            'step'        => $booted || $phase === 'booted' ? (string) ( $m['step'] ?? 'schema' ) : null,
            'error'       => (string) $error,
            'at'          => time(),
            'booted'      => (bool) $booted,
            'attempts'    => $tries,
            'attempt'     => $since,
            'retry_after' => $retry && ! $booted && $tries < $retries ? time() + $retry_wait : null,
        ];

        $failure['log'] = $logged( $m, 'Failed: ' . $error )['log'];

        try {

            $writeFile( $file, json_encode( $failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

        } catch ( \RuntimeException $e ) {

            fwrite( STDERR, $e->getMessage() . "\n" );
        }
    };

    /* Every updates-<32 hex>/ in the data directory, not a link; there should be one at most. */
    $updatesDirs = static function ( $data ) {

        return array_values( array_filter( (array) glob( rtrim( (string) $data, '/' ) . '/updates-*', GLOB_ONLYDIR ),
            static fn ( $d ) => ! is_link( (string) $d ) && (bool) preg_match( '#/updates-[0-9a-f]{32}$#', (string) $d ) ) );
    };

    /*
     * The updates directory, or null: none, or more than one (System Health
     * says so, and nothing installs). With $create, made when there is none:
     * mode 0755 (the web user lists the downloads for the Updates screen and
     * writes nothing), a name mkdir() refuses to reuse, and looked for again
     * after, so it is the only one.
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
        $umask = function_exists( 'umask' ) ? umask( 0022 ) : null;
        $made  = @mkdir( $dir, 0755 );

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
     * The install lock, owa-update-lock.php in the OWA directory, beside the
     * marker. In the OWA directory, not the data directory: only the cron
     * user takes it, and where the web server runs as another user it cannot
     * make or delete a file there, which is what the copy relies on too. A
     * PHP file that exits first, so a request for it shows nothing. It exists
     * while a process holds
     * it: the installer from its checks to the end of the copy, Finish, or a
     * release being put back. Its one line says who ("install 2.0.1, pid
     * 4242, since ..."). Whoever finds it held stops and tries again on a
     * later run.
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

    /* The lock's name, in the directory tryLock() and isLocked() are given. */
    $lock_name = 'owa-update-lock.php';

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

    $isLocked = static fn ( $dir ) => $lockState( rtrim( (string) $dir, '/' ) . '/' . $lock_name ) === 'held';

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
    /*
     * What the lock file says, after the line that exits when it is
     * requested: who holds it, since when, and the token that tells this
     * holder from a later one.
     */
    $lockLine = static fn ( $who, $token ) => "<?php exit; ?>\n" . sprintf( "%s, pid %d, since %s UTC, token %s\n", $who, getmypid(), gmdate( 'Y-m-d H:i:s' ), $token );

    $tryLock = static function ( $dir, $who = 'install', $no_flock = false ) use ( $lockState, $stillMine, $createFile, $lockLine, $lock_name ) {

        $file  = rtrim( (string) $dir, '/' ) . '/' . $lock_name;
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
                $line  = $lockLine( $who, $token );
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
        $line  = $lockLine( $who, $token );
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
     * Every run of cli.php holds a shared flock() on cli.php until it ends,
     * taken before it reads the marker ($running), and the installer copies
     * only once it holds it alone ($alone): a run that booted the installed
     * release loads its classes as it goes, so none may still be going when
     * they change. The marker, phase waiting, stops every run that starts
     * while the installer waits. Where the filesystem has no flock() nothing
     * is told, and the copy goes ahead.
     *
     * $running: the handle; false while the installer holds it alone; null
     * where there is no flock().
     */
    $running = static function ( $root ) {

        $h = @fopen( rtrim( (string) $root, '/' ) . '/cli.php', 'r' );

        if ( $h === false ) {

            return null;
        }

        $would_block = 0;

        if ( flock( $h, LOCK_SH | LOCK_NB, $would_block ) ) {

            return $h;
        }

        fclose( $h );

        return $would_block || PHP_OS_FAMILY === 'Windows' ? false : null;
    };

    /*
     * Whether this run, holding $h ($running), holds it alone within $wait
     * seconds; held exclusively when it does. True where there is no flock().
     */
    $alone = static function ( $h, $wait ) {

        if ( ! is_resource( $h ) ) {

            return true;
        }

        // Windows locks no file twice, even for one handle: this run's own share goes first.
        flock( $h, LOCK_UN );
        $until = microtime( true ) + (int) $wait;

        do {

            $would_block = 0;

            if ( flock( $h, LOCK_EX | LOCK_NB, $would_block ) ) {

                return true;
            }

            if ( ! $would_block && PHP_OS_FAMILY !== 'Windows' ) {

                return true;
            }

            usleep( 200000 );

        } while ( microtime( true ) < $until );

        return false;
    };

    /*
     * WHAT THE COPY NEVER DELETES. In each top-level directory a release
     * ships but owa-data/, modules/ among them, the copy deletes every file
     * the release does not ship, except these paths, relative to the OWA
     * directory, with all that is under them:
     *
     *   owa-data             the data directory a release ships
     *   extensions           third-party modules, which no release ships
     *   public/tracker       the tracking bundles (TrackerBundle::DIR)
     *   owa-update.php       the install marker
     *   owa-update-lock.php  the install lock
     *   $data_dir            the data directory (OWA_DATA_DIR), when it is in the OWA directory
     *   $public_path/tracker the tracking bundles under OWA_PUBLIC_PATH, when it is
     *
     * Nothing else is deleted: a file at the top of the OWA directory
     * (owa-config.php, .htaccess, an operator's robots.txt or .htpasswd), and
     * a directory the release does not ship (.well-known/). modules/ is OWA's
     * alone: a module directory the release no longer ships goes. Everything
     * else OWA writes as it runs goes in the data directory. tests/UpdaterKeepsTest.php fails when a path OWA
     * writes is not kept.
     *
     * The list of paths, '' when one is the OWA directory itself (nothing is
     * deleted then).
     *
     * @return list<string>
     */
    $keeps = static function ( $root, $data_dir = null, $public_path = null ) {

        $real   = static fn ( $p ) => rtrim( (string) ( @realpath( (string) $p ) ?: $p ), '/' );
        $root   = $real( $root );
        $keeps  = [ 'owa-data', 'extensions', 'public/tracker', 'owa-update.php', 'owa-update-lock.php' ];
        $public = (string) $public_path !== '' ? $real( $public_path ) . '/tracker' : '';

        foreach ( [ (string) $data_dir !== '' ? $real( $data_dir ) : '', $public ] as $dir ) {

            if ( $dir === $root ) {

                $keeps[] = '';

            } elseif ( $dir !== '' && strncmp( $dir, $root . '/', strlen( $root ) + 1 ) === 0 ) {

                $keeps[] = substr( $dir, strlen( $root ) + 1 );
            }
        }

        return array_values( array_unique( $keeps ) );
    };

    /* Whether $keeps names $rel or a directory it is in. */
    $kept = static function ( $rel, array $keeps ) {

        foreach ( $keeps as $k ) {

            if ( $k === '' || $rel === $k || strncmp( (string) $rel, $k . '/', strlen( $k ) + 1 ) === 0 ) {

                return true;
            }
        }

        return false;
    };

    /*
     * What the copy of the release in $tar over $root changes, decided
     * before anything is written:
     *   writes   each file the release ships that is not on disk as it
     *            ships it: missing, or with other contents
     *   deletes  each plain file or link on disk that the release does not
     *            ship, in a top-level directory it ships (not owa-data/),
     *            modules/ among them; not one $keeps names. A link is
     *            deleted, never followed.
     *   dirs     each directory the release ships, its files' included
     *   bytes    the size of the files on disk that writes replaces and
     *            deletes removes: what the backup holds
     * $tar is a handle, read from the start; Tar is loaded by the caller.
     *
     * @param  resource     $tar
     * @param  list<string> $keeps
     * @return array{writes: list<string>, deletes: list<string>, dirs: array<string, true>, bytes: int}
     */
    $plan = static function ( $root, $tar, array $keeps ) use ( $copies, $kept ) {

        $root   = rtrim( (string) $root, '/' );
        $files  = [];
        $dirs   = [];
        $writes = [];
        $bytes  = 0;

        if ( $root === '' || ! is_file( $root . '/owa_env.php' ) ) {

            throw new \RuntimeException( sprintf( '%s is not an OWA directory.', $root ) );
        }

        rewind( $tar );

        \OWA\Module\Base\Classes\AutoUpdate\Tar::each( $tar, static function ( $rel, $type, $mode, $size, $read ) use ( $root, &$files, &$dirs, &$writes, &$bytes, $copies ) {

            if ( ! $copies( $rel ) ) {

                return;
            }

            for ( $dir = $type === 'dir' ? $rel : dirname( $rel ); $dir !== '.' && ! isset( $dirs[ $dir ] ); $dir = dirname( $dir ) ) {

                $dirs[ $dir ] = true;
            }

            if ( $type === 'dir' ) {

                return;
            }

            $files[ $rel ] = true;
            $target        = $root . '/' . $rel;

            if ( is_dir( $target ) && ! is_link( $target ) ) {

                throw new \RuntimeException( sprintf( 'The release ships a file at %s, which is a directory here.', $rel ) );
            }

            $buf = fopen( 'php://temp/maxmemory:1048576', 'w+b' );
            $read( $buf );
            rewind( $buf );
            $ctx = hash_init( 'sha256' );
            hash_update_stream( $ctx, $buf );
            fclose( $buf );

            $plain = is_file( $target ) && ! is_link( $target );

            if ( $plain && hash_file( 'sha256', $target ) === hash_final( $ctx ) ) {

                return;
            }

            $writes[] = $rel;
            $bytes   += $plain ? (int) filesize( $target ) : 0;
        } );

        // The directories the mirror deletes in: each top-level one shipped.
        $tops = [];

        foreach ( array_keys( $dirs ) as $dir ) {

            $tops[ explode( '/', (string) $dir )[0] ] = true;
        }

        $deletes = [];

        foreach ( array_keys( $tops ) as $top ) {

            $top = (string) $top;

            if ( ! $copies( $top ) || $kept( $top, $keeps ) || is_link( $root . '/' . $top ) || ! is_dir( $root . '/' . $top ) ) {

                continue;
            }

            $tree = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator( $root . '/' . $top, \FilesystemIterator::SKIP_DOTS ),
                    static fn ( \SplFileInfo $f ) => ! $kept( substr( (string) $f->getPathname(), strlen( $root ) + 1 ), $keeps ) ),
                \RecursiveIteratorIterator::LEAVES_ONLY );

            foreach ( $tree as $path => $info ) {

                $rel = substr( (string) $path, strlen( $root ) + 1 );

                // A directory left empty is no leaf; a link to one is, and is deleted as a file.
                // A FIFO, socket or device is no file of a release's, and is left as it is.
                if ( isset( $files[ $rel ] ) || isset( $dirs[ $rel ] ) || ( ! $info->isLink() && ! $info->isFile() ) ) {

                    continue;
                }

                $deletes[] = $rel;
                $bytes    += $info->isLink() ? 0 : (int) $info->getSize();
            }
        }

        sort( $deletes );

        return [ 'writes' => $writes, 'deletes' => $deletes, 'dirs' => $dirs, 'bytes' => $bytes ];
    };

    /*
     * Delete the directory $dir and everything in it, following no link:
     * each directory is entered only when it is one, not a link, and, where
     * the posix extension says who runs this, owned by this user. $dir is in
     * the data directory, which the web user can write: it can put a link,
     * or its own directory, in place of the updates directory, and nothing
     * outside this user's own directories is deleted then. Returns whether
     * $dir is gone.
     */
    $removeTree = static function ( $dir ) {

        $me   = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
        $mine = static function ( $path ) use ( $me ) {

            clearstatcache( true, $path );
            $st = @lstat( $path );

            return $st !== false && ( $st['mode'] & 0170000 ) === 0040000 && ( $me === null || (int) $st['uid'] === $me );
        };

        $remove = static function ( $dir ) use ( &$remove, $mine ) {

            if ( ! $mine( $dir ) ) {

                return false;
            }

            foreach ( (array) @scandir( $dir ) as $name ) {

                if ( $name === '.' || $name === '..' || $name === false ) {

                    continue;
                }

                $path = $dir . '/' . $name;

                if ( ! is_link( $path ) && is_dir( $path ) ) {

                    $remove( $path );

                } else {

                    @unlink( $path );
                }
            }

            return @rmdir( $dir );
        };

        $dir = rtrim( (string) $dir, '/' );

        return $dir !== '' && ( ! file_exists( $dir ) && ! is_link( $dir ) || $remove( $dir ) );
    };

    /* Every backup-<32 hex>/ in the updates directory deleted (removeTree): one install's is kept only until it finishes. */
    $removeBackups = static function ( $updates ) use ( $removeTree ) {

        $updates = rtrim( (string) $updates, '/' );

        if ( $updates === '' || is_link( $updates ) ) {

            return;
        }

        foreach ( (array) glob( $updates . '/backup-*' ) as $dir ) {

            if ( preg_match( '#/backup-[0-9a-f]{32}$#', (string) $dir ) ) {

                is_link( (string) $dir ) ? @unlink( (string) $dir ) : $removeTree( (string) $dir );
            }
        }
    };

    /*
     * Before the copy: every file $plan says it overwrites or deletes copied
     * into a new directory, backup-<32 hex>, in the updates directory, and
     * hashed as it is copied; any backup an earlier install left deleted
     * first. Returns what the install marker carries to put it back: backup,
     * saved, created and made (the marker, above).
     *
     * The updates directory is in the data directory, which the web user can
     * write: each directory and file of the backup is made new (mkdir(),
     * fopen() x), so none is written through a link put there, and what is
     * read back is checked against saved, which only the marker holds.
     * Refused before anything is made when the disk holding the updates
     * directory has no room for it. Throws, leaving no backup.
     *
     * @return array{backup: string, saved: array<string, array<string, mixed>>, created: list<string>, made: list<string>}
     */
    $backup = static function ( $root, $updates, array $plan ) use ( $createFile, $removeTree, $removeBackups ) {

        $root    = rtrim( (string) $root, '/' );
        $updates = rtrim( (string) $updates, '/' );

        if ( $updates === '' || is_link( $updates ) || ! is_dir( $updates ) ) {

            throw new \RuntimeException( 'There is no updates directory to back up the files the copy replaces in.' );
        }

        $removeBackups( $updates );

        $free = @disk_free_space( $updates );
        $need = (int) ( $plan['bytes'] ?? 0 ) + 1048576;

        if ( $free !== false && $free < $need ) {

            throw new \RuntimeException( sprintf( 'Backing up the files the copy replaces needs about %1$d MB free on the disk holding %2$s; %3$d MB are.',
                (int) ceil( $need / 1048576 ), $updates, (int) floor( $free / 1048576 ) ) );
        }

        $dir     = $updates . '/backup-' . bin2hex( random_bytes( 16 ) );
        $saved   = [];
        $created = [];
        $made    = [];
        $mkdirs  = [];

        $umask = function_exists( 'umask' ) ? umask( 0077 ) : null;
        $fresh = @mkdir( $dir, 0700 );

        if ( $umask !== null ) {

            umask( $umask );
        }

        if ( ! $fresh ) {

            throw new \RuntimeException( sprintf( 'Could not create %s.', $dir ) );
        }

        try {

            $writes = array_fill_keys( (array) ( $plan['writes'] ?? [] ), true );

            foreach ( array_merge( array_keys( $writes ), (array) ( $plan['deletes'] ?? [] ) ) as $rel ) {

                $rel    = (string) $rel;
                $target = $root . '/' . $rel;

                if ( is_link( $target ) ) {

                    $saved[ $rel ] = [ 'link' => (string) readlink( $target ) ];

                    continue;
                }

                // Not there: one the copy writes is created; one it deletes is gone already.
                if ( ! file_exists( $target ) ) {

                    if ( isset( $writes[ $rel ] ) ) {

                        $created[] = $rel;
                    }

                    continue;
                }

                if ( ! is_file( $target ) ) {

                    throw new \RuntimeException( sprintf( '%s is not a plain file.', $rel ) );
                }

                // Each directory of the backup made new by this process.
                $parts = explode( '/', $rel );
                array_pop( $parts );

                for ( $i = 1, $n = count( $parts ); $i <= $n; $i++ ) {

                    $sub = implode( '/', array_slice( $parts, 0, $i ) );

                    if ( ! isset( $mkdirs[ $sub ] ) ) {

                        if ( ! @mkdir( $dir . '/' . $sub, 0700 ) ) {

                            throw new \RuntimeException( sprintf( 'Could not create %s/%s.', $dir, $sub ) );
                        }

                        $mkdirs[ $sub ] = true;
                    }
                }

                $in  = @fopen( $target, 'rb' );
                $out = $createFile( $dir . '/' . $rel, 0600 );
                $ctx = hash_init( 'sha256' );
                $ok  = $in !== false && $out !== false;

                while ( $ok && ! feof( $in ) ) {

                    $chunk = fread( $in, 1048576 );
                    $ok    = $chunk !== false && fwrite( $out, $chunk ) === strlen( $chunk );

                    if ( $ok ) {

                        hash_update( $ctx, $chunk );
                    }
                }

                $ok = $out !== false && fclose( $out ) && $ok;

                if ( $in !== false ) {

                    fclose( $in );
                }

                if ( ! $ok ) {

                    throw new \RuntimeException( sprintf( 'Backing up %s failed; the disk holding %s may be full.', $rel, $updates ) );
                }

                $saved[ $rel ] = [ 'sha256' => hash_final( $ctx ), 'mode' => fileperms( $target ) & 0777 ];
            }

            foreach ( array_keys( (array) ( $plan['dirs'] ?? [] ) ) as $d ) {

                if ( ! is_dir( $root . '/' . $d ) ) {

                    $made[] = (string) $d;
                }
            }

        } catch ( \Throwable $e ) {

            $removeTree( $dir );

            throw $e;
        }

        return [ 'backup' => $dir, 'saved' => $saved, 'created' => $created, 'made' => $made ];
    };

    /*
     * The copy: $tar's files over $root, each that differs written beside
     * its target and renamed over it (keeping the target's mode), then each
     * of $plan's deletes deleted, with the directories that leaves empty;
     * Core/Env.php, which says the version, renamed into place last. Only
     * what $plan names changes, so only what was backed up: a file that
     * differs and $plan does not write stops the copy. Nothing in owa-data/
     * is written or deleted ($copies): the web user writes there. Everywhere
     * else is OWA's code, which the cron user alone writes where the web
     * server runs as another user. $tar is a handle, read from the start;
     * Tar is loaded by the caller. Throws at the first failure, leaving the
     * files mixed.
     *
     * @param resource                                                $tar
     * @param array{writes: list<string>, deletes: list<string>, ...} $plan
     */
    $apply = static function ( $root, $tar, array $plan ) use ( $copies ) {

        $root   = rtrim( (string) $root, '/' );
        $files  = [];
        $dirs   = [];
        $env    = null;
        $writes = array_fill_keys( (array) ( $plan['writes'] ?? [] ), true );

        if ( $root === '' || ! is_file( $root . '/owa_env.php' ) ) {

            throw new \RuntimeException( sprintf( '%s is not an OWA directory.', $root ) );
        }

        rewind( $tar );

        try {

            \OWA\Module\Base\Classes\AutoUpdate\Tar::each( $tar, static function ( $rel, $type, $mode, $size, $read ) use ( $root, &$files, &$dirs, &$env, $copies, $writes ) {

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

                for ( $dir = dirname( $rel ); $dir !== '.' && ! isset( $dirs[ $dir ] ); $dir = dirname( $dir ) ) {

                    $dirs[ $dir ] = true;
                }

                // Read whole first: a file that is already this one is left as it is.
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

                if ( ! isset( $writes[ $rel ] ) ) {

                    fclose( $buf );

                    throw new \RuntimeException( sprintf( '%s changed after the install was prepared, so it was not backed up.', $rel ) );
                }

                if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {

                    fclose( $buf );

                    throw new \RuntimeException( sprintf( 'Could not create the directory for %s.', $rel ) );
                }

                $tmp = $target . '.owa-update';
                $out = @fopen( $tmp, 'wb' );

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

            foreach ( (array) ( $plan['deletes'] ?? [] ) as $rel ) {

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
     * Put back what the copy changed, from the backup the marker $m names.
     * First every backed-up file is read once into one private copy
     * (php://temp) and checked against its SHA-256 in the marker; when one
     * is missing or differs, nothing is written and the exception lists
     * them. Then each is written back that is not as it was (Core/Env.php
     * last), what the copy created is deleted, with the copy's temporary
     * files and the directories it made, and the backup is deleted. Throws
     * when a file does not check or cannot be written; run again, it
     * carries on from where it stopped. The installer calls it after a copy
     * that failed; preboot after one that was killed, or a release that did
     * not boot.
     */
    $restore = static function ( $root, array $m ) use ( $copies, $removeTree ) {

        $root    = rtrim( (string) $root, '/' );
        $dir     = rtrim( (string) ( $m['backup'] ?? '' ), '/' );
        $saved   = is_array( $m['saved'] ?? null ) ? $m['saved'] : null;
        $created = is_array( $m['created'] ?? null ) ? $m['created'] : [];
        $safe    = static fn ( $rel ) => is_string( $rel ) && $rel !== '' && $rel[0] !== '/' && ! str_contains( $rel, "\0" )
            && ! str_contains( '/' . $rel . '/', '/../' ) && $copies( $rel );

        if ( $saved === null ) {

            throw new \RuntimeException( 'The install marker names no backup to put the files back from.' );
        }

        $copy = fopen( 'php://temp/maxmemory:1048576', 'w+b' );
        $at   = [];
        $bad  = [];

        try {

            foreach ( $saved as $rel => $entry ) {

                $rel = (string) $rel;

                if ( ! $safe( $rel ) || ! is_array( $entry ) ) {

                    throw new \RuntimeException( sprintf( 'The install marker names %s, which is not a path in the OWA directory.', $rel ) );
                }

                if ( isset( $entry['link'] ) ) {

                    continue;
                }

                // Never opened unless it is a plain file: a FIFO would wait for a writer.
                $src = $dir . '/' . $rel;
                clearstatcache( true, $src );
                $in  = $dir === '' || is_link( $src ) || ! is_file( $src ) ? false : @fopen( $src, 'rb' );
                $st  = $in === false ? false : fstat( $in );

                if ( $in === false || $st === false || ( $st['mode'] & 0170000 ) !== 0100000 ) {

                    if ( $in !== false ) {

                        fclose( $in );
                    }

                    $bad[] = $rel;

                    continue;
                }

                $start = ftell( $copy );
                $ctx   = hash_init( 'sha256' );

                while ( ! feof( $in ) ) {

                    $chunk = fread( $in, 1048576 );

                    if ( $chunk === false || fwrite( $copy, $chunk ) !== strlen( $chunk ) ) {

                        fclose( $in );

                        throw new \RuntimeException( sprintf( 'Reading the backup of %s failed; the disk holding PHP\'s temporary directory may be full.', $rel ) );
                    }

                    hash_update( $ctx, $chunk );
                }

                fclose( $in );
                $at[ $rel ] = [ $start, ftell( $copy ) - $start ];

                if ( ! hash_equals( strtolower( (string) ( $entry['sha256'] ?? '' ) ), hash_final( $ctx ) ) ) {

                    $bad[] = $rel;
                }
            }

            if ( $bad ) {

                $shown = array_slice( $bad, 0, 20 );

                throw new \RuntimeException( sprintf( '%1$d of the %2$d files backed up before the copy are missing or changed in %3$s, so nothing was put back: %4$s%5$s.',
                    count( $bad ), count( $saved ), $dir, implode( ', ', $shown ), count( $bad ) > count( $shown ) ? sprintf( ' and %d more', count( $bad ) - count( $shown ) ) : '' ) );
            }

            // Core/Env.php, which says the version, last.
            $order = array_keys( $saved );
            usort( $order, static fn ( $a, $b ) => ( (string) $a === 'Core/Env.php' ) <=> ( (string) $b === 'Core/Env.php' ) );

            foreach ( $order as $rel ) {

                $rel    = (string) $rel;
                $entry  = $saved[ $rel ];
                $target = $root . '/' . $rel;

                if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {

                    throw new \RuntimeException( sprintf( 'Could not create the directory for %s.', $rel ) );
                }

                if ( isset( $entry['link'] ) ) {

                    if ( is_link( $target ) && readlink( $target ) === (string) $entry['link'] ) {

                        continue;
                    }

                    if ( ( is_file( $target ) || is_link( $target ) ) && ! @unlink( $target ) || ! @symlink( (string) $entry['link'], $target ) ) {

                        throw new \RuntimeException( sprintf( 'Could not put back the link %s.', $rel ) );
                    }

                    continue;
                }

                if ( is_file( $target ) && ! is_link( $target ) && hash_file( 'sha256', $target ) === strtolower( (string) $entry['sha256'] ) ) {

                    continue;
                }

                [ $start, $length ] = $at[ $rel ];
                $tmp = $target . '.owa-update';
                @unlink( $tmp );
                $out = @fopen( $tmp, 'xb' );
                $ok  = $out !== false && fseek( $copy, $start ) === 0 && stream_copy_to_stream( $copy, $out, $length ) === $length;
                $ok  = $out !== false && fclose( $out ) && $ok;

                if ( ! $ok || ! @chmod( $tmp, (int) ( $entry['mode'] ?? 0644 ) & 0777 ) || ! @rename( $tmp, $target ) ) {

                    @unlink( $tmp );

                    throw new \RuntimeException( sprintf( 'Could not write %s.', $rel ) );
                }
            }

        } finally {

            fclose( $copy );
        }

        foreach ( $created as $rel ) {

            if ( ! $safe( $rel ) ) {

                continue;
            }

            $target = $root . '/' . $rel;

            if ( ( is_file( $target ) || is_link( $target ) ) && ! @unlink( $target ) ) {

                throw new \RuntimeException( sprintf( 'Could not delete %s, which the copy created.', $rel ) );
            }
        }

        // What a copy killed part-way left beside its targets.
        foreach ( array_merge( array_keys( $saved ), $created ) as $rel ) {

            if ( $safe( (string) $rel ) && is_file( $root . '/' . $rel . '.owa-update' ) ) {

                @unlink( $root . '/' . $rel . '.owa-update' );
            }
        }

        $made = array_filter( (array) ( $m['made'] ?? [] ), $safe );
        usort( $made, static fn ( $a, $b ) => substr_count( (string) $b, '/' ) <=> substr_count( (string) $a, '/' ) );

        foreach ( $made as $d ) {

            @rmdir( $root . '/' . $d );
        }

        if ( $dir !== '' ) {

            $removeTree( $dir );
        }
    };

    /*
     * Put the installed release back, before OWA loads, from the backup
     * (restore), holding the lock. Returns the exit code; the marker goes
     * once it is done. When it cannot, the marker stays, so OWA does not
     * run on the mixed files, and the failure is recorded.
     */
    $putBack = static function ( $root, array $m, $why, $retry = true ) use ( $tryLock, $unlock, $restore, $marker, $recordFailure ) {

        $from = (string) ( $m['from'] ?? '' );
        $hold = $tryLock( dirname( (string) ( $m['lock'] ?? '' ) ), sprintf( 'putting back %s', $from ) );

        if ( $hold === null ) {

            fwrite( STDERR, "OWA is installing an update; try again in a minute.\n" );

            return 75;
        }

        try {

            $restore( $root, $m );

        } catch ( \Throwable $e ) {

            $recordFailure( (string) ( $m['updates'] ?? '' ), $m, sprintf( '%s Putting OWA %s back failed: %s', $why, $from, $e->getMessage() ), $retry );
            $unlock( $hold );
            fwrite( STDERR, sprintf( "Putting OWA %s back failed: %s\nOWA cannot run until it is put back by hand: download https://updates.openwebanalytics.com/download/%s/owa_%s_packaged.tar, unpack it over %s as the user the scheduler runs as, then delete %s.\n",
                $from, $e->getMessage(), $from, $from, $root, $marker( $root ) ) );

            return 1;
        }

        @unlink( $marker( $root ) );
        $recordFailure( (string) ( $m['updates'] ?? '' ), $m, sprintf( '%s OWA %s was put back.', $why, $from ), $retry );
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
        'running'     => $running,
        'alone'       => $alone,
        'copies'      => $copies,
        'keeps'       => $keeps,
        'kept'        => $kept,
        'plan'        => $plan,
        'backup'      => $backup,
        'apply'       => $apply,
        'restore'     => $restore,
        'removeTree'  => $removeTree,
        'removeBackups' => $removeBackups,
        'marker'      => $marker,
        'readMarker'  => $readMarker,
        'logged'      => $logged,
        'writeMarker' => $writeMarker,
        'recordFailure' => $recordFailure,
        'retries'     => $retries,
        'putBack'     => $putBack,
        'privateCopy' => $privateCopy,

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
        'preboot' => static function ( $root, array $argv ) use ( $logged, $readMarker, $writeMarker, $lockState, $tryLock, $unlock, $putBack, $marker, $recordFailure, $removeBackups ) {

            $m = $readMarker( $root );

            if ( $m === null ) {

                return null;
            }

            // cmd=schedule-run as the cron entry writes it, or --cmd=schedule-run, which cli.php accepts too.
            $quiet = (bool) preg_grep( '/^-*cmd=schedule-run$/', array_map( 'strval', $argv ) );
            $busy  = static function () use ( $quiet ) {

                if ( ! $quiet ) {

                    fwrite( STDERR, "OWA is installing an update; try again in a minute.\n" );
                }

                return $quiet ? 0 : 75;
            };

            $phase = (string) ( $m['phase'] ?? '' );

            if ( ! in_array( $phase, [ 'waiting', 'copying', 'copied', 'booted' ], true ) ) {

                fwrite( STDERR, sprintf( "%s cannot be read, so OWA does not know whether an update is half installed. If no install is running, delete it.\n", $marker( $root ) ) );

                return 1;
            }

            // Nothing has changed: an installer that died waiting leaves only the marker.
            if ( $phase === 'waiting' ) {

                if ( $lockState( (string) ( $m['lock'] ?? '' ) ) === 'held' ) {

                    return $busy();
                }

                @unlink( $marker( $root ) );

                return null;
            }

            if ( $phase === 'copying' ) {

                return $lockState( (string) ( $m['lock'] ?? '' ) ) === 'held'
                    ? $busy()
                    : $putBack( $root, $m, 'The copy stopped before it finished.' );
            }

            if ( $phase === 'copied' && (int) ( $m['boots'] ?? 0 ) >= 2 ) {

                return $putBack( $root, $m, sprintf( 'OWA %s did not boot.', $m['to'] ?? '' ), false );
            }

            $hold = $tryLock( dirname( (string) ( $m['lock'] ?? '' ) ), sprintf( 'finishing %s', $m['to'] ?? '' ) );

            if ( $hold === null ) {

                return $busy();
            }

            // Booted: Finish ran and died. Run again twice; then the failure is
            // recorded, the marker and the backup go, and OWA runs with its
            // schema updates pending, as after any failed schema update.
            if ( $phase === 'booted' && (int) ( $m['finishes'] ?? 0 ) >= 2 ) {

                $error = sprintf( 'Finishing the install of OWA %s stopped three times before it completed.', $m['to'] ?? '' );
                $recordFailure( (string) ( $m['updates'] ?? '' ), $m, $error, false, true );
                @unlink( $marker( $root ) );
                $removeBackups( (string) ( $m['updates'] ?? '' ) );
                $unlock( $hold );
                fwrite( STDERR, $error . "\n" );

                return null;
            }

            $count       = $phase === 'copied' ? 'boots' : 'finishes';
            $m[ $count ] = (int) ( $m[ $count ] ?? 0 ) + 1;
            $m           = $logged( $m, sprintf( $count === 'boots' ? 'Starting OWA %s (try %d of 2).' : 'Finishing the install of OWA %s again (try %d of 2).',
                $m['to'] ?? '', $m[ $count ] ) );

            try {

                $writeMarker( $root, $m );

            } catch ( \RuntimeException $e ) {

                $unlock( $hold );
                fwrite( STDERR, $e->getMessage() . "\n" );

                return 1;
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

            if ( $m === null || in_array( $m['phase'] ?? '', [ 'waiting', 'booted' ], true ) ) {

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

            return $m !== null && ! in_array( $m['phase'] ?? '', [ 'waiting', 'booted' ], true );
        },
    ];
} )();
