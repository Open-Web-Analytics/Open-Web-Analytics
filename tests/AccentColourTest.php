<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The UI's orange is the logo's: one token, --owa-accent on :root in
 * owa.report.css, set to the orange fill of modules/Base/i/owa-logo.svg.
 */
final class AccentColourTest extends TestCase
{
    private const CSS = array( 'owa.css', 'owa.report.css', 'owa.admin.css', 'owa.widgets.css', 'owa.overlay.css' );

    private function css( string $file ): string
    {
        return (string) file_get_contents( OWA_DIR . 'modules/Base/css/' . $file );
    }

    private function logoOrange(): string
    {
        $svg = (string) file_get_contents( OWA_DIR . 'modules/Base/i/owa-logo.svg' );
        preg_match_all( '/#([0-9a-f]{6})\b/i', $svg, $m );

        foreach ( array_unique( array_map( 'strtolower', $m[1] ) ) as $hex ) {

            if ( hexdec( substr( $hex, 0, 2 ) ) > 200 && hexdec( substr( $hex, 4, 2 ) ) < 100 ) {

                return '#' . $hex;
            }
        }

        $this->fail( 'The logo has no orange fill.' );
    }

    public function testTheAccentTokenIsTheLogosOrange(): void
    {
        preg_match( '/:root \{([^}]*)\}/', $this->css( 'owa.report.css' ), $root );
        preg_match( '/--owa-accent:\s*(#[0-9a-f]{6})\s*;/i', $root[1] ?? '', $token );

        $this->assertSame( $this->logoOrange(), strtolower( $token[1] ?? '' ) );
    }

    /** A fallback, for a page that loads a stylesheet without owa.report.css, is the same colour. */
    public function testEveryFallbackIsTheLogosOrange(): void
    {
        foreach ( self::CSS as $file ) {

            preg_match_all( '/var\(--owa-accent,\s*([^)]+)\)/', $this->css( $file ), $m );

            foreach ( $m[1] as $fallback ) {

                $this->assertSame( $this->logoOrange(), strtolower( trim( $fallback ) ), $file );
            }
        }
    }

    /** No other orange: the named colour, or the overlay's old #FF8C00. */
    public function testNoStylesheetUsesAnotherOrange(): void
    {
        foreach ( self::CSS as $file ) {

            $rules = preg_replace( '#/\*.*?\*/#s', '', $this->css( $file ) );

            $this->assertDoesNotMatchRegularExpression( '/\borange\b|#ff8c00\b|#ffa500\b/i', $rules, $file );
        }
    }
}
