<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Text colours against white, by the WCAG 2 contrast formula: 4.5:1 for text,
 * so a colour that falls short is either fixed or named below with the reason
 * it is exempt.
 */
final class TextContrastTest extends TestCase
{
    private const CSS = array( 'owa.css', 'owa.report.css', 'owa.grid.css', 'owa.admin.css', 'owa.shell.css', 'owa.widgets.css' );

    /** Selector => why its colour may be lighter than 4.5:1 on white. */
    private const EXEMPT = array(
        '.owa_periodDay:disabled'                                                     => 'a disabled control',
        '.owa .owa_editor .owa_button:disabled, .owa .owa_widgetPanel .owa_button:disabled' => 'a disabled control',
        '.owa_grid .owa_gridPopOption:disabled'                                       => 'a disabled control',
        '.owa_grid .owa_gridIconButton:disabled'                                      => 'a disabled control',
        '.owa_visitInfobox'                                                           => 'on a dark ground (#505050), not white; unused',
        '.owa_visitInfoboxTitle'                                                      => 'on a dark ground (#505050), not white; unused',
        '.inline_h2_grey'                                                             => 'unused',
        '.owa .externalUrl'                                                           => 'unused',
        '.owa_siteControlCaret'                                                       => 'an icon: 4.48:1 is past the 3:1 an icon needs',
    );

    /**
     * Glyphs that are graphical objects, not text, and the grounds each is
     * drawn on: 3:1 against every one of them (WCAG 1.4.11).
     */
    private const GRAPHICS = array(
        // The favourite star: on the roster's segmented control and its active segment.
        '.owa .owa_rosterFilter a i, .owa .owa_rosterStar.is-on'                      => array( '#ffffff', '#f3f5f8' ),
        '.owa .owa_rosterStar:hover, .owa .owa_rosterStar:focus'                      => array( '#f3f5f8' ),
        '.owa .owa_titleActionMark .fas.fa-star'                                      => array( '#ffffff', '#f3f5f8' ),
        // In the nav column, at rest, hovered and current.
        '.owa_navShell .owa_navColumnIcon.fa-star'                                    => array( '#ffffff', '#f3f5f8', '#eef4fb' ),
    );

    private static function luminance( string $hex ): float
    {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $c = array_map( function ( $i ) use ( $hex ) {
            $v = hexdec( substr( $hex, $i, 2 ) ) / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
        }, array( 0, 2, 4 ) );

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function ratio( string $a, string $b ): float
    {
        $x = self::luminance( $a );
        $y = self::luminance( $b );

        return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> file, selector, hex */
    private function textColours(): array
    {
        $out = array();

        foreach ( self::CSS as $file ) {

            $css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( OWA_DIR . 'modules/Base/css/' . $file ) );

            preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

            foreach ( $rules as $rule ) {

                // `color`, not border-color or background-color; a var() by its fallback.
                if ( preg_match_all( '/(?<![-\w])color\s*:\s*(?:var\(--[\w-]+,\s*)?(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\b/i', $rule[2], $m ) ) {

                    foreach ( $m[1] as $hex ) {
                        $out[] = array( $file, trim( preg_replace( '/\s+/', ' ', $rule[1] ) ), strtolower( $hex ) );
                    }
                }
            }
        }

        return $out;
    }

    public function testTheFormulaMatchesKnownPairs(): void
    {
        $this->assertEqualsWithDelta( 21.0, self::ratio( '#000000', '#ffffff' ), 0.01 );
        $this->assertEqualsWithDelta( 2.75, self::ratio( '#f47a17', '#ffffff' ), 0.01 );
        $this->assertEqualsWithDelta( 4.83, self::ratio( '#6b7280', '#ffffff' ), 0.01 );
    }

    public function testTextOnWhiteReadsAtFourAndAHalfToOne(): void
    {
        $short = array();

        foreach ( $this->textColours() as list( $file, $selector, $hex ) ) {

            if ( strtolower( $hex ) === '#ffffff' || strtolower( $hex ) === '#fff' ) {
                continue; // white text sits on a fill, checked below for the button
            }

            if ( array_key_exists( $selector, self::GRAPHICS ) ) {
                continue; // held to 3:1 below
            }

            if ( self::ratio( $hex, '#ffffff' ) < 4.5 && ! array_key_exists( $selector, self::EXEMPT ) ) {
                $short[] = sprintf( '%s: %s { color: %s } is %.2f:1', $file, $selector, $hex, self::ratio( $hex, '#ffffff' ) );
            }
        }

        $this->assertSame( array(), $short );
    }

    /** The exemptions and graphics name rules that exist, so a removed one does not linger here. */
    public function testEveryExemptionIsARule(): void
    {
        $selectors = array_map( function ( $c ) { return $c[1]; }, $this->textColours() );

        foreach ( array_merge( array_keys( self::EXEMPT ), array_keys( self::GRAPHICS ) ) as $selector ) {
            $this->assertContains( $selector, $selectors );
        }
    }

    public function testAGraphicReadsAtThreeToOneOnEachGroundItIsDrawnOn(): void
    {
        $short = array();

        foreach ( $this->textColours() as list( $file, $selector, $hex ) ) {

            foreach ( self::GRAPHICS[ $selector ] ?? array() as $ground ) {

                if ( self::ratio( $hex, $ground ) < 3.0 ) {
                    $short[] = sprintf( '%s: %s { color: %s } is %.2f:1 on %s', $file, $selector, $hex, self::ratio( $hex, $ground ), $ground );
                }
            }
        }

        $this->assertSame( array(), $short );
    }

    /** White on the button reads at 4.5:1 at rest and on hover; the logo keeps its orange. */
    public function testTheButtonFillCarriesWhiteText(): void
    {
        $owa = (string) file_get_contents( OWA_DIR . 'modules/Base/css/owa.css' );

        preg_match( '/\n\.owa-button \{[^}]*background-color:\s*var\(--owa-accent-fill,\s*(#[0-9a-f]{6})\)/i', $owa, $rest );
        preg_match( '/\.owa-button:hover,\s*\.owa-button:active \{[^}]*background-color:\s*var\(--owa-accent-fill-hover,\s*(#[0-9a-f]{6})\)/i', $owa, $hover );

        $this->assertNotEmpty( $rest, '.owa-button has no accent-fill background' );
        $this->assertNotEmpty( $hover, '.owa-button:hover has no accent-fill-hover background' );

        $this->assertGreaterThanOrEqual( 4.5, self::ratio( '#ffffff', $rest[1] ) );
        $this->assertGreaterThanOrEqual( 4.5, self::ratio( '#ffffff', $hover[1] ) );

        // The tokens and the fallbacks are the same colours.
        preg_match( '/:root \{([^}]*)\}/', (string) file_get_contents( OWA_DIR . 'modules/Base/css/owa.report.css' ), $root );
        $this->assertStringContainsString( '--owa-accent-fill: ' . $rest[1] . ';', $root[1] ?? '' );
        $this->assertStringContainsString( '--owa-accent-fill-hover: ' . $hover[1] . ';', $root[1] ?? '' );
    }

    /** A control that drops the browser's focus outline draws its own ring when focused from the keyboard. */
    public function testNoFocusStyleRemovesTheOutlineWithoutAReplacement(): void
    {
        $bare = array();

        foreach ( self::CSS as $file ) {

            $css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( OWA_DIR . 'modules/Base/css/' . $file ) );

            preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

            foreach ( $rules as $rule ) {

                if ( ! preg_match( '/outline\s*:\s*(none|0)\b/', $rule[2] ) ) {
                    continue;
                }

                $selector = trim( preg_replace( '/\s+/', ' ', $rule[1] ) );

                // Replaced in the same rule by a border and ring of the focus colour...
                if ( preg_match( '/box-shadow\s*:[^;]*#1874cd|box-shadow\s*:[^;]*var\(--owa-period-blue\)/i', $rule[2] ) ) {
                    continue;
                }

                // ...or by a :focus-visible / :focus-within rule for the same control.
                $base = preg_replace( '/:(focus|hover)(-visible)?\b/', '', explode( ',', $selector )[0] );
                $ring = '/' . preg_quote( trim( $base ), '/' ) . '(:focus-visible|:focus-within)?\s*\{[^}]*outline\s*:\s*2px solid/';

                if ( preg_match( $ring, $css ) ) {
                    continue;
                }

                $bare[] = "$file: $selector";
            }
        }

        $this->assertSame( array(
            // The field draws the ring on its box (.owa_periodField:focus-within).
            'owa.report.css: .owa_periodField input:focus',
        ), $bare );
    }
}
