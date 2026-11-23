<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The updates directory, owa-data/updates-<32 hex>/: the release tarballs
 * automatic updates downloaded, found by looking for it (updater.php
 * `updatesDir`) and kept in the updates_dir setting for the rest of the
 * process.
 *
 *   updates-<id>/                0711
 *     owa_<v>_packaged.tar       0600: a release tarball, there only once its SHA-256
 *     owa_<v>_packaged.tar.sig         matched GitHub's digest and its signature a trusted
 *                                      key (Signature). Two at most: the installed
 *                                      release's, which a failed install puts back, and
 *                                      the one the release check chose, until it installs.
 *     last-failure.json          0644: the release whose install failed and was put back,
 *                                or whose schema updates did not complete, until one
 *                                installs: {format, version, from, error, at, booted}
 *
 * Nothing here is trusted for what it holds. The installer opens each
 * tarball once and checks its SHA-256 and signature through that handle
 * before it reads a file from it (open()). last-failure.json only says which
 * release automatic updates skip; it is readable by the web user, for the
 * Updates screen.
 *
 * @phpstan-import-type Release from Releases
 */
class Downloads {

    const FAILURE = 'last-failure.json';

    /** last-failure.json's format: raised only when a field changes meaning or goes. */
    const FORMAT = 1;

    /** Seconds a tarball download may take. */
    const DOWNLOAD_TIMEOUT = 600;

    /** @var string the updates directory, '' while there is none */
    private $root;

    /** @var bool whether the updates directory was named, not found (a test's) */
    private $named;

    /** @var string the data directory it lives in */
    private $data;

    /** @var array<string, array<string, mixed>|null> release.php by version, read once */
    private $info = [];

    /**
     * @param string|null $root the updates directory, for a test; else found
     * @param string|null $data the data directory; else OWA's
     */
    public function __construct( $root = null, $data = null ) {

        $this->data  = rtrim( (string) ( $data ?? \OWA\Core\Env::dataDir() ), '/' );
        $this->named = $root !== null;
        $this->root  = $root !== null ? rtrim( (string) $root, '/' ) : (string) self::locate( $this->data );
    }

    /**
     * The installed release's updater.php closures.
     *
     * @return array<string, mixed>
     */
    public static function updater() {

        return require \OWA\Core\Env::root() . '/updater.php';
    }

    /**
     * The updates directory, from the setting or by looking for it (and then
     * set), or null when there is none or more than one.
     *
     * @param  string $data
     * @return string|null
     */
    public static function locate( $data ) {

        $set = (string) CoreAPI::getSetting( 'base', 'updates_dir' );

        if ( $set !== '' && is_dir( $set ) ) {

            return $set;
        }

        $found = self::updater()['updatesDir']( $data );

        if ( $found !== null ) {

            CoreAPI::setSetting( 'base', 'updates_dir', $found );
        }

        return $found;
    }

    /** @return int how many updates directories there are; more than one stops installs */
    public function count() {

        return count( self::updater()['updatesDirs']( $this->data ) );
    }

    /** @return string the updates directory, '' while there is none */
    public function root() {

        return $this->root;
    }

    /**
     * Create the updates directory if there is none, as the only one, and
     * set the setting.
     *
     * @return void
     * @throws \RuntimeException when it cannot, or there is more than one
     */
    public function ensureRoot() {

        if ( $this->root !== '' && is_dir( $this->root ) ) {

            return;
        }

        // A directory named outright is made where it was named, never in OWA's data directory.
        if ( $this->named ) {

            if ( ! @mkdir( $this->root, 0711, true ) && ! is_dir( $this->root ) ) {

                throw new \RuntimeException( sprintf( 'Could not create %s.', $this->root ) );
            }

            return;
        }

        $dir = self::updater()['updatesDir']( $this->data, true );

        if ( $dir === null ) {

            throw new \RuntimeException( sprintf( 'Could not make the updates directory in %s: it is not writable, or it holds more than one updates-* directory.', $this->data ) );
        }

        $this->root = $dir;
        CoreAPI::setSetting( 'base', 'updates_dir', $dir );
    }

    /**
     * @param  string $version
     * @return string the release's tarball
     */
    public function tarball( $version ) {

        return $this->root . '/' . sprintf( Releases::ASSET, $version );
    }

    /**
     * Whether the release's tarball and its signature have been downloaded.
     *
     * @param  string $version
     * @return bool
     */
    public function has( $version ) {

        return $this->root !== '' && Version::parse( (string) $version ) !== null
            && is_file( $this->tarball( $version ) ) && is_file( $this->tarball( $version ) . '.sig' );
    }

    /** @return list<string> the versions downloaded, newest first */
    public function versions() {

        $out = [];

        foreach ( $this->root === '' ? [] : (array) glob( $this->root . '/owa_*_packaged.tar' ) as $file ) {

            if ( preg_match( '/^owa_(\d+\.\d+\.\d+)_packaged\.tar$/', basename( (string) $file ), $m ) && $this->has( $m[1] ) ) {

                $out[] = $m[1];
            }
        }

        usort( $out, static fn ( $a, $b ) => Version::compare( $b, $a ) );

        return $out;
    }

    /**
     * Delete every tarball but those of $keep, and what a download left
     * half done.
     *
     * @param  list<string> $keep versions
     * @return void
     */
    public function prune( array $keep ) {

        if ( $this->root === '' ) {

            return;
        }

        $names = array_map( static fn ( $v ) => sprintf( Releases::ASSET, $v ), array_filter( $keep ) );

        foreach ( (array) glob( $this->root . '/owa_*' ) as $file ) {

            $name = basename( (string) $file );

            if ( ! in_array( $name, $names, true ) && ! in_array( substr( $name, 0, -4 ), $names, true ) ) {

                @unlink( (string) $file );
            }
        }
    }

    /** @return array<string, mixed>|null last-failure.json */
    public function failure() {

        return $this->root === '' ? null : $this->readJson( $this->root . '/' . self::FAILURE );
    }

    /**
     * @param  array<string, mixed> $failure
     * @return void
     */
    public function writeFailure( array $failure ) {

        $this->writeJson( $this->root . '/' . self::FAILURE, [ 'format' => self::FORMAT ] + $failure );
    }

    /** @return void */
    public function clearFailure() {

        if ( $this->root !== '' ) {

            @unlink( $this->root . '/' . self::FAILURE );
        }
    }

    /**
     * A downloaded release's release.php, read from its tarball; null when
     * it has none or there is no such tarball.
     *
     * @param  string $version
     * @return array<string, mixed>|null
     */
    public function releaseInfo( $version ) {

        if ( ! array_key_exists( $version, $this->info ) ) {

            try {

                $data = $this->has( $version ) ? \OWA\Core\VersionInfo::decode( Tar::member( $this->tarball( $version ), \OWA\Core\VersionInfo::FILE ) ) : null;

            } catch ( \RuntimeException $e ) {

                $data = null;
            }

            $this->info[ $version ] = $data;
        }

        return $this->info[ $version ];
    }

    /**
     * Each file the release's tarball holds, with its size.
     *
     * @param  string $version
     * @return array<string, int>
     * @throws \RuntimeException
     */
    public function files( $version ) {

        $out = [];

        Tar::each( $this->tarball( $version ), static function ( $rel, $type, $mode, $size ) use ( &$out ) {

            if ( $type === 'file' ) {

                $out[ $rel ] = $size;
            }
        } );

        return $out;
    }

    /**
     * Open a downloaded release's tarball and prove it through that handle:
     * its SHA-256 against its signature by a trusted key (Signature), its
     * release.php naming it, and every member one a release may hold (Tar).
     * Whatever is read from the handle after is what was proved, whatever
     * happens to the file. A tarball that does not prove is deleted with its
     * signature, so the next release check downloads it again.
     *
     * @param  string $version
     * @return resource rewound
     * @throws \RuntimeException
     */
    public function open( $version ) {

        $tar = $this->tarball( $version );
        $h   = @fopen( $tar, 'rb' );

        if ( $h === false ) {

            throw new \RuntimeException( sprintf( '%s has not been downloaded.', basename( $tar ) ) );
        }

        try {

            $ctx = hash_init( 'sha256' );
            hash_update_stream( $ctx, $h );
            Signature::verify( $version, hash_final( $ctx ), (string) @file_get_contents( $tar . '.sig' ) );

            rewind( $h );
            $info = \OWA\Core\VersionInfo::decode( Tar::member( $h, \OWA\Core\VersionInfo::FILE ) );

            if ( ( is_array( $info ) ? $info['version'] ?? null : null ) !== $version ) {

                throw new \RuntimeException( sprintf( '%s\'s release.php does not say it is %s.', basename( $tar ), $version ) );
            }

            rewind( $h );

            return $h;

        } catch ( \Throwable $e ) {

            fclose( $h );
            @unlink( $tar );
            @unlink( $tar . '.sig' );

            throw new \RuntimeException( sprintf( '%s It was deleted; the next release check downloads it again.', rtrim( $e->getMessage(), '.' ) . '.' ), 0, $e );
        }
    }

    /**
     * Download a release's tarball and its signature, check its SHA-256
     * against the asset's digest (else the release's SHA256SUMS, when either
     * is known), its signature, and that its release.php names it, and only then give it
     * its name. On any failure nothing is left and the error is thrown: the
     * next run starts again.
     *
     * @param  Release $release
     * @return string the tarball's SHA-256
     * @throws \RuntimeException
     */
    public function download( array $release ) {

        $this->ensureRoot();

        $tar  = $this->tarball( $release['version'] );
        $part = $tar . '.part';
        @unlink( $part );

        try {

            $expected = $release['sha256'];

            if ( $expected === null && $release['sums_url'] !== null ) {

                $expected = Releases::sumFor( Releases::get( $release['sums_url'], Releases::TIMEOUT ), basename( $tar ) );
            }

            if ( $release['sig_url'] === null ) {

                throw new \RuntimeException( sprintf( '%s is not signed: the release has no %s.', basename( $tar ), sprintf( Signature::ASSET, $release['version'] ) ) );
            }

            Releases::get( $release['asset_url'], self::DOWNLOAD_TIMEOUT, $part );
            @chmod( $part, 0600 );

            $actual = (string) hash_file( 'sha256', $part );

            // Without a digest (a release fetched by its version) the signature alone proves it, below.
            if ( $expected !== null && ! hash_equals( $expected, $actual ) ) {

                throw new \RuntimeException( sprintf( '%s does not match its SHA-256: expected %s, downloaded %s.',
                    basename( $tar ), $expected, $actual ) );
            }

            // The SHA-256 says this is the file the listing names; the signature
            // says OWA released it (Signature), wherever the listing came from.
            $sig = Releases::get( $release['sig_url'], Releases::TIMEOUT );
            Signature::verify( $release['version'], $actual, $sig );

            // Read whole, so it is checked whole as a release (Tar), and says it is this one.
            $info = \OWA\Core\VersionInfo::decode( Tar::member( $part, \OWA\Core\VersionInfo::FILE ) );

            if ( ( is_array( $info ) ? $info['version'] ?? null : null ) !== $release['version'] ) {

                throw new \RuntimeException( sprintf( '%s\'s release.php says it is %s.', $release['version'], var_export( is_array( $info ) ? $info['version'] ?? null : null, true ) ) );
            }

            if ( @file_put_contents( $tar . '.sig', trim( $sig ) . "\n" ) === false || ! @rename( $part, $tar ) ) {

                throw new \RuntimeException( sprintf( 'Could not write %s.', $tar ) );
            }

            unset( $this->info[ $release['version'] ] );

            return $actual;

        } catch ( \Throwable $e ) {

            @unlink( $part );

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException( $e->getMessage(), 0, $e );
        }
    }

    /**
     * Delete a directory and everything in it. Links are removed, not followed.
     *
     * @param  string $dir
     * @return void
     */
    public static function remove( $dir ) {

        self::updater()['remove']( (string) $dir );
    }

    /**
     * @param  string $path
     * @return array<string, mixed>|null
     */
    public function readJson( $path ) {

        $data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;

        return is_array( $data ) ? $data : null;
    }

    /**
     * @param  string               $path
     * @param  array<string, mixed> $data
     * @return void
     */
    public function writeJson( $path, array $data ) {

        $tmp = $path . '.tmp' . getmypid();

        if ( file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) === false ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }

        // Readable by the web user (the Updates screen); written by the owner alone.
        chmod( $tmp, 0644 );

        if ( ! rename( $tmp, $path ) ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }
    }
}
