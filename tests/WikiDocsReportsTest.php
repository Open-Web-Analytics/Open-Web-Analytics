<?php

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\ChannelStep;
use OWA\Module\Base\Classes\Cube\SiteLists;
use PHPUnit\Framework\TestCase;

/**
 * The `reports`, `channels` and `site-lists` wiki sections list everything the
 * code ships.
 *
 * Each section runs in its own process, as the publish workflow runs it: the
 * reports section loads every shipped module into the service, which must not
 * leak into the tests that follow.
 */
final class WikiDocsReportsTest extends TestCase
{
    private static array $out = array();

    private static function section( string $name ): string
    {
        if ( ! isset( self::$out[ $name ] ) ) {

            $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' )
                 . ' ' . escapeshellarg( $name ) . ' 2>/dev/null';

            exec( $cmd, $lines, $status );

            self::$out[ $name ] = $status === 0 ? implode( "\n", $lines ) : '';
        }

        return self::$out[ $name ];
    }

    /**
     * The report ids registered by shipped modules, read from their source --
     * independent of which modules this process happens to have active.
     *
     * @return array<string,string> id => module directory
     */
    private static function registeredReportIds(): array
    {
        $ids = array();

        foreach ( glob( OWA_DIR . 'modules/*/Module.php' ) as $path ) {

            $module = basename( dirname( $path ) );

            if ( $module === 'Hello' ) {
                continue;
            }

            preg_match_all( "/registerReport\(\s*'([^']+)'/", (string) file_get_contents( $path ), $m );

            foreach ( $m[1] as $id ) {
                $ids[ $id ] = $module;
            }
        }

        return $ids;
    }

    public function testEveryShippedReportIsListed(): void
    {
        $md  = self::section( 'reports' );
        $ids = self::registeredReportIds();

        $this->assertNotSame( '', $md, 'the reports section failed' );
        $this->assertGreaterThan( 30, count( $ids ), 'the source scan found too few reports to be the real list' );

        foreach ( $ids as $id => $module ) {
            $this->assertStringContainsString( "| `$id` |", $md, "report $id ($module) is not on the page" );
        }
    }

    public function testEveryShippedReportFileHasADescription(): void
    {
        $files = array_filter( glob( OWA_DIR . 'modules/*/reports/*.json' ),
            fn ( $p ) => basename( dirname( $p, 2 ) ) !== 'Hello' );

        $this->assertGreaterThan( 30, count( $files ) );

        foreach ( $files as $path ) {

            $d = json_decode( (string) file_get_contents( $path ), true );

            $this->assertIsString( $d['description'] ?? null, basename( $path ) . ' has no description' );
            $this->assertNotSame( '', trim( $d['description'] ), basename( $path ) . ' has an empty description' );
        }
    }

    public function testEveryListedReportShowsItsDescription(): void
    {
        $md = self::section( 'reports' );

        foreach ( array_keys( self::registeredReportIds() ) as $id ) {

            $this->assertSame( 1, preg_match( '/^\| [^|]+ \| `' . preg_quote( $id, '/' ) . '` \| ([^|]+) \|/m', $md, $m ),
                "no row for $id" );
            $this->assertNotSame( '—', trim( $m[1] ), "report $id has no description, controller reports included" );
        }
    }

    public function testAReportFromAnInactiveShippedModuleIsListed(): void
    {
        // Domstream is not active on a configless boot; the page documents it anyway.
        $this->assertStringContainsString( "| Recordings | `domstreams` |", self::section( 'reports' ) );
    }

    public function testTheDeveloperExampleIsLeftOut(): void
    {
        $this->assertStringNotContainsString( 'hello-dashboard', self::section( 'reports' ) );
    }

    public function testReportsAreGroupedInNavigationOrder(): void
    {
        $md = self::section( 'reports' );

        preg_match_all( '/^### (.+)$/m', $md, $m );

        $this->assertSame( 'Dashboard', $m[1][0] ?? null );
        $this->assertSame( 'Reached from other reports', end( $m[1] ) );
        $this->assertLessThan( array_search( 'Content', $m[1], true ), array_search( 'Traffic', $m[1], true ) );
    }

    public function testADetailReportSaysWhatItNeedsAndWhereItIsReached(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\| Page Detail \| `document` \|.*the `pagePath` URL parameter \|.*`pages`.* \|$/m',
            self::section( 'reports' ) );
    }

    public function testEveryChannelRuleIsListedInOrder(): void
    {
        $md = self::section( 'channels' );

        foreach ( ChannelStep::definitions( OWA_CONF_DIR ) as $i => $rule ) {

            $this->assertStringContainsString( sprintf( '| %d | %s |', $i + 1, $rule['channel'] ), $md );
        }

        $this->assertStringContainsString( '| — | Unassigned |', $md );
    }

    public function testEverySiteListEntryIsListed(): void
    {
        $md = self::section( 'site-lists' );

        foreach ( SiteLists::FILES as $list => $spec ) {

            $this->assertStringContainsString( "| `$list` |", $md );

            foreach ( (array) include OWA_CONF_DIR . $spec[0] as $entry ) {

                $domain = strtolower( is_array( $entry ) ? (string) ( $entry['domain'] ?? '' ) : (string) $entry );

                $this->assertStringContainsString( "`$domain`", $md, "$domain ($list) is not on the page" );
            }
        }
    }
}
