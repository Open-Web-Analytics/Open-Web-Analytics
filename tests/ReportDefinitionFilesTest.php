<?php

use PHPUnit\Framework\TestCase;

// At file scope, not in setUpBeforeClass(): data providers run before it, and
// the providers below read the harness.
require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * Every report definition is one the renderer will accept, and the renderer
 * refuses the ones it cannot honour.
 *
 * This was ReportConfigEquivalenceTest, which held each converted report to a
 * record of what its controller declared and then to a hand-kept list of which
 * reports existed. Both were scaffolding for the conversion, which is finished.
 * Whether each file is REGISTERED is asked of the live registry now, in
 * ReportRegistryTest; whether each one renders silently is asked of every report
 * in ReportCharacterizationTest.
 */
final class ReportDefinitionFilesTest extends TestCase
{
    /** Every definition file, by the id it is registered under. */
    public static function definitionProvider(): array
    {
        $cases = array();

        foreach ( Harness::definitionIds() as $id ) {
            $cases[ $id ] = array( $id );
        }

        return $cases;
    }

    /**
     * It parses, and the renderer would accept it.
     *
     * @dataProvider definitionProvider
     */
    public function testTheDefinitionIsValid( string $id ): void
    {
        $path = Harness::definitionPath( $id );

        $definition = json_decode( (string) file_get_contents( $path ), true );

        $this->assertSame( JSON_ERROR_NONE, json_last_error(),
            "report '$id' has a definition file that is not valid JSON: " . json_last_error_msg() );

        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( $definition ),
            "report '$id' has a definition the renderer would refuse" );
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
            'no title'        => array( array( 'metrics' => 'sessions' ), 'needs a "title"' ),
            'empty title'     => array( array( 'title' => '' ), 'needs a "title"' ),
            'names a renderer' => array(
                array( 'title' => 'Pages', 'subview' => 'base.reportWidgets' ), 'unknown key' ),

            // The failure this is really for: a key that looks right, does
            // nothing, and says nothing.
            'misspelled key'  => array(
                array( 'title' => 'Pages', 'setings' => array() ), 'unknown key' ),

            'settings scalar' => array(
                array( 'title' => 'Pages', 'settings' => 'metrics' ), 'must be an object' ),

            'description not a string' => array(
                array( 'title' => 'Pages', 'description' => array( 'Page views' ) ), '"description" must be a string' ),
        );
    }

    /** A definition that is fine must not be refused by the guard above. */
    public function testAGoodDefinitionIsAccepted(): void
    {
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'       => 'Web Pages',
            'titleSuffix' => '',
            'description' => 'Page views by page.',
            'settings'    => array( 'metrics' => 'pageViews' ),
        ) ) );
    }
}
