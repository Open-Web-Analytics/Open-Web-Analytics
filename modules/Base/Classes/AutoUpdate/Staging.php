<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\ReleaseManifest;

/**
 * owa-data/updates/: the releases Step 1: release check has downloaded.
 *
 *   updates/                     0700
 *     staged.json                Step 1's only: {format, version, window}
 *     <version>/                 0700
 *       owa_<version>_packaged.tar
 *       release/                 the tarball unpacked
 *       stage.json               Step 1's: what it proved, and the preflight
 *       install.json, backup/    the installer's (Steps 2-4)
 *
 * A <version>/ is staged once its stage.json says the manifest was checked:
 * stage.json is written last, so a directory without one is an interrupted
 * staging, deleted and redone. Step 1 never changes a staged release/; it
 * rewrites only stage.json's preflight. Every JSON file is written to a
 * temporary name and renamed into place, mode 0600.
 *
 * @phpstan-import-type Release from Releases
 * @phpstan-import-type Manifest from \OWA\Core\ReleaseManifest
 */
class Staging {

    const STAGED  = 'staged.json';
    const STAGE   = 'stage.json';
    const INSTALL = 'install.json';
    const RELEASE = 'release';

    /** The format of staged.json and stage.json: raised only when a field changes meaning or goes. */
    const FORMAT = 1;

    /** Seconds a tarball download may take. */
    const DOWNLOAD_TIMEOUT = 600;

    /** @var string */
    private $root;

    /** @param string|null $root a test names its own; else owa-data/updates */
    public function __construct( $root = null ) {

        $this->root = rtrim( $root ?? \OWA\Core\Env::dataDir() . 'updates', '/' );
    }

    /** @return string */
    public function root() {

        return $this->root;
    }

    /**
     * @param  string $version
     * @return string
     */
    public function dir( $version ) {

        return $this->root . '/' . $version;
    }

    /**
     * @param  string $version
     * @return string the unpacked release
     */
    public function releaseDir( $version ) {

        return $this->dir( $version ) . '/' . self::RELEASE;
    }

    /** @return void */
    public function ensureRoot() {

        if ( ! is_dir( $this->root ) && ! @mkdir( $this->root, 0700, true ) ) {

            throw new \RuntimeException( sprintf( 'Could not create %s.', $this->root ) );
        }

        chmod( $this->root, 0700 );
    }

    /**
     * The release directories present, lowest version first.
     *
     * @return list<string>
     */
    public function versions() {

        $versions = [];

        foreach ( (array) glob( $this->root . '/*', GLOB_ONLYDIR ) as $dir ) {

            $v = basename( (string) $dir );

            if ( Version::parse( $v ) !== null ) {

                $versions[] = $v;
            }
        }

        usort( $versions, [ Version::class, 'compare' ] );

        return $versions;
    }

    /** @return array<string, mixed>|null staged.json */
    public function staged() {

        $staged = $this->readJson( $this->root . '/' . self::STAGED );

        return $staged && Version::parse( (string) ( $staged['version'] ?? '' ) ) !== null ? $staged : null;
    }

    /**
     * @param  string                    $version
     * @param  array<string, mixed>|null $window
     * @return void
     */
    public function writeStaged( $version, $window ) {

        $this->writeJson( $this->root . '/' . self::STAGED, [ 'format' => self::FORMAT, 'version' => $version, 'window' => $window ] );
    }

    /** @return void */
    public function removeStaged() {

        @unlink( $this->root . '/' . self::STAGED );
    }

    /**
     * @param  string $version
     * @return array<string, mixed>|null stage.json
     */
    public function stageInfo( $version ) {

        return $this->readJson( $this->dir( $version ) . '/' . self::STAGE );
    }

    /**
     * @param  string               $version
     * @param  array<string, mixed> $info
     * @return void
     */
    public function writeStageInfo( $version, array $info ) {

        $this->writeJson( $this->dir( $version ) . '/' . self::STAGE, $info );
    }

    /**
     * Whether a release is staged: its stage.json says the manifest was checked.
     *
     * @param  string $version
     * @return bool
     */
    public function isStaged( $version ) {

        $info = $this->stageInfo( $version );

        return $info !== null && ( $info['version'] ?? null ) === $version && ! empty( $info['manifest_checked'] )
            && is_dir( $this->releaseDir( $version ) );
    }

    /**
     * Whether the installer has claimed a release (its install.json).
     *
     * @param  string $version
     * @return bool
     */
    public function hasInstall( $version ) {

        return is_file( $this->dir( $version ) . '/' . self::INSTALL );
    }

    /**
     * The staged release's release.json.
     *
     * @param  string $version
     * @return array<string, mixed>|null
     */
    public function releaseInfo( $version ) {

        return $this->readJson( $this->releaseDir( $version ) . '/release.json' );
    }

    /**
     * Download, verify and unpack a release, then check every file against
     * its manifest. On any failure the directory is deleted and the error
     * thrown: the next run starts again.
     *
     * @param  Release $release
     * @return array<string, mixed> its stage.json
     * @throws \RuntimeException
     */
    public function stage( array $release ) {

        $v   = $release['version'];
        $dir = $this->dir( $v );

        if ( $this->hasInstall( $v ) ) {

            throw new \LogicException( sprintf( '%s is claimed by the installer; Step 1 does not change it.', $v ) );
        }

        $this->ensureRoot();
        self::remove( $dir );

        try {

            if ( ! @mkdir( $dir, 0700 ) ) {

                throw new \RuntimeException( sprintf( 'Could not create %s.', $dir ) );
            }

            $tar  = $dir . '/' . sprintf( Releases::ASSET, $v );
            $sha  = $this->download( $release, $tar );
            $tmp  = $dir . '/' . self::RELEASE . '.tmp';

            mkdir( $tmp, 0755 );
            Tar::extract( $tar, $tmp );

            if ( ! rename( $tmp, $this->releaseDir( $v ) ) ) {

                throw new \RuntimeException( sprintf( 'Could not rename %s.', $tmp ) );
            }

            $this->check( $v );

            $info = [
                'format'           => self::FORMAT,
                'version'          => $v,
                'staged_at'        => time(),
                'tarball_sha256'   => $sha,
                'manifest_checked' => true,
                'preflight'        => null,
            ];

            $this->writeStageInfo( $v, $info );

            return $info;

        } catch ( \Throwable $e ) {

            self::remove( $dir );

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException( $e->getMessage(), 0, $e );
        }
    }

    /**
     * Fetch the tarball into $tar and prove it: its SHA-256 against the
     * asset's digest, else against the release's SHA256SUMS.
     *
     * @param  Release $release
     * @param  string  $tar
     * @return string the SHA-256
     */
    private function download( array $release, $tar ) {

        $expected = $release['sha256'];

        if ( $expected === null && $release['sums_url'] !== null ) {

            $expected = Releases::sumFor( Releases::get( $release['sums_url'], Releases::TIMEOUT ), basename( $tar ) );
        }

        if ( $expected === null ) {

            throw new \RuntimeException( sprintf( '%s has no SHA-256 to check it against: no digest and no %s entry.',
                basename( $tar ), Releases::SUMS_ASSET ) );
        }

        Releases::get( $release['asset_url'], self::DOWNLOAD_TIMEOUT, $tar . '.part' );

        $actual = (string) hash_file( 'sha256', $tar . '.part' );

        if ( ! hash_equals( $expected, $actual ) ) {

            throw new \RuntimeException( sprintf( '%s does not match its SHA-256: expected %s, downloaded %s.',
                basename( $tar ), $expected, $actual ) );
        }

        rename( $tar . '.part', $tar );

        return $actual;
    }

    /**
     * Every file of the unpacked release is the one its manifest lists, and
     * it is the release it says it is.
     *
     * @param  string $version
     * @return void
     * @throws \RuntimeException
     */
    public function check( $version ) {

        $dir      = $this->releaseDir( $version );
        $manifest = $this->readJson( $dir . '/' . ReleaseManifest::FILE );
        $release  = $this->readJson( $dir . '/release.json' );

        if ( ! $manifest || ! isset( $manifest['files'] ) ) {

            throw new \RuntimeException( sprintf( '%s has no readable %s.', $version, ReleaseManifest::FILE ) );
        }

        if ( ( $release['version'] ?? null ) !== $version ) {

            throw new \RuntimeException( sprintf( '%s\'s release.json says it is %s.', $version, var_export( $release['version'] ?? null, true ) ) );
        }

        /** @var Manifest $manifest */
        $diff = ReleaseManifest::compare( $manifest, $dir );

        foreach ( [ 'missing', 'changed', 'unlisted' ] as $kind ) {

            if ( $diff[ $kind ] ) {

                throw new \RuntimeException( sprintf( '%s does not match its manifest: %d %s, such as %s.',
                    $version, count( $diff[ $kind ] ), $kind, $diff[ $kind ][0] ) );
            }
        }
    }

    /**
     * Delete a directory and everything in it. Links are removed, not followed.
     *
     * @param  string $dir
     * @return void
     */
    public static function remove( $dir ) {

        if ( is_link( $dir ) || is_file( $dir ) ) {

            @unlink( $dir );

            return;
        }

        if ( ! is_dir( $dir ) ) {

            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST );

        foreach ( $it as $path => $info ) {

            /** @var \SplFileInfo $info */
            if ( $info->isDir() && ! $info->isLink() ) {

                @rmdir( (string) $path );

            } else {

                @unlink( (string) $path );
            }
        }

        @rmdir( $dir );
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

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }

        chmod( $tmp, 0600 );

        if ( ! rename( $tmp, $path ) ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }
    }
}
