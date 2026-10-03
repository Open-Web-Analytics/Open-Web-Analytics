<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * SitesEdit::action() refused a missing siteId with `throw exception(...)`:
 * a call to a function that does not exist, so the refusal itself was an
 * "undefined function" Error. Validation normally stops the request first;
 * this is the guard behind it.
 */
final class SitesEditNoSiteIdTest extends TestCase
{
    public function testAMissingSiteIdIsRefusedWithAnException(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the controller loads the site entity');
        }

        $controller = new \OWA\Module\Base\Controller\SitesEdit(['siteId' => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No siteId passed on request');

        $controller->action();
    }
}
