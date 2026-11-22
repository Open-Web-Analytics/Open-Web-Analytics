<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The updates directory: owa-data/updates-<32 hex>/, a random name so the
 * web cannot guess it, found by looking for it (updater.php `updatesDir`)
 * and kept in the updates_dir setting for the rest of the process.
 *
 *   updates-<id>/        0711: others open a name they know, list nothing
 *     staged.json        0644: what staged/ holds, and whether it installs on its own
 *                        {format, version, window, sha256, staged_at, automatic, by_hand}
 *     install.json       0644, the installer's, while an install is unfinished:
 *                        {version, attempts, retry_after, copied, booted, forced, error}
 *     staged/            0700: the one staged release, the tarball unpacked; there
 *                        only once its SHA-256 matched GitHub's digest
 *                        (staged.tmp/ while it downloads and unpacks)
 *     backup/            0700, the installer's: the originals of every file
 *                        replaced or deleted
 *
 * One release is staged at a time (Peter, 2026-11-22). A download, by Step 1
 * or by hand, replaces it; staged.json exists exactly when staged/ does, and
 * describes it. The two JSON files are readable by the web user, under any
 * arrangement of users and groups, for the Updates screen; nothing that holds
 * code is. Every JSON file is written to a temporary name and renamed into
 * place.
 *
 * @phpstan-import-type Release from Releases
 */
class Staging {

    const STAGED  = 'staged.json';
    const INSTALL = 'install.json';
    const BACKUP  = 'backup';
    const SLOT    = 'staged';

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

    /** @return string the staged release */
    public function releaseDir() {

        return $this->root . '/' . self::SLOT;
    }

    /** @return array<string, mixed>|null staged.json */
    public function staged() {

        $staged = $this->root === '' ? null : $this->readJson( $this->root . '/' . self::STAGED );

        return $staged && Version::parse( (string) ( $staged['version'] ?? '' ) ) !== null ? $staged : null;
    }

    /**
     * Describe what staged/ holds. $automatic: whether the hand-off installs it
     * on its own, in its window; false for one staged by hand, or one that
     * waits for a requirement or for an operator.
     *
     * @param  string                    $version
     * @param  array<string, mixed>|null $window
     * @param  string                    $sha256 the tarball's
     * @param  bool                      $automatic
     * @param  bool                      $by_hand
     * @param  int|null                  $staged_at the caller's clock; now when null
     * @return void
     */
    public function writeStaged( $version, $window, $sha256, $automatic = true, $by_hand = false, $staged_at = null ) {

        $this->writeJson( $this->root . '/' . self::STAGED, [
            'format'    => self::FORMAT,
            'version'   => $version,
            'window'    => $window,
            'sha256'    => $sha256,
            'staged_at' => $staged_at ?? time(),
            'automatic' => (bool) $automatic,
            'by_hand'   => (bool) $by_hand,
        ] );
    }

    /** @return void */
    public function removeStaged() {

        if ( $this->root !== '' ) {

            @unlink( $this->root . '/' . self::STAGED );
        }
    }

    /**
     * Empty the slot: staged.json, then staged/. The caller holds the lock.
     *
     * @return void
     */
    public function clear() {

        if ( $this->root !== '' ) {

            $this->removeStaged();
            self::remove( $this->releaseDir() );
        }
    }

    /**
     * Whether staged/ holds this release: its release.json says so (staged/
     * is renamed into place only once proved).
     *
     * @param  string $version
     * @return bool
     */
    public function isStaged( $version ) {

        return $this->root !== '' && ( $this->releaseInfo()['version'] ?? null ) === $version;
    }

    /** @return array<string, mixed>|null install.json: the unfinished install */
    public function install() {

        return $this->root === '' ? null : $this->readJson( $this->root . '/' . self::INSTALL );
    }

    /**
     * Whether the unfinished install is of this version.
     *
     * @param  string $version
     * @return bool
     */
    public function hasInstall( $version ) {

        return ( $this->install()['version'] ?? null ) === $version;
    }

    /** @return array<string, mixed>|null the staged release's release.json */
    public function releaseInfo() {

        return $this->root === '' ? null : $this->readJson( $this->releaseDir() . '/release.json' );
    }

    /**
     * Download a release into staged.tmp/, check it against its SHA-256 and
     * unpack it, without touching the slot; swapIn() then puts it there. On
     * any failure staged.tmp/ is deleted and the error thrown: the next run
     * starts again.
     *
     * @param  Release $release
     * @return string the tarball's SHA-256
     * @throws \RuntimeException
     */
    public function download( array $release ) {

        $v = $release['version'];
        $this->ensureRoot();

        $dir = $this->root . '/' . self::SLOT . '.tmp';
        self::remove( $dir );

        try {

            if ( ! @mkdir( $dir, 0700 ) ) {

                throw new \RuntimeException( sprintf( 'Could not create %s.', $dir ) );
            }

            $tar = $dir . '/' . sprintf( Releases::ASSET, $v );
            $sha = $this->fetch( $release, $tar );

            mkdir( $dir . '/release', 0755 );
            Tar::extract( $tar, $dir . '/release' );
            @unlink( $tar );

            $info = $this->readJson( $dir . '/release/release.json' );

            if ( ( $info['version'] ?? null ) !== $v ) {

                throw new \RuntimeException( sprintf( '%s\'s release.json says it is %s.', $v, var_export( $info['version'] ?? null, true ) ) );
            }

            return $sha;

        } catch ( \Throwable $e ) {

            self::remove( $dir );

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException( $e->getMessage(), 0, $e );
        }
    }

    /**
     * Put what download() unpacked into the slot, in place of what was there:
     * staged.json goes first, and an unfinished install with its backup/ (the
     * release replacing it may well be the fix). The caller holds the lock and
     * writes staged.json after.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function swapIn() {

        $tmp = $this->root . '/' . self::SLOT . '.tmp';

        $this->removeStaged();
        @unlink( $this->root . '/' . self::INSTALL );
        self::remove( $this->root . '/' . self::BACKUP );
        self::remove( $this->releaseDir() );

        if ( ! @rename( $tmp . '/release', $this->releaseDir() ) ) {

            throw new \RuntimeException( sprintf( 'Could not move %s/release into place.', $tmp ) );
        }

        @chmod( $this->releaseDir(), 0700 );
        self::remove( $tmp );
    }

    /**
     * Fetch the tarball into $tar and prove it: its SHA-256 against the
     * asset's digest, else against the release's SHA256SUMS.
     *
     * @param  Release $release
     * @param  string  $tar
     * @return string the SHA-256
     */
    private function fetch( array $release, $tar ) {

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

        // Readable by the web user (the Updates screen); written by the owner alone.
        chmod( $tmp, 0644 );

        if ( ! rename( $tmp, $path ) ) {

            @unlink( $tmp );

            throw new \RuntimeException( sprintf( 'Could not write %s.', $path ) );
        }
    }
}
