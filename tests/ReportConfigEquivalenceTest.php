<?php

use PHPUnit\Framework\TestCase;

// At file scope, not in setUpBeforeClass(): data providers run before it, and
// the providers below read the harness.
require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * Every converted report has a definition, and that definition is usable.
 *
 * This file used to hold each definition to a RECORD of what its controller
 * declared, key by key and type by type -- the gate that made the conversion
 * safe while the controllers were being deleted underneath it.
 *
 * That gate is retired. It had done its job by the time the conversion merged,
 * and everything after that was a deliberate redesign asking to be let past:
 * seven allowance lists and their undo routines, one entry per report per
 * change, all added AFTER the migration landed. A test whose only remaining
 * answer is "this differs from what a controller declared in August 2026" is
 * measuring the calendar, not the code.
 *
 * What survives is the part that was never about the record: every converted id
 * still has a definition file, that file is well formed, nothing claims an id
 * twice, and rendering it raises no diagnostic. What a report EMITS is pinned
 * separately, against current behaviour, by ReportRenderCharacterizationTest.
 */
final class ReportConfigEquivalenceTest extends TestCase
{
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
