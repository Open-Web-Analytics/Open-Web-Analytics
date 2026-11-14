<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * On a phone or a touch screen, the controls a finger hits are 44px or more,
 * and every field is at least 16px so iOS does not zoom the page when it
 * takes focus.
 *
 * Read from the stylesheets: the rules inside a media block that applies at a
 * phone's width or to a coarse pointer, or where there is no hover.
 */
final class TouchTargetTest extends TestCase
{
    private const CSS = array( 'owa.css', 'owa.admin.css', 'owa.report.css', 'owa.grid.css', 'owa.shell.css' );

    /**
     * Selector => the properties that must reach 44px for it, in some touch
     * block. A property listed as a|b is met by either.
     */
    private const TARGETS = array(
        '.owa .owa_helpMenuToggle'                                  => array( 'width', 'height' ),
        '.owa .owa_notificationToggle'                              => array( 'width', 'height' ),
        '.owa .owa_userMenuToggle'                                  => array( 'min-width', 'height' ),
        '.owa .owa_notificationDismiss'                             => array( 'width', 'height' ),
        '.owa_siteControlEdit'                                      => array( 'min-width', 'min-height' ),
        '.owa_grid .owa_gridRowActions'                             => array( 'min-height' ),
        '.owa_grid .owa_gridSort'                                   => array( 'min-height' ),
        '.owa_grid .owa_gridButton'                                 => array( 'min-height' ),
        '.owa_grid .owa_gridIconButton'                             => array( 'width', 'height' ),
        '.owa_grid .owa_gridChipRemove'                             => array( 'width', 'height' ),
        '.owa_grid .owa_gridPageSize'                               => array( 'height' ),
        '.owa .owa_chartGranularity'                                => array( 'height' ),
        '.owa .owa_metricCarouselArrow'                             => array( 'width', 'min-height' ),
        '.owa .ui-tabs .ui-tabs-nav .ui-tabs-anchor'                => array( 'min-height' ),
        '.owa_confirmDialogFrame .ui-dialog-buttonpane button.owa-button' => array( 'min-height' ),
        '.owa .owa-override, .owa-override'                         => array( 'min-height' ),
        '.owa_periodDay'                                            => array( 'height' ),
    );

    /** Selectors whose type must be 16px or more in a touch block (iOS zooms a field below that). */
    private const SIXTEEN = array(
        '.owa_grid .owa_gridPageSize',
        '.owa .owa_chartGranularity',
    );

    /**
     * The rules inside the media blocks a phone or a touch screen matches.
     *
     * @return list<array{file: string, query: string, selectors: list<string>, body: string}>
     */
    private static function touchRules(): array
    {
        $out = array();

        foreach ( self::CSS as $file ) {

            $css  = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/css/' . $file ) );
            $from = 0;

            while ( preg_match( '/@media([^{]+)\{/', $css, $m, PREG_OFFSET_CAPTURE, $from ) ) {

                $query = trim( $m[1][0] );
                $open  = $m[0][1] + strlen( $m[0][0] ) - 1;
                $depth = 0;

                for ( $i = $open; $i < strlen( $css ); $i++ ) {

                    $depth += $css[ $i ] === '{' ? 1 : ( $css[ $i ] === '}' ? -1 : 0 );

                    if ( $depth === 0 ) {
                        break;
                    }
                }

                $from = $i;

                if ( ! preg_match( '/max-width:\s*600px|pointer:\s*coarse|hover:\s*none|max-height:\s*500px/', $query ) ) {
                    continue;
                }

                preg_match_all( '/([^{}]+)\{([^{}]*)\}/', substr( $css, $open + 1, $i - $open - 1 ), $rules, PREG_SET_ORDER );

                foreach ( $rules as $rule ) {
                    $out[] = array(
                        'file'      => $file,
                        'query'     => $query,
                        'selectors' => array_map( 'trim', explode( ',', preg_replace( '/\s+/', ' ', trim( $rule[1] ) ) ) ),
                        'body'      => $rule[2],
                    );
                }
            }
        }

        return $out;
    }

    private static function px( string $body, string $property ): ?float
    {
        return preg_match( '/(?<![-\w])' . preg_quote( $property, '/' ) . '\s*:\s*([\d.]+)px/', $body, $m ) ? (float) $m[1] : null;
    }

    /** A font size's px floor: `max(16px, 0.8125rem)` is never under 16px. */
    private static function fontFloor( string $body ): ?float
    {
        return preg_match( '/(?<![-\w])font-size\s*:\s*max\(\s*([\d.]+)px\s*,/', $body, $m ) ? (float) $m[1] : null;
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function targets(): array
    {
        $out = array();

        foreach ( self::TARGETS as $selector => $properties ) {
            $out[ $selector ] = array( $selector, $properties );
        }

        return $out;
    }

    /**
     * @dataProvider targets
     * @param list<string> $properties
     */
    public function testATouchTargetIsFortyFourPixels( string $selector, array $properties ): void
    {
        $wanted = array_map( 'trim', explode( ',', $selector ) );
        $best   = array_fill_keys( $properties, 0.0 );

        foreach ( self::touchRules() as $rule ) {

            if ( ! array_intersect( $wanted, $rule['selectors'] ) ) {
                continue;
            }

            foreach ( $properties as $property ) {
                $best[ $property ] = max( $best[ $property ], (float) self::px( $rule['body'], $property ) );
            }
        }

        foreach ( $best as $property => $px ) {
            $this->assertGreaterThanOrEqual( 44.0, $px, "$selector: $property on a phone or touch screen" );
        }
    }

    /** Nothing in a touch block sizes a listed control below 44px, as the header's 34px buttons were. */
    public function testNoTouchRuleShrinksATarget(): void
    {
        $short = array();
        $names = array();

        foreach ( array_keys( self::TARGETS ) as $selector ) {
            $names = array_merge( $names, array_map( 'trim', explode( ',', $selector ) ) );
        }

        foreach ( self::touchRules() as $rule ) {

            if ( ! array_intersect( $names, $rule['selectors'] ) ) {
                continue;
            }

            foreach ( array( 'width', 'height', 'min-width', 'min-height' ) as $property ) {

                $px = self::px( $rule['body'], $property );

                if ( $px !== null && $px > 0 && $px < 44 ) {
                    $short[] = sprintf( '%s: %s { %s: %dpx } in @media %s', $rule['file'], implode( ', ', $rule['selectors'] ), $property, $px, $rule['query'] );
                }
            }
        }

        $this->assertSame( array(), $short );
    }

    public function testFieldsAreAtLeastSixteenPixelsOnAPhone(): void
    {
        $sizes = array();

        foreach ( self::touchRules() as $rule ) {

            foreach ( $rule['selectors'] as $selector ) {

                if ( ( $px = self::fontFloor( $rule['body'] ) ) !== null ) {
                    $sizes[ $selector ] = max( $sizes[ $selector ] ?? 0, $px );
                }
            }
        }

        foreach ( self::SIXTEEN as $selector ) {
            $this->assertGreaterThanOrEqual( 16.0, $sizes[ $selector ] ?? 0, "$selector on a phone" );
        }

        // And every field, as a floor under each screen's own size.
        $this->assertSame( 16.0, $sizes['select'] ?? 0, 'every select on a phone' );
        $this->assertSame( 16.0, $sizes['textarea'] ?? 0, 'every textarea on a phone' );
        $this->assertNotEmpty( array_filter( array_keys( $sizes ), function ( $s ) {
            return strpos( $s, 'input:where(' ) === 0;
        } ), 'every text field on a phone' );
    }

    /** The phone sheet is also the picker on a phone held sideways. */
    public function testThePeriodSheetIsUsedOnAShortWindow(): void
    {
        $found = false;

        foreach ( self::touchRules() as $rule ) {

            if ( in_array( '.owa_periodPanel', $rule['selectors'], true )
                 && strpos( $rule['body'], 'position: fixed' ) !== false
                 && preg_match( '/max-width:\s*600px\),\s*\(max-height:\s*500px/', $rule['query'] ) ) {
                $found = true;
            }
        }

        $this->assertTrue( $found, 'the period sheet answers to (max-width: 600px), (max-height: 500px)' );
    }
}
