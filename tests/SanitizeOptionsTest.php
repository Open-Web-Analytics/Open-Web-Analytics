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
}
