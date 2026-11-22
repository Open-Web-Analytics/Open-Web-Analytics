<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The updates directory: owa-data/updates-<32 hex>/, a random name so the
 * web cannot guess it, found by looking for it (updater.php `updatesDir`)
 * and kept in the updates_dir setting for the rest of the process.
 *
 *   updates-<id>/                0700
 *     staged.json                Step 1's only: {format, version, window, sha256, staged_at}
 *     <version>/release/         a staged release: the tarball unpacked, there
 *                                only once its SHA-256 matched GitHub's digest
 *     install/                   the installer's, while an install is unfinished
 *       install.json             {version, attempts, retry_after, copied, booted, error}
 *       backup/                  the originals of every file replaced or deleted
 *
 * Step 1 writes staged.json and <version>/; the installer writes install/.
 * Every JSON file is written to a temporary name and renamed into place,
 * mode 0600.
 *
 * @phpstan-import-type Release from Releases
 */
class Staging {

    const STAGED  = 'staged.json';
    const INSTALL = 'install';
    const RELEASE = 'release';

    /** staged.json's format: raised only when a field changes meaning or goes. */
    const FORMAT = 1;

    /** Seconds a tarball download may take. */
    const DOWNLOAD_TIMEOUT = 600;

    /** @var string the updates directory, '' while there is none */
    private $root;

    /** @var bool whether the updates directory was named, not found (a test's) */
    private $named;

    /** @var string the data directory it lives in */
    private $data;

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
     * The installed release's updater.php closures: the one place the
     * updates directory is found, before boot and after.
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

            if ( ! @mkdir( $this->root, 0700, true ) && ! is_dir( $this->root ) ) {

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

    /**
     * The staged releases, lowest version first.
     *
     * @return list<string>
     */
    public function versions() {

        if ( $this->root === '' ) {

            return [];
        }

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

        $staged = $this->root === '' ? null : $this->readJson( $this->root . '/' . self::STAGED );

        return $staged && Version::parse( (string) ( $staged['version'] ?? '' ) ) !== null ? $staged : null;
    }

    /**
     * @param  string                    $version
     * @param  array<string, mixed>|null $window
     * @param  string                    $sha256 the tarball's
     * @return void
     */
    public function writeStaged( $version, $window, $sha256 ) {

        $this->writeJson( $this->root . '/' . self::STAGED, [
            'format'    => self::FORMAT,
            'version'   => $version,
            'window'    => $window,
            'sha256'    => $sha256,
            'staged_at' => time(),
        ] );
    }

    /** @return void */
    public function removeStaged() {

        if ( $this->root !== '' ) {

            @unlink( $this->root . '/' . self::STAGED );
        }
    }

    /**
     * Whether a release is staged: its release/ exists (it is renamed into
     * place only once proved) and says it is that version.
     *
     * @param  string $version
     * @return bool
     */
    public function isStaged( $version ) {

        return $this->root !== '' && ( $this->releaseInfo( $version )['version'] ?? null ) === $version;
    }

    /** @return array<string, mixed>|null install/install.json: the unfinished install */
    public function install() {

        return $this->root === '' ? null : $this->readJson( $this->root . '/' . self::INSTALL . '/install.json' );
    }

    /**
     * Whether the unfinished install is of this version: its staging is then
     * the installer's.
     *
     * @param  string $version
     * @return bool
     */
    public function hasInstall( $version ) {

        return ( $this->install()['version'] ?? null ) === $version;
    }

    /**
     * The staged release's release.json.
     *
     * @param  string $version
     * @return array<string, mixed>|null
     */
    public function releaseInfo( $version ) {

        return $this->root === '' ? null : $this->readJson( $this->releaseDir( $version ) . '/release.json' );
    }

    /**
     * Download a release, check it against its SHA-256 and unpack it. On any
     * failure the directory is deleted and the error thrown: the next run
     * starts again.
     *
     * @param  Release $release
     * @return string the tarball's SHA-256
     * @throws \RuntimeException
     */
    public function stage( array $release ) {

        $v = $release['version'];

        if ( $this->hasInstall( $v ) ) {

            throw new \LogicException( sprintf( '%s is being installed; its staging is not changed.', $v ) );
        }

        $this->ensureRoot();

        $dir = $this->dir( $v );
        self::remove( $dir );

        try {

            if ( ! @mkdir( $dir, 0700 ) ) {

                throw new \RuntimeException( sprintf( 'Could not create %s.', $dir ) );
            }

            $tar = $dir . '/' . sprintf( Releases::ASSET, $v );
            $sha = $this->download( $release, $tar );
            $tmp = $dir . '/' . self::RELEASE . '.tmp';

            mkdir( $tmp, 0755 );
            Tar::extract( $tar, $tmp );
            @unlink( $tar );

            $info = $this->readJson( $tmp . '/release.json' );

            if ( ( $info['version'] ?? null ) !== $v ) {

                throw new \RuntimeException( sprintf( '%s\'s release.json says it is %s.', $v, var_export( $info['version'] ?? null, true ) ) );
            }

            if ( ! rename( $tmp, $this->releaseDir( $v ) ) ) {

                throw new \RuntimeException( sprintf( 'Could not rename %s.', $tmp ) );
            }

            return $sha;

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

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }

        chmod( $tmp, 0600 );

        if ( ! rename( $tmp, $path ) ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }
    }
}
