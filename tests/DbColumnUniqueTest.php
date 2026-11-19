<?php

use PHPUnit\Framework\TestCase;

/**
 * A column declared unique gets a UNIQUE constraint in its definition.
 */
final class DbColumnUniqueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        // The dialect defines the OWA_DTD_* constants.
        \OWA\Core\CoreAPI::entityFactory('base.site');
    }

    public function testAUniqueColumnsDefinitionSaysUnique(): void
    {
        $column = new \OWA\Module\Base\Classes\DbColumn('token', 'VARCHAR(255)');
        $column->setUnique();

        $this->assertSame('VARCHAR(255) UNIQUE', $column->getDefinition());
    }

    public function testAColumnNotDeclaredUniqueSaysNothing(): void
    {
        $column = new \OWA\Module\Base\Classes\DbColumn('token', 'VARCHAR(255)');

        $this->assertSame('VARCHAR(255)', $column->getDefinition());
    }
}
