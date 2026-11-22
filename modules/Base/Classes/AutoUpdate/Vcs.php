<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * Whether OWA runs from a version control checkout: a fixed rule, with no
 * setting to override it. Updating a checkout is git's job (or svn's), and
 * copying a release over one would leave its working tree dirty.
 *
 * A .git, .svn, .hg or .bzr, as a directory or a file (a git worktree or
 * submodule has a .git file), in the OWA directory or any directory above it.
 * A parent that cannot be read is passed over.
 */
class Vcs {

    const MARKERS = [ '.git', '.svn', '.hg', '.bzr' ];

    /**
     * @param  string $dir
     * @return string|null the marker found, or null
     */
    public static function checkout( $dir ) {

        $dir = rtrim( (string) ( realpath( (string) $dir ) ?: $dir ), '/' );

        while ( true ) {

            foreach ( self::MARKERS as $marker ) {

                $path = ( $dir === '' ? '' : $dir ) . '/' . $marker;

                if ( @file_exists( $path ) ) {

                    return $path;
                }
            }

            if ( $dir === '' || $dir === '/' ) {

                return null;
            }

            $dir = dirname( $dir );
            $dir = $dir === '/' ? '' : $dir;
        }
    }
}
