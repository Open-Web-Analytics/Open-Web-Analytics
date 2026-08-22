<?php

use PHPUnit\Framework\TestCase;

/**
 * The download flow, tested without touching MaxMind or a licence key.
 *
 * WHY THIS IS NOT AN INTEGRATION TEST
 * The real thing costs a download from an account's limit -- MaxMind rate-limit
 * and say so -- and needs a credential that must not be in a repository, a CI
 * secret store, or a developer's shell history. A test suite that needed either
 * would be a test suite nobody runs, and the bugs found here were not in the
 * HTTP anyway. They were in what happens either side of it:
 *
 *   - the archive was named something PharData refuses to open, so the unpack
 *     failed after a successful download
 *   - the dry run reported after downloading, so asking cost what doing costs
 *
 * Both are reachable with the transport replaced, which is what this does. The
 * controller's download() and lastModified() are protected precisely so a test
 * can stand in for them, and dataDir() so nothing is written near a real
 * installation's 60MB database.
 *
 * The fixture archive is built here rather than committed: it is a real
 * .tar.gz, nested in a dated directory the way MaxMind's is, so the code that
 * locates the .mmdb inside it is genuinely exercised.
 */
final class MaxmindDownloadFlowTest extends TestCase {

    /** @var string */
    private $dir;

    /** @var string */
    private $fixture;

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void {

        if ( ! class_exists( 'PharData' ) ) {
            $this->markTestSkipped( 'phar is required to build the fixture archive' );
        }

        $this->dir = sys_get_temp_dir() . '/owa-geoip-test-' . getmypid() . '-' . uniqid() . '/';
        mkdir( $this->dir, 0755, true );

        $this->fixture = $this->buildArchive( 'GeoLite2-City' );
    }

    protected function tearDown(): void {

        foreach ( glob( $this->dir . '*' ) ?: array() as $path ) {
            is_dir( $path ) ? $this->removeTree( $path ) : @unlink( $path );
        }

        @rmdir( $this->dir );
        @unlink( $this->fixture );
    }

    private function removeTree( $dir ) {

        foreach ( new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {

            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }

        @rmdir( $dir );
    }

    /**
     * A .tar.gz shaped like MaxMind's: the database nested inside a directory
     * named for the edition and the date it was built.
     */
    private function buildArchive( $edition, $contents = 'FIXTURE-DATABASE-CONTENTS' ) {

        $staging = sys_get_temp_dir() . '/owa-geoip-fixture-' . uniqid() . '/';
        $nested  = $staging . $edition . '_20260814/';

        mkdir( $nested, 0755, true );
        file_put_contents( $nested . $edition . '.mmdb', $contents );
        file_put_contents( $nested . 'COPYRIGHT.txt', 'fixture' );

        $tar = sys_get_temp_dir() . '/owa-geoip-fixture-' . uniqid() . '.tar';

        $phar = new PharData( $tar );
        $phar->buildFromDirectory( $staging );
        $phar->compress( Phar::GZ );

        unset( $phar );
        @unlink( $tar );
        $this->removeTree( $staging );

        return $tar . '.gz';
    }

    /**
     * The controller with the network replaced and the destination redirected.
     */
    private function controller( array $options = array() ) {

        $fixture = $this->fixture;
        $dir     = $this->dir;

        $controller = new class( $fixture, $dir, $options )
            extends \OWA\Module\MaxmindGeoip\Controller\UpdateGeoipDbCli {

            public $downloads = 0;
            public $head_requests = 0;
            public $messages = array();
            private $fixture;
            private $dir;
            private $options;

            public function __construct( $fixture, $dir, array $options ) {

                $this->fixture = $fixture;
                $this->dir     = $dir;
                $this->options = $options;
                $this->params  = isset( $options['params'] ) ? $options['params'] : array();
                $this->data    = array();
            }

            protected function dataDir() { return $this->dir; }

            protected function download( $url, $destination ) {

                $this->downloads++;

                if ( isset( $this->options['download_fails'] ) ) {
                    return $this->options['download_fails'];
                }

                copy( $this->fixture, $destination );

                return (int) filesize( $destination );
            }

            protected function lastModified( $url ) {

                $this->head_requests++;

                return isset( $this->options['remote_mtime'] ) ? $this->options['remote_mtime'] : 0;
            }

            protected function write( $lines ) {

                foreach ( (array) $lines as $line ) { $this->messages[] = $line; }
            }

            protected function refuse( $msg ) { $this->messages[] = $msg; return null; }
            protected function fail( $msg )   { $this->messages[] = $msg; return null; }

            public function said( $needle ) {

                return (bool) array_filter( $this->messages, function ( $m ) use ( $needle ) {
                    return stripos( (string) $m, $needle ) !== false;
                } );
            }
        };

        return $controller;
    }

    private function withKey( array $params = array() ) {

        return array_merge( array( 'license-key' => 'fixture-key-not-a-real-one' ), $params );
    }

    public function testAFullRunWritesTheDatabaseWhereLookupsReadIt(): void {

        $c = $this->controller( array( 'params' => $this->withKey() ) );
        $c->action();

        $this->assertFileExists( $this->dir . 'GeoLite2-City.mmdb',
            'the database must end up at the path the module reads' );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'the file located inside the nested archive directory must be the one installed' );
    }

    /**
     * The bug that a real key exposed: the archive is nested in a dated
     * directory, so the .mmdb has to be found rather than assumed.
     */
    public function testItFindsTheDatabaseInsideADatedDirectory(): void {

        $c = $this->controller( array( 'params' => $this->withKey() ) );
        $c->action();

        $this->assertFileDoesNotExist( $this->dir . 'GeoLite2-City_20260814',
            'the wrapper directory must not be left behind' );
        $this->assertSame( array(), glob( $this->dir . '.update-*' ) ?: array(),
            'the working directory must be cleaned up' );
    }

    /**
     * The other bug: asking what would happen must not cost what doing it
     * costs. MaxMind rate-limit downloads.
     */
    public function testADryRunMakesNoDownload(): void {

        $c = $this->controller( array( 'params' => $this->withKey( array( 'dry-run' => 1 ) ) ) );
        $c->action();

        $this->assertSame( 0, $c->downloads, 'a dry run must not transfer the archive' );
        $this->assertFileDoesNotExist( $this->dir . 'GeoLite2-City.mmdb' );
        $this->assertTrue( $c->said( 'Dry run' ) );
    }

    public function testAnUpToDateCopyIsNotDownloadedAgain(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );
        touch( $this->dir . 'GeoLite2-City.mmdb', time() );

        $c = $this->controller( array(
            'params'       => $this->withKey(),
            'remote_mtime' => time() - 86400,   // MaxMind's copy is older
        ) );
        $c->action();

        $this->assertSame( 1, $c->head_requests, 'it must ask before deciding' );
        $this->assertSame( 0, $c->downloads, 'nothing to fetch, so nothing should be fetched' );
        $this->assertSame( 'EXISTING', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
        $this->assertTrue( $c->said( 'Already current' ) );
    }

    public function testANewerRemoteCopyIsDownloaded(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );
        touch( $this->dir . 'GeoLite2-City.mmdb', time() - 86400 * 7 );

        $c = $this->controller( array(
            'params'       => $this->withKey(),
            'remote_mtime' => time(),
        ) );
        $c->action();

        $this->assertSame( 1, $c->downloads );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'a newer remote copy must replace the local one' );
    }

    public function testForceDownloadsEvenWhenCurrent(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );

        $c = $this->controller( array(
            'params'       => $this->withKey( array( 'force' => 1 ) ),
            'remote_mtime' => time() - 86400,
        ) );
        $c->action();

        $this->assertSame( 1, $c->downloads );
        $this->assertSame( 'FIXTURE-DATABASE-CONTENTS',
            file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ) );
    }

    /**
     * A failed download must leave the working database exactly as it was. It
     * is read by every tracking request while this runs.
     */
    public function testAFailedDownloadLeavesTheExistingDatabaseAlone(): void {

        file_put_contents( $this->dir . 'GeoLite2-City.mmdb', 'EXISTING' );

        $c = $this->controller( array(
            'params'         => $this->withKey( array( 'force' => 1 ) ),
            'download_fails' => 'MaxMind rejected the licence key.',
        ) );
        $c->action();

        $this->assertSame( 'EXISTING', file_get_contents( $this->dir . 'GeoLite2-City.mmdb' ),
            'the live database must survive a failed update' );
        $this->assertTrue( $c->said( 'rejected' ) );
    }

    /**
     * No key, no request. Worth asserting because the failure without it is an
     * HTTP error nobody can act on.
     */
    public function testNothingIsRequestedWithoutALicenceKey(): void {

        $config = \OWA\Core\CoreAPI::configSingleton();
        $previous_db = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_license_key' );
        $previous_ws = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_license_key' );

        $config->set( 'maxmind_geoip', 'db_license_key', '' );
        $config->set( 'maxmind_geoip', 'ws_license_key', '' );

        $c = $this->controller();
        $c->action();

        $config->set( 'maxmind_geoip', 'db_license_key', $previous_db );
        $config->set( 'maxmind_geoip', 'ws_license_key', $previous_ws );

        $this->assertSame( 0, $c->downloads );
        $this->assertSame( 0, $c->head_requests );
        $this->assertTrue( $c->said( 'licence key' ) );
    }

    public function testAnEditionTheModuleCannotReadIsRefusedBeforeAnyRequest(): void {

        $c = $this->controller( array(
            'params' => $this->withKey( array( 'edition' => 'GeoLite2-ASN' ) ),
        ) );
        $c->action();

        $this->assertSame( 0, $c->downloads );
        $this->assertTrue( $c->said( 'not an edition' ) );
    }
}
