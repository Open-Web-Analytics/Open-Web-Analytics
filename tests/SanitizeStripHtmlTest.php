<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Sanitize;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Sanitize::stripHtml() with a list of tags removes every one of them.
 *
 * Each pass used to start again from the original input, so only the last
 * tag in the list was removed; and the closing-tag pattern matched by prefix,
 * so stripping b also removed </blockquote>.
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
}
