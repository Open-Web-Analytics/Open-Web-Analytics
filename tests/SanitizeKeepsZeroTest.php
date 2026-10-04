<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Sanitize;

/**
 * "0" is a value, not an absence.
 *
 * cleanInput() returned early on empty(), which is true for "0", so every
 * request parameter and tracked string whose value was zero became null. Found
 * saving a retention window of 0 from the Data Retention page: the settings
 * form posted "0" and validation was handed null.
 */
final class SanitizeKeepsZeroTest extends TestCase
{
    public function testZeroSurvives(): void
    {
        $this->assertSame('0', Sanitize::cleanInput('0'));
        $this->assertSame('0', Sanitize::cleanInput('0', array('remove_html' => true, 'escape_html' => false)));
    }

    public function testZeroSurvivesInsideARequest(): void
    {
        $this->assertSame(
            array('config' => array('base.raw_retention_months' => '0', 'base.cube_retention_months' => '12')),
            Sanitize::cleanInput(array('config' => array('base.raw_retention_months' => '0', 'base.cube_retention_months' => '12')),
                array('remove_html' => true, 'escape_html' => false)));
    }

    public function testEmptyIsStillNothing(): void
    {
        $this->assertNull(Sanitize::cleanInput(''));
        $this->assertNull(Sanitize::cleanInput(null));
        $this->assertNull(Sanitize::cleanInput(array()));
        $this->assertSame(array('a' => null), Sanitize::cleanInput(array('a' => '')));
    }
}
