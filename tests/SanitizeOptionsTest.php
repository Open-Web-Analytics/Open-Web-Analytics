<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Sanitize;

/**
 * Each cleanInput() step works on what the steps before it produced.
 *
 * The first step was the only one that read the input, so with hidden_spaces
 * off every later step was handed null and the value came back empty.
 */
final class SanitizeOptionsTest extends TestCase
{
    public function testTheValueSurvivesWithHiddenSpacesOff(): void
    {
        $this->assertSame( 'a b', Sanitize::cleanInput( '<b>a</b> b',
            [ 'hidden_spaces' => false, 'remove_html' => true, 'escape_html' => false ] ) );
    }

    public function testTheValueSurvivesWithEveryStepOff(): void
    {
        $this->assertSame( 'a<b', Sanitize::cleanInput( 'a<b', [
            'hidden_spaces' => false, 'dollar' => false, 'carriage' => false,
            'unicode' => false, 'escape_html' => false, 'backslash' => false ] ) );
    }

    /**
     * U+02BB (the okina) is the bytes CA BB. Byte 0xCA was stripped, which
     * left an invalid byte in its place.
     */
    public function testTextInTheRangeU0280ToU02BFIsKept(): void
    {
        $this->assertSame( "Hawai\u{02BB}i", Sanitize::removeHiddenSpaces( "Hawai\u{02BB}i" ) );
        $this->assertSame( "Hawai\u{02BB}i", Sanitize::cleanInput( "Hawai\u{02BB}i" ) );
        $this->assertSame( "\u{0280}\u{02BF}", Sanitize::cleanInput( "\u{0280}\u{02BF}" ) );
    }

    public function testANonBreakingSpaceBecomesASpace(): void
    {
        $this->assertSame( 'a b', Sanitize::removeHiddenSpaces( "a\u{00A0}b" ) );
        $this->assertSame( "a\u{00A0}b", Sanitize::cleanInput( "a\u{00A0}b", [ 'hidden_spaces' => false, 'escape_html' => false ] ) );
    }
}
