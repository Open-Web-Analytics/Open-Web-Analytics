<?php

use PHPUnit\Framework\TestCase;

// At file scope, not in setUpBeforeClass(): data providers run before it, and
// the providers below read the harness.
require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * The 35 converted reports must declare exactly what their controllers did.
 *
 * A report is the query it will issue and the widgets it declares. Those
 * controllers expressed both as a bag of key/value pairs, the golden file
 * recorded that bag for every one of them, and this holds the JSON to it --
 * key by key, value by value, including types.
 *
 * The comparison is against the RECORD rather than against the live
 * controllers, which is what lets the controllers be deleted while the standard
 * they set stays enforced. A conversion checked only by diffing against code
 * that is about to be removed stops being checked the moment it lands.
 */
final class ReportConfigEquivalenceTest extends TestCase
{
    /** @var array<string, array> */
    private static $golden = array();

    public static function setUpBeforeClass(): void
    {
        self::$golden = (array) json_decode(
            (string) file_get_contents( Harness::goldenPath() ), true );
    }

    public static function convertedProvider(): array
    {
        // Reading the map rather than the directory: a definition file that
        // exists but is not claimed by a converted report should show up as a
        // missing test subject, not silently go unchecked.
        $cases = array();

        foreach ( Harness::CONVERTED as $id => $class ) {
            $cases[ $id ] = array( $id, $class );
        }

        return $cases;
    }

    /**
     * @dataProvider convertedProvider
     */
    public function testTheDefinitionDeclaresWhatTheControllerDeclared( string $id, string $class ): void
    {
        $this->assertArrayHasKey( $class, self::$golden,
            "$class has no recorded behaviour, so converting it cannot be checked" );

        $expected = self::$golden[ $class ]['config'];
        $actual   = Harness::snapshotConfigured( $id )['config'];

        /*
         * `deprecated` is the one key a definition may add that no controller
         * ever declared. It says the report is still here but no longer
         * filling -- a fact about the data, not about the conversion.
         *
         * Dropped from the comparison rather than written into the golden file:
         * the golden records what the CONTROLLER declared, and no controller
         * ever declared this. Writing it there would make the record claim
         * something untrue about code that no longer exists. The key's own
         * behaviour is pinned in ReportDefinitionFormatTest.
         *
         * Unset from $actual only, so a definition that DROPS a real key still
         * fails below.
         */
        unset( $actual['deprecated'] );

        $actual = $this->undoRetyping( $class, $actual );

        /*
         * ...and a report that has been deliberately relaid out is reconciled
         * with the record on position and span alone, so everything else about
         * every widget is still compared. See Harness::RELAID_OUT.
         */
        $layout = Harness::normaliseLayout( $class, $expected, $actual );

        $this->assertSame( array(), $layout['problems'],
            "the relayout allowance for $class does not match the definition:\n  "
            . implode( "\n  ", $layout['problems'] ) );

        $expected = $layout['expected'];
        $actual   = $layout['actual'];

        // Whole-bag comparison, not key-by-key: a conversion that DROPPED a key
        // would pass every per-key assertion that only looks at keys present in
        // both.
        $this->assertSame( $expected, $actual,
            "report '$id' does not declare what $class declared" );
    }

    /**
     * Put a deliberately re-typed widget back to what the controller declared,
     * so everything ELSE about it is still compared.
     *
     * The list lives in the harness because the characterization test reads the
     * same fixture and needs the same allowance -- see Harness::RETYPED.
     */
    private function undoRetyping( string $class, array $config ): array
    {
        $result = Harness::undoRetyping( $class, $config );

        $this->assertSame( array(), $result['problems'],
            "the re-typing allowance for $class does not match the definition:\n  "
            . implode( "\n  ", $result['problems'] ) );

        return $result['config'];
    }

    /**
     * @dataProvider convertedProvider
     */
    public function testTheDefinitionRaisesNoDiagnostics( string $id, string $class ): void
    {
        $this->assertSame( array(), Harness::snapshotConfigured( $id )['diagnostics'],
            "rendering report '$id' from its definition raised a diagnostic" );
    }

    /**
     * Every converted report has a definition file, and it parses.
     *
     * Separate from the equivalence test because a missing or malformed file
     * fails that one with a comparison error, which reads like a behaviour
     * difference rather than a missing file.
     *
     * @dataProvider convertedProvider
     */
    public function testTheDefinitionFileExistsAndIsValid( string $id, string $class ): void
    {
        $path = Harness::definitionPath( $id );

        $this->assertFileExists( $path, "report '$id' has no definition file" );

        $definition = json_decode( (string) file_get_contents( $path ), true );

        $this->assertSame( JSON_ERROR_NONE, json_last_error(),
            "report '$id' has a definition file that is not valid JSON: " . json_last_error_msg() );

        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( $definition ),
            "report '$id' has a definition the renderer would refuse" );
    }

    /**
     * Nothing in the reports directory is unaccounted for.
     *
     * Catches the reverse of a missing file: a definition left behind by a
     * rename, which would sit there being loaded by nobody and diverging from
     * the report it looks like it belongs to.
     *
     * Accounted for by EITHER map. Every definition used to be a conversion, so
     * CONVERTED was the whole ledger; an authored report has no controller to
     * be listed against, and refusing it would have the harness forbid the
     * thing the format is for.
     */
    public function testEveryDefinitionFileBelongsToAConvertedReport(): void
    {
        $onDisk = array();

        foreach ( glob( OWA_DIR . 'modules/Base/reports/*.json' ) as $file ) {
            $onDisk[] = basename( $file, '.json' );
        }

        sort( $onDisk );

        $claimed = array_merge( array_keys( Harness::CONVERTED ), Harness::AUTHORED );
        sort( $claimed );

        $this->assertSame( $claimed, $onDisk,
            'the reports directory and the converted-report map disagree' );
    }

    /**
     * The renderer refuses a definition it cannot honour rather than rendering
     * a partial report.
     *
     * @dataProvider badDefinitionProvider
     */
    public function testABadDefinitionIsRefused( $definition, string $because ): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( $definition );

        $this->assertNotSame( '', $error, 'this definition should not be accepted' );
        $this->assertStringContainsString( $because, $error );
    }

    public static function badDefinitionProvider(): array
    {
        return array(
            'not an object'   => array( 'pages', 'must be an object' ),
            'no title'        => array( array( 'metrics' => 'visits' ), 'needs a "title"' ),
            'empty title'     => array( array( 'title' => '' ), 'needs a "title"' ),
            'names a renderer' => array(
                array( 'title' => 'Pages', 'subview' => 'base.reportWidgets' ), 'unknown key' ),

            // The failure this is really for: a key that looks right, does
            // nothing, and says nothing.
            'misspelled key'  => array(
                array( 'title' => 'Pages', 'setings' => array() ), 'unknown key' ),

            'settings scalar' => array(
                array( 'title' => 'Pages', 'settings' => 'metrics' ), 'must be an object' ),
        );
    }

    /** A definition that is fine must not be refused by the guard above. */
    public function testAGoodDefinitionIsAccepted(): void
    {
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'       => 'Web Pages',
            'titleSuffix' => '',
            'settings'    => array( 'metrics' => 'pageViews' ),
        ) ) );
    }
}
