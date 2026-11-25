<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;
use OWA\Core\SafeFile;

/**
 * The updates directory, owa-data/updates-<32 hex>/: the release tarballs
 * automatic updates downloaded, found by looking for it (updater.php
 * `updatesDir`) and kept in the updates_dir setting for the rest of the
 * process.
 *
 *   updates-<id>/                0755: listed by the Updates screen (UpdatesState)
 *     owa_<v>_packaged.tar       0600: a release tarball, there only once its signature
 *     owa_<v>_packaged.tar.sig   0644  checked by a trusted key (Signature), and its SHA-256
 *                                      the listing's digest where it has one. Two at most: the installed
 *                                      release's, which a failed install puts back, and
 *                                      the one the release check chose, until it installs.
 *     last-failure.json          0644: the release whose install failed and was put back,
 *                                or whose schema updates did not complete, until one
 *                                installs; written by updater.php `recordFailure` alone
 *
 * Nothing here is trusted for what it holds: the web user can write the data
 * directory, so it may have replaced any of it. A tarball is read once, into
 * a private copy (php://temp) while its SHA-256 is taken, and only that copy
 * is checked against the signature and read after (open()): its release.php,
 * its file list and the files the copy installs. Rewriting the file on disk
 * afterwards changes nothing. last-failure.json only says which release
 * automatic updates skip; it is readable by the web user, for the Updates
 * screen.
 *
 * @phpstan-import-type Release from Releases
 */
class Downloads {

    const FAILURE = 'last-failure.json';

    /** Seconds a tarball download may take. */
    const DOWNLOAD_TIMEOUT = 600;

    /** The most a tarball may hold: a release is about 20 MB. */
    const MAX_TARBALL = 268435456;

    /** @var array<string, array{h: resource, sha256: string, info: array<string, mixed>}> the private copies open() proved, by version */
    private $proved = [];

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

        if ( $set !== '' && is_dir( $set ) && ! is_link( $set ) ) {

            return $set;
        }

        $found = self::updater()['updatesDir']( $data );

        if ( $found !== null ) {

            CoreAPI::setSetting( 'base', 'updates_dir', $found );
        }

        return $found;
    }

    /**
     * The automatic updates files the user $uid did not make, and who did:
     * the install lock, each updates directory and its last-failure.json.
     * The web user can write the data directory, so it can make any of them,
     * and each stops updates: a lock that cannot be taken over, a directory
     * that cannot be written, a failure that was never recorded.
     *
     * @param  int $uid the user that installs updates
     * @return array<string, int> owner by path
     */
    public function foreign( $uid ) {

        $paths = [ InstallLock::path() ];

        foreach ( self::updater()['updatesDirs']( $this->data ) as $dir ) {

            array_push( $paths, (string) $dir, $dir . '/' . self::FAILURE );
        }

        $out = [];

        foreach ( $paths as $path ) {

            $stat = @lstat( $path );

            if ( $stat !== false && (int) $stat['uid'] !== (int) $uid ) {

                $out[ $path ] = (int) $stat['uid'];
            }
        }

        return $out;
    }

    /**
     * What foreign() found, as problems to show: one sentence each.
     *
     * @param  array<string, int> $foreign
     * @param  int                $uid
     * @return list<string>
     */
    public static function foreignProblems( array $foreign, $uid ) {

        $name = static function ( $id ) {

            $user = function_exists( 'posix_getpwuid' ) ? @posix_getpwuid( (int) $id ) : false;

            return is_array( $user ) ? (string) $user['name'] : sprintf( CoreAPI::t( 'user %d' ), $id );
        };
        $out  = [];

        foreach ( $foreign as $path => $owner ) {

            $out[] = sprintf( CoreAPI::t( '%1$s belongs to %2$s, not to %3$s, the user that installs updates: no update installs while it is there. If no install is running, delete it.' ),
                InstallNotices::withoutPaths( $path ), $name( $owner ), $name( $uid ) );
        }

        return $out;
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

        if ( $this->root !== '' && is_dir( $this->root ) && ( $this->named || ! is_link( $this->root ) ) ) {

            return;
        }

        // A directory named outright is made where it was named, never in OWA's data directory.
        if ( $this->named ) {

            if ( ! @mkdir( $this->root, 0755, true ) && ! is_dir( $this->root ) ) {

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

        [ $before, $after ] = explode( '%s', Releases::ASSET );

        foreach ( $this->root === '' ? [] : (array) glob( $this->root . '/' . sprintf( Releases::ASSET, '*' ) ) as $file ) {

            $v = substr( basename( (string) $file ), strlen( $before ), - strlen( $after ) );

            if ( Version::parse( $v ) !== null && $this->has( $v ) ) {

                $out[] = $v;
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
     * Whether a failure could be recorded here now: a block written and
     * deleted beside last-failure.json. An install whose failure could not be
     * recorded would be tried again every hour.
     *
     * @return bool
     */
    public function canRecord() {

        if ( $this->root === '' ) {

            return false;
        }

        $probe = \OWA\Core\SafeFile::fresh( $this->root . '/write-test' );
        $h     = \OWA\Core\SafeFile::create( $probe, 0600 );
        $ok    = $h !== false && @fwrite( $h, str_repeat( "\0", 4096 ) ) === 4096 && fflush( $h );

        if ( $h !== false ) {

            fclose( $h );
        }

        @unlink( $probe );

        return $ok;
    }

    /** @return void */
    public function clearFailure() {

        if ( $this->root !== '' ) {

            @unlink( $this->root . '/' . self::FAILURE );
        }
    }

    /**
     * A downloaded release's release.php, read from its proved copy (open());
     * null when it has none, there is no such tarball, or it does not prove.
     *
     * @param  string $version
     * @return array<string, mixed>|null
     */
    public function releaseInfo( $version ) {

        try {

            return $this->has( $version ) ? $this->proof( $version )['info'] : null;

        } catch ( \RuntimeException $e ) {

            return null;
        }
    }

    /**
     * Each file the release's tarball holds, with its size, from its proved
     * copy (open()).
     *
     * @param  string $version
     * @return array<string, int>
     * @throws \RuntimeException
     */
    public function files( $version ) {

        $out = [];

        Tar::each( $this->open( $version ), static function ( $rel, $type, $mode, $size ) use ( &$out ) {

            if ( $type === 'file' ) {

                $out[ $rel ] = $size;
            }
        } );

        return $out;
    }

    /**
     * The size of the tarballs named, together: those downloaded, and the
     * first one's again for one that is not (it is about the size of a
     * release).
     *
     * @param  string ...$versions
     * @return int
     */
    public function tarballBytes( ...$versions ) {

        $sizes = array_map( fn ( $v ) => $this->has( $v ) ? (int) @filesize( $this->tarball( $v ) ) : 0, $versions );

        return (int) array_sum( array_map( static fn ( $s ) => $s ?: (int) ( $sizes[0] ?? 0 ), $sizes ) );
    }

    /**
     * A downloaded release's SHA-256, as open() proved it.
     *
     * @param  string $version
     * @return string
     * @throws \RuntimeException
     */
    public function sha256( $version ) {

        return $this->proof( $version )['sha256'];
    }

    /**
     * A downloaded release, proved: its tarball read once into a private
     * copy while its SHA-256 is taken, then that copy checked against its
     * signature by a trusted key (Signature), its release.php naming it, and
     * every member one a release may hold (Tar). The handle returned reads
     * that copy, rewound: whatever happens to the file on disk after, it reads
     * what was proved. A tarball that is not a plain file, is larger than
     * MAX_TARBALL, or does not prove is deleted with its signature, so the
     * next release check downloads it again. Proved once per process.
     *
     * @param  string $version
     * @return resource
     * @throws \RuntimeException
     */
    public function open( $version ) {

        $h = $this->proof( $version )['h'];
        rewind( $h );

        return $h;
    }

    /**
     * @param  string $version
     * @return array{h: resource, sha256: string, info: array<string, mixed>}
     * @throws \RuntimeException
     */
    private function proof( $version ) {

        if ( isset( $this->proved[ $version ] ) ) {

            return $this->proved[ $version ];
        }

        $tar  = $this->tarball( $version );
        $copy = null;

        try {

            [ $copy, $sha ] = self::privateCopy( $tar );
            Signature::verify( $version, $sha, (string) @file_get_contents( $tar . '.sig' ) );

            $info = \OWA\Core\VersionInfo::decode( Tar::member( $copy, \OWA\Core\VersionInfo::FILE ) );

            if ( ( is_array( $info ) ? $info['version'] ?? null : null ) !== $version ) {

                throw new \RuntimeException( sprintf( '%s\'s release.php does not say it is %s.', basename( $tar ), $version ) );
            }

            return $this->proved[ $version ] = [ 'h' => $copy, 'sha256' => $sha, 'info' => $info ];

        } catch ( \Throwable $e ) {

            if ( is_resource( $copy ) ) {

                fclose( $copy );
            }

            if ( ! str_contains( $e->getMessage(), 'has not been downloaded' ) ) {

                @unlink( $tar );
                @unlink( $tar . '.sig' );
            }

            throw new \RuntimeException( sprintf( '%s It was deleted; the next release check downloads it again.', rtrim( $e->getMessage(), '.' ) . '.' ), 0, $e );
        }
    }

    /**
     * $file read once into php://temp (memory, then a temporary file only
     * this process holds) while its SHA-256 is taken. Only a plain file, no
     * link, at most MAX_TARBALL.
     *
     * @param  string $file
     * @return array{0: resource, 1: string} the copy, rewound, and the SHA-256
     * @throws \RuntimeException
     */
    public static function privateCopy( $file ) {

        return self::updater()['privateCopy']( $file, self::MAX_TARBALL );
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
        $part = SafeFile::fresh( $tar . '.part' );

        // What a download that was killed left (unlink() removes a link, never what it names).
        foreach ( (array) glob( $tar . '.part*' ) as $left ) {

            @unlink( (string) $left );
        }

        try {

            $expected = $release['sha256'];

            if ( $expected === null && $release['sums_url'] !== null ) {

                $expected = Releases::sumFor( Releases::get( $release['sums_url'], Releases::TIMEOUT ), basename( $tar ) );
            }

            if ( $release['sig_url'] === null ) {

                throw new \RuntimeException( sprintf( '%s is not signed: the release has no %s.', basename( $tar ), sprintf( Signature::ASSET, $release['version'] ) ) );
            }

            // Opened here under a fresh name, which no link can be waiting at: the fetch writes into it.
            $sink = SafeFile::create( $part, 0600 );

            if ( $sink === false ) {

                throw new \RuntimeException( sprintf( 'Could not write %s.', $part ) );
            }

            try {

                Releases::get( $release['asset_url'], self::DOWNLOAD_TIMEOUT, $sink );

            } finally {

                fclose( $sink );
            }

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

            SafeFile::replace( $tar . '.sig', trim( $sig ) . "\n" );

            if ( ! @rename( $part, $tar ) ) {

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
     * @param  string $path
     * @return array<string, mixed>|null
     */
    private function readJson( $path ) {

        $data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;

        return is_array( $data ) ? $data : null;
    }
}
