<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Sanitize;

/**
 * "0" is a value, not an absence.
 *
 * cleanInput() must not treat "0" as empty() does: a request parameter or
 * tracked string whose value is zero would become null -- a retention window
 * of 0 saved from the Data Retention page reaching validation as null.
 */
final class SanitizeKeepsZeroTest extends TestCase
{
    public function testZeroSurvives(): void
    {
        $this->assertSame('0', Sanitize::cleanInput('0'));
        $this->assertSame('0', Sanitize::cleanInput('0', ['remove_html' => true, 'escape_html' => false]));
    }

    public function testZeroSurvivesInsideARequest(): void
    {
        $this->assertSame(
            ['config' => ['base.raw_retention_months' => '0', 'base.cube_retention_months' => '12']],
            Sanitize::cleanInput(['config' => ['base.raw_retention_months' => '0', 'base.cube_retention_months' => '12']],
                ['remove_html' => true, 'escape_html' => false]));
    }

    public function testEmptyIsStillNothing(): void
    {
        $this->assertNull(Sanitize::cleanInput(''));
        $this->assertNull(Sanitize::cleanInput(null));
        $this->assertNull(Sanitize::cleanInput([]));
        $this->assertSame(['a' => null], Sanitize::cleanInput(['a' => '']));
    }
}
