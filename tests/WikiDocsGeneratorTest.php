<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/tools/wiki/generate.php';

use PHPUnit\Framework\TestCase;

/**
 * tests/tools/wiki/generate.php: the splice, the section resolution, and that
 * every section it ships generates something. What each section must contain is
 * asserted by that section's own WikiDocs*Test.
 */
final class WikiDocsGeneratorTest extends TestCase
{
    private static function fixed(): callable
    {
        return fn ( $name ) => "[$name]";
    }

    public function testSpliceReplacesOnlyTheNamedSection(): void
    {
        $page = "intro\n<!-- BEGIN GENERATED: jobs -->\nold\n<!-- END GENERATED: jobs -->\noutro\n";

        $this->assertSame(
            "intro\n<!-- BEGIN GENERATED: jobs -->\n\n[jobs]\n\n<!-- END GENERATED: jobs -->\noutro\n",
            owa_wiki_splice( $page, self::fixed() ) );
    }

    public function testSpliceHandlesSeveralSectionsOnOnePage(): void
    {
        $page = "<!-- BEGIN GENERATED: a -->x<!-- END GENERATED: a -->\n"
              . "<!-- BEGIN GENERATED: b-c -->y<!-- END GENERATED: b-c -->";

        $new = owa_wiki_splice( $page, self::fixed() );

        $this->assertStringContainsString( "\n[a]\n", $new );
        $this->assertStringContainsString( "\n[b-c]\n", $new );
    }

    public function testSpliceIsIdempotent(): void
    {
        $page = "<!-- BEGIN GENERATED: a -->\nanything\n<!-- END GENERATED: a -->";
        $once = owa_wiki_splice( $page, self::fixed() );

        $this->assertSame( $once, owa_wiki_splice( $once, self::fixed() ) );
    }

    public function testSpliceLeavesTheUnnamedOneXMarkersAlone(): void
    {
        $page = "<!-- BEGIN GENERATED -->\n1.x tables\n<!-- END GENERATED -->";

        $this->assertSame( $page, owa_wiki_splice( $page, self::fixed() ) );
    }

    public function testSpliceRefusesABeginWithoutItsEnd(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'jobs' );

        owa_wiki_splice( "<!-- BEGIN GENERATED: jobs -->\nold\n<!-- END GENERATED: cli -->",
            self::fixed() );
    }

    public function testAnUnknownSectionIsAnError(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'no-such-section' );

        owa_wiki_generate( 'no-such-section' );
    }

    public function testASuffixResolvesToThePrefixSectionWithAnArgument(): void
    {
        $files = owa_wiki_section_files();

        if ( ! isset( $files['settings'] ) ) {
            $this->markTestSkipped( 'no settings section' );
        }

        $this->assertSame( [ $files['settings'], 'tracking_tag' ], owa_wiki_resolve( 'settings-tracking_tag' ) );
    }

    public function testTheLongestMatchingSectionFileWins(): void
    {
        $files = owa_wiki_section_files();

        if ( ! isset( $files['event-properties'] ) ) {
            $this->markTestSkipped( 'no event-properties section' );
        }

        $this->assertSame( [ $files['event-properties'], 'click' ], owa_wiki_resolve( 'event-properties-click' ) );
        $this->assertSame( [ $files['event-properties'], null ], owa_wiki_resolve( 'event-properties' ) );
    }

    public static function sections(): array
    {
        $out = [];

        foreach ( array_keys( owa_wiki_section_files() ) as $name ) {
            $out[ $name ] = [ $name ];
        }

        return $out;
    }

    /**
     * @dataProvider sections
     */
    public function testEverySectionGeneratesATable( string $name ): void
    {
        $md = owa_wiki_generate( $name );

        $this->assertMatchesRegularExpression( '/^\|.*\|$/m', $md, "$name produced no table" );
    }
}
