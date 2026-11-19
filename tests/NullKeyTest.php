<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A null used as an array key is deprecated from PHP 8.5, and phpunit.xml fails
 * on deprecations. Each of these passed null where no key was meant: a request
 * with no user's role, a column with no data type, a Profile with no Property.
 * On PHP 8.5 each raised the deprecation before the fix.
 */
final class NullKeyTest extends TestCase
{
    public function testANullRoleHasNoCapabilities(): void
    {
        $this->assertSame([], \OWA\Core\CoreAPI::getCapabilities(null));
    }

    public function testANullDataTypeLeavesTheValueAsItIs(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();

        $this->assertSame('12', $rsm->formatValue(null, '12'));
    }

    public function testAProfileWithNoPropertyHasAnEmptyPropertyName(): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->set('site_id', 'null-key-probe');

        $view = new \OWA\Module\Base\View\SitesRest();
        $row  = (new \ReflectionMethod($view, 'addPropertyNames'))->invoke($view, ['null-key-probe' => $site])['null-key-probe'];

        $this->assertSame('', $row['property_name']);
    }
}
