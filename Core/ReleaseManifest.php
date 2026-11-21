<?php
namespace OWA\Core;

/**
 * Every file a release ships, with its SHA-256, size and mode: manifest.json,
 * which the Release Packager writes from the unpacked tarball
 * (cmd=release-manifest) and adds to it. A release check verifies a staged
 * release against it; an install compares it with the files on disk to see
 * what was changed locally and what a release removes.
 *
 * Paths are relative to the release root, with forward slashes, sorted. A
 * symbolic link is listed with its target, not followed. manifest.json is not
 * in its own list, and neither is what an install adds and no release ships:
 * owa-config.php and owa-data/ (NOT_RELEASED).
 *
 * Boot-free: files only.
 *
 * @phpstan-type FileEntry array{sha256: string, size: int, mode: string}|array{link: string, mode: string}
 * @phpstan-type Manifest array{format: int, files: array<string, FileEntry>}
 */
class ReleaseManifest {

    /** manifest.json's format: raised when a field changes meaning or goes. */
    const FORMAT = 1;

    /** Its own name, at the release root. */
    const FILE = 'manifest.json';

    /** What an install holds that no release ships: never listed, never read. */
    const NOT_RELEASED = [ 'owa-config.php', 'owa-data' ];

    /**
     * @param  string $root the release's top directory
     * @return Manifest
     */
    public static function build( $root ) {

        $root  = rtrim( (string) $root, '/' );
        $files = [];

        $dirs = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
            static fn ( \SplFileInfo $f, $path ) => ! ( dirname( (string) $path ) === $root
                && in_array( $f->getFilename(), self::NOT_RELEASED, true ) ) );

        $it = new \RecursiveIteratorIterator( $dirs, \RecursiveIteratorIterator::SELF_FIRST );

        foreach ( $it as $path => $info ) {

            /** @var \SplFileInfo $info */
            $relative = substr( (string) $path, strlen( $root ) + 1 );

            if ( $relative === self::FILE ) {

                continue;
            }

            $mode = sprintf( '%04o', fileperms( (string) $path ) & 07777 );

            if ( $info->isLink() ) {

                $files[ $relative ] = [ 'link' => (string) readlink( (string) $path ), 'mode' => $mode ];

            } elseif ( $info->isFile() ) {

                $files[ $relative ] = [
                    'sha256' => (string) hash_file( 'sha256', (string) $path ),
                    'size'   => (int) $info->getSize(),
                    'mode'   => $mode,
                ];
            }
        }

        ksort( $files, SORT_STRING );

        return [ 'format' => self::FORMAT, 'files' => $files ];
    }

    /**
     * How a tree differs from a manifest: files it lists that are missing or
     * different, and files on disk it does not list.
     *
     * @param  Manifest $manifest
     * @param  string   $root
     * @return array{missing: list<string>, changed: list<string>, unlisted: list<string>}
     */
    public static function compare( array $manifest, $root ) {

        $actual = self::build( $root )['files'];
        $listed = (array) ( $manifest['files'] ?? [] );
        $out    = [ 'missing' => [], 'changed' => [], 'unlisted' => [] ];

        foreach ( $listed as $path => $entry ) {

            if ( ! isset( $actual[ $path ] ) ) {

                $out['missing'][] = (string) $path;

            } elseif ( self::content( (array) $entry ) !== self::content( $actual[ $path ] ) ) {

                $out['changed'][] = (string) $path;
            }
        }

        $out['unlisted'] = array_values( array_map( 'strval', array_keys( array_diff_key( $actual, $listed ) ) ) );

        return $out;
    }

    /**
     * What makes two entries the same file: the content, not the mode, which
     * an install keeps as it is (cli.php 0700).
     *
     * @param  array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function content( array $entry ) {

        unset( $entry['mode'] );

        return $entry;
    }
}
