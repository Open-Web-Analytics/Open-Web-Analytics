<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every transition and animation in the stylesheets is switched off under
 * prefers-reduced-motion: reduce, by a rule for the same selector later in
 * the same file (WCAG 2.3.3).
 */
final class ReducedMotionTest extends TestCase
{
    private const QUERY = '@media (prefers-reduced-motion: reduce)';

    /** @return array<string, array{0: string}> */
    public static function stylesheets(): array
    {
        $out = array();

        foreach ( glob( \OWA\Core\Env::root() . '/modules/Base/css/*.css' ) as $file ) {
            $out[ basename( $file ) ] = array( $file );
        }

        return $out;
    }

    /**
     * The rules of a stylesheet: offset, selectors, declarations, and whether
     * the rule is inside a reduced-motion block.
     *
     * @return list<array{at: int, selectors: list<string>, body: string, reduced: bool}>
     */
    private static function rules( string $css ): array
    {
        $css    = preg_replace_callback( '#/\*.*?\*/#s', function ( $m ) { return str_repeat( ' ', strlen( $m[0] ) ); }, $css );
        $blocks = array();
        $from   = 0;

        while ( ( $at = strpos( $css, self::QUERY, $from ) ) !== false ) {

            $open  = strpos( $css, '{', $at );
            $depth = 0;

            for ( $i = $open; $i < strlen( $css ); $i++ ) {

                $depth += $css[ $i ] === '{' ? 1 : ( $css[ $i ] === '}' ? -1 : 0 );

                if ( $depth === 0 ) {
                    break;
                }
            }

            $blocks[] = array( $open, $i );
            $from     = $i;
        }

        preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

        $out = array();

        foreach ( $m as $rule ) {

            $at      = $rule[0][1];
            $reduced = false;

            foreach ( $blocks as list( $open, $close ) ) {
                $reduced = $reduced || ( $at > $open && $at < $close );
            }

            $out[] = array(
                'at'        => $at,
                'selectors' => array_map( function ( $s ) { return trim( preg_replace( '/\s+/', ' ', $s ) ); }, explode( ',', $rule[1][0] ) ),
                'body'      => $rule[2][0],
                'reduced'   => $reduced,
            );
        }

        return $out;
    }

    /** @dataProvider stylesheets */
    public function testEveryTransitionAndAnimationStopsUnderReducedMotion( string $file ): void
    {
        $rules  = self::rules( (string) file_get_contents( $file ) );
        $moving = array();

        foreach ( $rules as $rule ) {

            if ( $rule['reduced'] ) {
                continue;
            }

            foreach ( array( 'transition', 'animation' ) as $property ) {

                if ( ! preg_match( '/(?<![-\w])' . $property . '\s*:\s*(?!none\b)/', $rule['body'] ) ) {
                    continue;
                }

                foreach ( $rule['selectors'] as $selector ) {

                    $stopped = false;

                    foreach ( $rules as $later ) {

                        $stopped = $stopped || ( $later['reduced'] && $later['at'] > $rule['at']
                            && in_array( $selector, $later['selectors'], true )
                            && preg_match( '/(?<![-\w])' . $property . '\s*:\s*none\b/', $later['body'] ) );
                    }

                    if ( ! $stopped ) {
                        $moving[] = "$selector { $property }";
                    }
                }
            }
        }

        $this->assertSame( array(), $moving, basename( $file ) . ': moves under prefers-reduced-motion' );
    }

    /** The reader finds a transition, and a reduced-motion rule that stops it. */
    public function testTheReaderSeesARuleAndItsStop(): void
    {
        $rules = self::rules( ".a { transition: left 1s; }\n" . self::QUERY . " {\n .a { transition: none; }\n}\n.b { color: red; }" );

        $this->assertCount( 3, $rules );
        $this->assertFalse( $rules[0]['reduced'] );
        $this->assertTrue( $rules[1]['reduced'] );
        $this->assertFalse( $rules[2]['reduced'] );
        $this->assertSame( array( '.a' ), $rules[1]['selectors'] );
    }
}
