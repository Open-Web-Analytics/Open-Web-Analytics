<?php

use PHPUnit\Framework\TestCase;

/**
 * The GeoIP download reads the HTTP status and Last-Modified from the stream's
 * own metadata, against a real http:// server on loopback.
 *
 * MaxmindDownloadFlowTest replaces download() and lastModified() wholesale, so
 * the header handling inside them is only reached here.
 */
final class MaxmindResponseHeadersTest extends TestCase {

    /** @var resource|null */
    private static $server;
    private static string $base = '';
    private static string $docroot = '';

    public static function setUpBeforeClass(): void {

        self::$docroot = sys_get_temp_dir() . '/owa-maxmind-headers-' . bin2hex( random_bytes( 4 ) );
        mkdir( self::$docroot );

        file_put_contents( self::$docroot . '/router.php', <<<'PHP'
<?php
switch ( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) {
    case '/denied':
        http_response_code( 401 );
        echo 'Invalid license key';
        break;
    case '/db':
        header( 'Last-Modified: Tue, 03 Nov 2026 10:00:00 GMT' );
        echo 'archive-bytes';
        break;
    case '/moved':
        header( 'Location: /db', true, 302 );
        break;
    default:
        http_response_code( 404 );
}
PHP
        );

        $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
        $name  = stream_socket_get_name( $probe, false );
        fclose( $probe );

        self::$base   = 'http://' . $name;
        self::$server = proc_open(
            [ PHP_BINARY, '-S', $name, self::$docroot . '/router.php' ],
            [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ],
            $pipes );

        for ( $i = 0; $i < 50; $i++ ) {

            $sock = @fsockopen( '127.0.0.1', (int) substr( $name, strrpos( $name, ':' ) + 1 ), $errno, $errstr, 0.1 );

            if ( $sock ) {
                fclose( $sock );
                return;
            }

            usleep( 100000 );
        }
    }

    public static function tearDownAfterClass(): void {

        if ( is_resource( self::$server ) ) {
            proc_terminate( self::$server );
            proc_close( self::$server );
        }

        foreach ( glob( self::$docroot . '/*' ) ?: [] as $f ) { unlink( $f ); }
        @rmdir( self::$docroot );
    }

    protected function setUp(): void {

        if ( ! is_resource( self::$server ) || ! proc_get_status( self::$server )['running'] ) {
            $this->markTestSkipped( 'could not start a loopback php -S server' );
        }
    }

    private function controller() {

        return new class extends \OWA\Module\MaxmindGeoip\Controller\UpdateGeoipDbCli {

            public function __construct() {}

            public function fetch( $url, $destination ) { return $this->download( $url, $destination ); }

            public function modified( $url ) { return $this->lastModified( $url ); }
        };
    }

    public function testARejectedKeyIsReportedAndNothingIsWritten(): void {

        $dest = self::$docroot . '/out.tar.gz';

        $result = $this->controller()->fetch( self::$base . '/denied', $dest );

        $this->assertIsString( $result );
        $this->assertStringContainsString( 'rejected the licence key', $result );
        $this->assertFileDoesNotExist( $dest );
    }

    public function testASuccessfulResponseIsWritten(): void {

        $dest = self::$docroot . '/ok.tar.gz';

        $this->assertSame( 13, $this->controller()->fetch( self::$base . '/db', $dest ) );
        $this->assertSame( 'archive-bytes', file_get_contents( $dest ) );
    }

    public function testLastModifiedIsReadFromTheResponse(): void {

        $this->assertSame( strtotime( 'Tue, 03 Nov 2026 10:00:00 GMT' ),
            $this->controller()->modified( self::$base . '/db' ) );
    }

    public function testLastModifiedIsFoundAfterARedirect(): void {

        $this->assertSame( strtotime( 'Tue, 03 Nov 2026 10:00:00 GMT' ),
            $this->controller()->modified( self::$base . '/moved' ) );
    }

    public function testARejectedHeadRequestGivesNoDate(): void {

        $this->assertSame( 0, $this->controller()->modified( self::$base . '/denied' ) );
    }
}
