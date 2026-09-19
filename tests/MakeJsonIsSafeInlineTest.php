<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Template::makeJson() emits a JS object literal into a <script> block.
 *
 * Both callers do exactly that -- report.php assigns it to
 * OWA.items[...].properties and report_widgets.php passes it to
 * addLinkToColumn() -- and report.php's input is $view->params, which is
 * URL-controlled.
 *
 * It used to be assembled by string concatenation and escaped with
 * Sanitize::escapeForDisplay(), which is htmlentities(): an HTML escape applied
 * to a JavaScript context. It leaves a backslash untouched, and a backslash is
 * what a JS string literal treats as an escape.
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
            array( 'reportId' => 'dashboard', 'siteId' => 'abc123' ),
            $this->decoded( array( 'reportId' => 'dashboard', 'siteId' => 'abc123' ) ) );
    }

    /**
     * A value cannot close the script block it is embedded in.
     *
     * @dataProvider breakoutAttempts
     */
    public function testAValueCannotEscapeTheScriptBlock( string $payload ): void
    {
        $json = $this->json( array( 'q' => $payload ) );

        /*
         * < > & ' never appear in JSON's own syntax, so any occurrence would be
         * a value character reaching the script raw. " is excluded from this
         * list because it IS the string delimiter -- the check for it is that
         * the payload does not appear verbatim, below.
         */
        foreach ( array( '<', '>', "'", '&' ) as $raw ) {
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
        return array(
            'closing tag'   => array( '</script><script>alert(1)</script>' ),
            'quote'         => array( 'he said "hi"' ),
            'single quote'  => array( "it's" ),
            'ampersand'     => array( 'a & b' ),
            'attribute'     => array( '" onmouseover=alert(1) x="' ),
        );
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

        $this->assertSame( $payload, $this->decoded( array( 'q' => $payload ) )['q'] );

        // the old escape left the backslash alone; assert we no longer do
        $this->assertStringNotContainsString( 'value\\"',
            $this->json( array( 'q' => $payload ) ) );
    }

    /**
     * Stored values may be HTML-encoded; the JS should receive the true value.
     *
     * escapeForDisplay() decoded before re-encoding for the same reason, so this
     * preserves the behaviour rather than introducing it.
     */
    public function testStoredEntitiesAreDecodedForTheScript(): void
    {
        $this->assertSame( 'Grüße', $this->decoded( array( 'q' => 'Gr&uuml;&szlig;e' ) )['q'] );
        $this->assertSame( 'a & b', $this->decoded( array( 'q' => 'a &amp; b' ) )['q'] );
    }

    /** Values stay strings, as they always have been. */
    public function testValuesRemainStrings(): void
    {
        $out = $this->decoded( array( 'n' => 42, 'b' => true, 'f' => 1.5 ) );

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
        $json = $this->json( array( 'a', 'b' ) );

        $this->assertStringStartsWith( '{', $json );
        $this->assertSame( array( '0' => 'a', '1' => 'b' ), json_decode( $json, true ) );
    }

    /** An empty array used to return '}' on its own. */
    public function testAnEmptyArrayIsAnEmptyObject(): void
    {
        $this->assertSame( '{}', $this->json( array() ) );
    }
}
