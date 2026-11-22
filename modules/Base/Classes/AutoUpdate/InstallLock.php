<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * owa-data/updating: the install lock, and the maintenance signal.
 *
 * A permanent empty file. The installer (Steps 2-4) holds an exclusive flock
 * on it for an attempt; entry points that find it held serve maintenance.
 * Step 1: release check takes it only for the moment it writes staged.json
 * or deletes a staging, non-blocking, and leaves everything as it is when it
 * is held: an install is running, and Step 1 does not change what it reads.
 */
class InstallLock {

    const FILE = 'updating';

    /** @var string|null a test names its own */
    public static $path = null;

    /** @return string */
    public static function path() {

        return self::$path ?? \OWA\Core\Env::dataDir() . self::FILE;
    }

    /**
     * Whether an install holds the lock now, asked as an entry point asks: a
     * shared lock, not waited for and let go at once, which never looks like
     * an install to anyone else.
     *
     * @return bool
     */
    public static function isHeld() {

        $h = @fopen( self::path(), 'c' );

        if ( $h === false ) {

            return false;
        }

        try {

            $free = flock( $h, LOCK_SH | LOCK_NB );

            if ( $free ) {

                flock( $h, LOCK_UN );
            }

            return ! $free;

        } finally {

            fclose( $h );
        }
    }

    /**
     * Run $fn holding the lock, if it is free now.
     *
     * @param  callable $fn
     * @return bool false when the lock is held and $fn did not run
     */
    public static function tryExclusive( callable $fn ) {

        $h = @fopen( self::path(), 'c' );

        if ( $h === false ) {

            throw new \RuntimeException( sprintf( 'Could not open %s.', self::path() ) );
        }

        try {

            if ( ! flock( $h, LOCK_EX | LOCK_NB ) ) {

                return false;
            }

            try {

                $fn();

                return true;

            } finally {

                flock( $h, LOCK_UN );
            }

        } finally {

            fclose( $h );
        }
    }
}
