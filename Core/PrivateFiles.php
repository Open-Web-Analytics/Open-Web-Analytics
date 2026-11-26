<?php
namespace OWA\Core;

/**
 * Files only this install's code should find: its error log, its tracking
 * intake. owa-data/ is not served (the shipped .htaccess, the install docs
 * for nginx); these are for a server that serves it anyway.
 *
 *  - name(): a file name with the install's hash in it, which nobody can
 *    guess without its secrets (CoreAPI::generateInstanceSpecificHash()). The
 *    hash does not change, so the name is computed, never stored.
 *  - guard(): an index.php in a directory, as owa-data/ and owa-data/logs/
 *    ship with, so a server that lists directories lists nothing there.
 */
class PrivateFiles {

    /** What guard() writes: what owa-data/index.php holds. */
    const INDEX = "<?php\n// ...\n";

    /**
     * @param  string $stem errors, events
     * @param  string $ext  with its dot
     * @return string $stem_<hash>$ext
     */
    public static function name( $stem, $ext ) {

        return (string) $stem . '_' . CoreAPI::generateInstanceSpecificHash() . (string) $ext;
    }

    /**
     * Give $dir an index.php when it has none. A link there is left alone:
     * written through, it would put the file wherever the link points.
     *
     * @param  string $dir
     * @return bool whether $dir has one now
     */
    public static function guard( $dir ) {

        $index = rtrim( (string) $dir, '/' ) . '/index.php';

        if ( is_link( $index ) ) {

            return false;
        }

        if ( is_file( $index ) ) {

            return true;
        }

        try {

            SafeFile::replace( $index, self::INDEX, 0644 );

        } catch ( \RuntimeException $e ) {

            return false;
        }

        return true;
    }
}
