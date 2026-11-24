<?php
namespace OWA\Core;

/**
 * Writing files in a directory another user can write, such as owa-data/
 * where the web server runs as a user other than the cron user's: no write
 * follows a link that user put at the name to a file of the writer's.
 *
 * PHP resolves links in a path before it opens it, a dangling one too, so
 * opening a name that user knows writes (or creates) wherever the link
 * points; 'x' refuses only a file that is already at the end of the link.
 * So:
 *  - a new file is made under a name made afresh (fresh()), which no link
 *    can be waiting at, then moved into place: rename() and link() act on
 *    the name, never on what a link there points to;
 *  - a file that is there is opened, then checked to be the plain file the
 *    name holds (append());
 *  - a mode is set through the umask as the file or directory is made
 *    (create(), makeDir()), never by chmod() after, which follows a link.
 *
 * updater.php has its own closures for this: it runs before OWA loads, and
 * loads no class. On Windows the umask and the inode check change nothing,
 * and links need privileges there.
 */
class SafeFile {

    /**
     * @param  string $path
     * @return string $path with a random suffix
     */
    public static function fresh( $path ) {

        return $path . '-' . bin2hex( random_bytes( 16 ) );
    }

    /**
     * A new file, never one that is there. $path is one fresh() made.
     *
     * @param  string $path
     * @param  int    $mode
     * @param  string $flags an fopen() mode with x
     * @return resource|false
     */
    public static function create( $path, $mode, $flags = 'xb' ) {

        return self::withUmask( $mode, static fn () => @fopen( (string) $path, $flags ) );
    }

    /**
     * A new directory with $mode, whatever the umask: set as it is made,
     * never by chmod() after, which follows a link put there in between.
     * mkdir() itself refuses a name that is there, a link included.
     *
     * @param  string $dir
     * @param  int    $mode
     * @return bool whether it was made
     */
    public static function makeDir( $dir, $mode ) {

        return self::withUmask( $mode, static fn () => @mkdir( (string) $dir, $mode ) );
    }

    /**
     * $path's contents replaced whole: written under a fresh name and renamed
     * over it.
     *
     * @param  string $path
     * @param  string $contents
     * @param  int    $mode
     * @return void
     * @throws \RuntimeException
     */
    public static function replace( $path, $contents, $mode = 0644 ) {

        $tmp = self::fresh( $path . '.tmp' );

        if ( ! self::writeNew( $tmp, $contents, $mode ) || ! @rename( $tmp, $path ) ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }
    }

    /**
     * $data appended to $path, under an exclusive lock so lines from many
     * processes never interleave; $path made with $mode when it is not
     * there. Checked under the lock, so a file a reader renames away (a
     * rotation) between the open and the lock is not written: the next try
     * writes to the file at $path then. False, and nothing written, when
     * $path is not a plain file of its own (a link, a hard link to another
     * file, a FIFO) or cannot be written.
     *
     * @param  string $path
     * @param  string $data
     * @param  int    $mode
     * @return bool
     */
    public static function append( $path, $data, $mode = 0664 ) {

        $path = (string) $path;
        $data = (string) $data;

        for ( $try = 0; $try < 5; $try++ ) {

            if ( ! file_exists( $path ) && ! is_link( $path ) && self::place( $path, $data, $mode ) ) {

                return true;
            }

            // Not opened unless it is a plain file: a FIFO would block the open.
            if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {

                return false;
            }

            $h = is_file( $path ) ? @fopen( $path, 'ab' ) : null;

            if ( $h === false ) {

                return false;
            }

            if ( $h === null ) {

                continue;
            }

            flock( $h, LOCK_EX );
            $named = self::isNamed( $h, $path );
            $ok    = $named && @fwrite( $h, $data ) === strlen( $data );
            flock( $h, LOCK_UN );
            fclose( $h );

            if ( $named ) {

                return $ok;
            }
        }

        return false;
    }

    /**
     * Whether $h, opened by $path, is the plain file $path names now, and
     * its only name: a link put there before the open, or swapped back
     * after, is told by the inode, as is a file renamed away; a hard link to
     * another file by its count (place() gives a new file two names for an
     * instant: append() tries again).
     *
     * @param  resource $h
     * @param  string   $path
     * @return bool
     */
    private static function isNamed( $h, $path ) {

        $open = @fstat( $h );
        clearstatcache( true, $path );
        $named = @lstat( $path );

        return is_array( $open ) && is_array( $named )
            && ( $open['mode'] & 0170000 ) === 0100000
            && $open['nlink'] === 1
            && $open['dev'] === $named['dev']
            && $open['ino'] === $named['ino'];
    }

    /**
     * A new $path holding $data: written under a fresh name, then given
     * $path with link(), which fails when anything is there, or, where the
     * filesystem has no hard links, with rename() while nothing is. False
     * when $path is there by then: the caller appends to it.
     *
     * @param  string $path
     * @param  string $data
     * @param  int    $mode
     * @return bool
     */
    private static function place( $path, $data, $mode ) {

        $tmp = self::fresh( $path . '.new' );

        if ( ! self::writeNew( $tmp, $data, $mode ) ) {

            @unlink( $tmp );

            return false;
        }

        $placed = @link( $tmp, $path );

        if ( $placed ) {

            @unlink( $tmp );

            return true;
        }

        $placed = ! file_exists( $path ) && ! is_link( $path ) && @rename( $tmp, $path );

        if ( ! $placed ) {

            @unlink( $tmp );
        }

        return $placed;
    }

    /**
     * @template T
     * @param  int          $mode
     * @param  callable(): T $make
     * @return T what $make made, under the umask that gives $mode; the
     *           process's own where umask() is disabled
     */
    private static function withUmask( $mode, callable $make ) {

        $umask = function_exists( 'umask' ) ? umask( 0777 & ~ $mode ) : null;

        try {

            return $make();

        } finally {

            if ( $umask !== null ) {

                umask( $umask );
            }
        }
    }

    /**
     * @param  string $tmp a name fresh() made
     * @param  string $contents
     * @param  int    $mode
     * @return bool
     */
    private static function writeNew( $tmp, $contents, $mode ) {

        $h = self::create( $tmp, $mode );

        if ( $h === false ) {

            return false;
        }

        $ok = @fwrite( $h, $contents ) === strlen( $contents );

        return fclose( $h ) && $ok;
    }
}
