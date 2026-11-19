<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Sanitize;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Sanitize::stripHtml() with a list of tags removes every one of them: each
 * pass works on the previous pass's result, and the closing-tag pattern
 * matches a whole tag name, so stripping b leaves </blockquote> alone.
 */
final class SanitizeStripHtmlTest extends TestCase
{
    public function testEveryListedTagIsStripped(): void
    {
        $html = '<p class="a">one</p><b>two</b><i>three</i>';

        $this->assertSame( 'onetwo<i>three</i>', Sanitize::stripHtml( $html, [ 'p', 'b' ] ) );
        $this->assertSame( 'onetwothree', Sanitize::stripHtml( $html, [ 'p', 'b', 'i' ] ) );
    }

    public function testATagIsMatchedByWholeNameNotByPrefix(): void
    {
        // b is not br, and </b> is not </blockquote>.
        $this->assertSame(
            '<br>x<blockquote>q</blockquote>',
            Sanitize::stripHtml( '<br><b>x</b><blockquote>q</blockquote>', [ 'b' ] )
        );
    }

    public function testATagNameIsNotAPattern(): void
    {
        // A name with regex metacharacters matches itself literally, and does
        // not break the expression.
        $this->assertSame( '<a>x</a>', Sanitize::stripHtml( '<a>x</a>', [ 'a.*' ] ) );
    }

    public function testWithoutAListEveryTagIsStripped(): void
    {
        $this->assertSame( 'onetwo', Sanitize::stripHtml( '<p>one</p><b>two</b>' ) );
    }

    /**
     * A constraint compares with < and <=. Neither starts a tag, so neither is
     * stripped, nor is anything after it.
     *
     * @dataProvider textWithALessThanSign
     */
    public function testALessThanSignThatStartsNoTagIsKept( string $text ): void
    {
        $this->assertSame( $text, Sanitize::stripHtml( $text ) );
    }

    /** @return array<string, array{string}> */
    public static function textWithALessThanSign(): array
    {
        return [
            'less than'           => [ 'pageViews<5' ],
            'less than or equal'  => [ 'pageViews<=5' ],
            'two constraints'     => [ 'pageViews<5,bounces<=1' ],
            'a spaced comparison' => [ 'a < b' ],
            'a negative number'   => [ 'revenue<-1' ],
        ];
    }

    /**
     * @dataProvider markup
     */
    public function testMarkupIsStripped( string $input, string $expected ): void
    {
        $this->assertSame( $expected, Sanitize::stripHtml( $input ) );
    }

    /** @return array<string, array{string, string}> */
    public static function markup(): array
    {
        return [
            'a closed tag'          => [ 'a<b>c</b>d', 'acd' ],
            'an unclosed tag'       => [ 'x<img src=x onerror=alert(1)', 'x' ],
            'a comment'             => [ 'a<!-- c -->b', 'ab' ],
            'an unclosed comment'   => [ 'a<!-- c', 'a' ],
            'a declaration'         => [ '<!DOCTYPE html>a', 'a' ],
            'a processing tag'      => [ 'a<?php echo 1; ?>b', 'ab' ],
            'a tag after a compare' => [ 'pageViews<5<script>x', 'pageViews<5x' ],
        ];
    }
}
