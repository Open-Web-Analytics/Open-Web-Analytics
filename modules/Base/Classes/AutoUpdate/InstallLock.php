<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * owa-update-lock.php in the OWA directory, the install lock, for booted
 * code: updater.php's isLocked, tryLock, unlock and touchLock, which say how
 * it works, and why it is in the OWA directory rather than the data
 * directory.
 *
 * It exists while one process holds it, and its line says who: an install
 * from its checks to the end of the copy (Install), Finish, a release being
 * put back (updater.php `preboot`). Whoever finds it held stops and tries
 * again on a later run, cmd=core-update-schema by hand included.
 */
class InstallLock {

    const FILE = 'owa-update-lock.php';

    /** @var string|null a test names its own */
    public static $path = null;

    /**
     * Whether this process holds the lock to finish an install (Finish; the
     * hold preboot took before OWA loaded). Read by cmd=core-update-schema,
     * which runs inside it.
     *
     * @var bool
     */
    public static $held_here = false;

    /** @var array<string, mixed>|null Finish's hold, which it touches as it goes */
    public static $hold = null;

    /** @var string|null the owner of the updates-apply lease Finish's schema updates hold (the marker's schema_lease) */
    public static $schema_lease = null;

    /** @return string */
    public static function path() {

        return self::$path ?? \OWA\Core\Env::root() . '/' . self::FILE;
    }

    /** @return bool whether another process holds the lock now */
    public static function isHeld() {

        return (bool) Downloads::updater()['isLocked']( dirname( self::path() ) );
    }

    /**
     * Finish, between steps: the lock file's time says the install is still
     * moving, and where there is no flock() it keeps it from going stale.
     *
     * @return void
     */
    public static function touch() {

        if ( self::$hold !== null ) {

            Downloads::updater()['touchLock']( self::$hold );
        }
    }
}
