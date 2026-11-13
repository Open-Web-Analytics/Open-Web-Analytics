<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Table names do not follow the `ns` setting.
 *
 * ns prefixes cookies and URL parameters (ParamNamespaceTest). Every entity's
 * table is owa_-prefixed, but cubes and a few updates built names from ns, so
 * an install with another ns looked for its cubes under one prefix while raw
 * stayed owa_event_raw.
 */
final class TablePrefixIsNotNsTest extends TestCase
{
    private $saved;

    protected function setUp(): void
    {
        $this->saved = \OWA\Core\CoreAPI::getSetting('base', 'ns');
        \OWA\Core\CoreAPI::setSetting('base', 'ns', 'zz_');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'ns', $this->saved);
    }

    public function testACubeIsNamedWithTheTablePrefix(): void
    {
        $this->assertSame('owa_' . \OWA\Module\Base\Classes\Cube\Cubes::PREFIX . '123',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor('123'));
        $this->assertSame('123', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdFor(
            'owa_' . \OWA\Module\Base\Classes\Cube\Cubes::PREFIX . '123'));
    }

    public function testRawAndTheCubeShareThePrefix(): void
    {
        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();

        $this->assertStringStartsWith(\OWA\Core\Entity::TABLE_PREFIX, $raw);
        $this->assertStringStartsWith(\OWA\Core\Entity::TABLE_PREFIX,
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor('123'));
    }
}
