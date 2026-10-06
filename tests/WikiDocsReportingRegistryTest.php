<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The generated metrics, dimensions, raw-columns and cube-columns wiki sections
 * cover the whole of what they document: one row per registered metric and
 * dimension, per owa_event_raw column, and per column a cube adds to raw.
 *
 * Runs the generator as a separate process, the way the publish workflow does,
 * so a section that only works inside the test bootstrap fails here.
 */
final class WikiDocsReportingRegistryTest extends TestCase
{
    private static function generate( string $section ): string
    {
        $cmd = escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 '
             . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' ) . ' '
             . escapeshellarg( $section ) . ' 2>/dev/null';

        exec( $cmd, $lines, $status );
        $out = implode( "\n", $lines );

        self::assertSame( 0, $status, "generate.php $section failed:\n$out" );

        return $out;
    }

    /** @return string[] the first-column names, in order */
    private static function rowNames( string $md ): array
    {
        preg_match_all( '/^\| `([^`]+)` \|/m', $md, $m );

        return $m[1];
    }

    private static function assertExactlyCovers( array $expected, string $md, string $what ): void
    {
        self::assertNotEmpty( $expected, "no $what registered: the comparison would be vacuous" );

        $rows = self::rowNames( $md );
        sort( $expected );
        sort( $rows );

        self::assertSame( $expected, $rows, "one row per $what, and no others" );
    }

    public function testEveryMetricHasOneRow(): void
    {
        self::assertExactlyCovers( array_keys( \OWA\Core\CoreAPI::getAllMetrics() ),
            self::generate( 'metrics' ), 'metric' );
    }

    public function testEveryDimensionHasOneRow(): void
    {
        self::assertExactlyCovers( array_keys( \OWA\Core\CoreAPI::getAllDimensions() ),
            self::generate( 'dimensions' ), 'dimension' );
    }

    public function testMetricRowsStateHowEachIsComputed(): void
    {
        $md = self::generate( 'metrics' );

        // Read from the definitions, not restated: a conditioned count and a ratio.
        self::assertStringContainsString(
            '| `pageViews` | Page Views | The total number of pages viewed. | Count of events where `event_type` = `page_view` |', $md );
        self::assertStringContainsString( '`exits` ÷ `pageViews`', $md );
    }

    public function testEveryRawColumnHasOneRow(): void
    {
        $raw = new \OWA\Module\Base\Entity\EventRaw();

        self::assertExactlyCovers( $raw->getColumns(), self::generate( 'raw-columns' ), 'raw column' );
    }

    public function testRawColumnsNameTheTrackingPropertyThatFillsThem(): void
    {
        $md = self::generate( 'raw-columns' );

        // `column` differs from the property name for these two, so a row that
        // only echoed the column name would not pass.
        self::assertMatchesRegularExpression( '/^\| `visitor_fsts` \|.*`fsts` \(client\)/m', $md );
        self::assertMatchesRegularExpression( '/^\| `region` \|.*`state` \(event\)/m', $md );

        // Columns the cube does not carry are marked.
        self::assertMatchesRegularExpression( '/^\| `created_at` \|.*\| no \|$/m', $md );
    }

    public function testDescriptionForAnswersEveryTrackingProperty(): void
    {
        $helpers = '\\OWA\\Module\\Base\\Classes\\TrackingEventHelpers';
        $config  = json_decode( file_get_contents( OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

        self::assertNotEmpty( $config );

        foreach ( $config as $property => $definition ) {
            self::assertSame( (string) ( $definition['description'] ?? '' ), $helpers::descriptionFor( $property ),
                "descriptionFor('$property') does not answer the file's description" );
        }

        self::assertSame( '', $helpers::descriptionFor( 'no_such_property' ) );
    }

    public function testEveryRawColumnIsDescribed(): void
    {
        $md = self::generate( 'raw-columns' );

        // The column's own description where the entity declares one...
        self::assertMatchesRegularExpression( '/^\| `id` \| The event\'s id, /m', $md );

        // ...else the description of the tracking property that fills it.
        self::assertStringContainsString( '| `visitor_fsts` | '
            . \OWA\Module\Base\Classes\TrackingEventHelpers::descriptionFor( 'fsts' ) . ' |', $md );

        preg_match_all( '/^\| `([^`]+)` \| ([^|]*) \|/m', $md, $m, PREG_SET_ORDER );
        self::assertNotEmpty( $m );

        foreach ( $m as $row ) {
            self::assertNotContains( trim( $row[2] ), array( '', '—' ), "raw column {$row[1]} has no description" );
        }
    }

    public function testEveryDerivedCubeColumnIsDescribed(): void
    {
        $md = self::generate( 'cube-columns' );

        preg_match_all( '/^\| `([^`]+)` \| ([^|]*) \|/m', $md, $m, PREG_SET_ORDER );
        self::assertNotEmpty( $m );

        foreach ( $m as $row ) {
            self::assertNotContains( trim( $row[2] ), array( '', '—' ), "cube column {$row[1]} has no description" );
        }

        // A description is not one of a column's inputs.
        self::assertStringNotContainsString( 'Where the session came from', implode( "\n",
            array_map( fn ( $r ) => explode( ' | ', $r )[3] ?? '', explode( "\n", $md ) ) ) );
    }

    public function testEveryDerivedCubeColumnHasOneRow(): void
    {
        $raw  = new \OWA\Module\Base\Entity\EventRaw();
        $cube = new \OWA\Module\Base\Entity\Event();

        $derived = array_values( array_diff( $cube->getColumns(), $raw->getColumns() ) );

        self::assertExactlyCovers( $derived, self::generate( 'cube-columns' ), 'derived cube column' );

        // And the config agrees: every entry in cube_columns.php is one of them.
        $config = array_keys( (array) \OWA\Core\CoreAPI::loadConf( 'cube_columns.php', 'cube.columns' ) );
        sort( $config );
        sort( $derived );

        self::assertSame( $derived, $config );
    }
}
