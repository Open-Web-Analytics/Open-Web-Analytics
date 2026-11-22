<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * owa-data/updating, the install lock, for booted code: updater.php's
 * isLocked, tryLock, unlock and touchLock, which say how it works.
 *
 * It exists while one process holds it, and its line says who: an install
 * for all of its attempt, or Step 1: release check while it writes
 * staged.json or deletes a staging. Whoever finds it held stops and tries
 * again on a later run, cmd=update by hand included.
 */
class InstallLock {

    const FILE = 'updating';

    /** @var string|null a test names its own */
    public static $path = null;

    /**
     * Whether this process holds the lock: the installer (updater.php took it
     * before OWA loaded). Read by cmd=update, which runs inside the install.
     *
     * @var bool
     */
    public static $held_here = false;

    /** @var array<string, mixed>|null the installer's hold, which it touches as it goes */
    public static $hold = null;

    /** @return string */
    public static function path() {

        return self::$path ?? \OWA\Core\Env::dataDir() . self::FILE;
    }

    /** @return bool whether another process holds the lock now */
    public static function isHeld() {

        return (bool) Staging::updater()['isLocked']( dirname( self::path() ) );
    }

    /**
     * Run $fn holding the lock, if it is free now.
     *
     * @param  callable $fn
     * @param  string   $who what the lock file says holds it
     * @return bool false when the lock is held and $fn did not run
     */
    public static function tryExclusive( callable $fn, $who = 'release check' ) {

        $u    = Staging::updater();
        $hold = $u['tryLock']( dirname( self::path() ), $who );

        if ( $hold === null ) {

            return false;
        }

        try {

            $fn();

            return true;

        } finally {

            $u['unlock']( $hold );
        }
    }

    /**
     * The installer, between steps: the lock file's time says the install is
     * still moving, and where there is no flock() it keeps it from going stale.
     *
     * @return void
     */
    public static function touch() {

        if ( self::$hold !== null ) {

            Staging::updater()['touchLock']( self::$hold );
        }
    }
}
