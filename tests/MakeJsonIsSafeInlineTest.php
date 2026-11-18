<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Template::makeJson() emits a JS object literal into a <script> block.
 *
 * report.php assigns it to OWA.items[...].properties, and its input is
 * $view->params, which is URL-controlled. report_widgets.php reads it back
 * into a widget's description for addLinkToColumn().
 *
 * An HTML escape such as Sanitize::escapeForDisplay() (htmlentities()) is wrong
 * in this JavaScript context: it leaves a backslash untouched, and a backslash
 * is what a JS string literal treats as an escape.
 */
final class MakeJsonIsSafeInlineTest extends TestCase
{
    private function json( array $in ): string
    {
        return ( new \OWA\Core\Template() )->makeJson( $in );
    }

    private function decoded( array $in )
    {
        $out = json_decode( $this->json( $in ), true );

        $this->assertIsArray( $out, 'makeJson did not emit parseable output' );

        return $out;
    }

    /** It is valid JSON, which the hand-built version was not (bare keys). */
    public function testTheOutputParses(): void
    {
        $this->assertSame(
            [ 'reportId' => 'dashboard', 'siteId' => 'abc123' ],
            $this->decoded( [ 'reportId' => 'dashboard', 'siteId' => 'abc123' ] ) );
    }

    /**
     * A value cannot close the script block it is embedded in.
     *
     * @dataProvider breakoutAttempts
     */
    public function testAValueCannotEscapeTheScriptBlock( string $payload ): void
    {
        $json = $this->json( [ 'q' => $payload ] );

        /*
         * < > & ' never appear in JSON's own syntax, so any occurrence would be
         * a value character reaching the script raw. " is excluded from this
         * list because it IS the string delimiter -- the check for it is that
         * the payload does not appear verbatim, below.
         */
        foreach ( [ '<', '>', "'", '&' ] as $raw ) {
            $this->assertStringNotContainsString( $raw, $json,
                "a raw $raw reached the inline script" );
        }

        $this->assertStringNotContainsString( $payload, $json,
            'the payload reached the inline script unescaped' );

        // Exactly the four quotes of {"q":"..."} -- none from the value.
        $this->assertSame( 4, substr_count( $json, '"' ),
            'a value quote survived into the inline script' );

        // ...and the value still round-trips intact.
        $this->assertSame( $payload, json_decode( $json, true )['q'] );
    }

    public static function breakoutAttempts(): array
    {
        return [
            'closing tag'   => [ '</script><script>alert(1)</script>' ],
            'quote'         => [ 'he said "hi"' ],
            'single quote'  => [ "it's" ],
            'ampersand'     => [ 'a & b' ],
            'attribute'     => [ '" onmouseover=alert(1) x="' ],
        ];
    }

    /**
     * The backslash case, which is what HTML escaping got wrong.
     *
     * htmlentities() does not touch a backslash, so a value ending in one used
     * to be emitted as "value\" -- the backslash escaping the quote that was
     * supposed to terminate the string.
     */
    public function testATrailingBackslashDoesNotEscapeTheClosingQuote(): void
    {
        $payload = 'value\\';

        $this->assertSame( $payload, $this->decoded( [ 'q' => $payload ] )['q'] );

        // an HTML escape would leave the backslash alone
        $this->assertStringNotContainsString( 'value\\"',
            $this->json( [ 'q' => $payload ] ) );
    }

    /**
     * Stored values may be HTML-encoded; the JS should receive the true value.
     *
     * escapeForDisplay() decoded before re-encoding for the same reason, so this
     * preserves the behaviour rather than introducing it.
     */
    public function testStoredEntitiesAreDecodedForTheScript(): void
    {
        $this->assertSame( 'Grüße', $this->decoded( [ 'q' => 'Gr&uuml;&szlig;e' ] )['q'] );
        $this->assertSame( 'a & b', $this->decoded( [ 'q' => 'a &amp; b' ] )['q'] );
    }

    /** Values stay strings, as they always have been. */
    public function testValuesRemainStrings(): void
    {
        $out = $this->decoded( [ 'n' => 42, 'b' => true, 'f' => 1.5 ] );

        foreach ( $out as $key => $value ) {
            $this->assertIsString( $value, "$key stopped being a string" );
        }
    }

    /**
     * A list stays an object.
     *
     * report_widgets.php casts valueColumns with (array), which yields integer
     * keys. Those emitted `0: "x"` before and must not become a JS array.
     */
    public function testAListIsStillEmittedAsAnObject(): void
    {
        $json = $this->json( [ 'a', 'b' ] );

        $this->assertStringStartsWith( '{', $json );
        $this->assertSame( [ '0' => 'a', '1' => 'b' ], json_decode( $json, true ) );
    }

    /** An empty array is an empty object. */
    public function testAnEmptyArrayIsAnEmptyObject(): void
    {
        $this->assertSame( '{}', $this->json( [] ) );
    }
}
