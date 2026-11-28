<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * cmd=core-install --force for a module other than Base
 * (InstallManager::reinstallModule()), against the database: Domstream's
 * tables with no recorded schema version are renamed <table>_before_<stamp>,
 * created fresh, and the required version recorded. A second move under the
 * same stamp collides and renames nothing.
 *
 * Leaves the database as it found it: the fresh tables are dropped, the old
 * ones renamed back, and the version and is_active restored.
 */
final class ReinstallModuleTest extends TestCase
{
    public function testAModuleInAnUnknownStateIsMovedAsideAndInstalledFresh(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('renames and creates the domstream tables');
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $c      = \OWA\Core\CoreAPI::configSingleton();
        $module = \OWA\Core\CoreAPI::moduleClassFactory('domstream');
        $stamp  = '20261128' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $version = \OWA\Core\CoreAPI::getSetting('domstream', 'schema_version');
        $active  = \OWA\Core\CoreAPI::getSetting('domstream', 'is_active');
        $tables  = array_map(fn ($e) => \OWA\Core\CoreAPI::entityFactory('domstream.' . $e)->getTableName(), $module->getEntities());
        $result  = ['moved' => []];

        $this->assertSame([], $module->createMissingTables()['failed'], 'precondition: the tables are there');

        try {
            \OWA\Core\CoreAPI::persistSetting('domstream', 'schema_version', false);
            $c->save();

            $this->assertTrue($module->hasUnknownSchemaState());

            $result = (new \OWA\Module\Base\Classes\InstallManager())->reinstallModule('domstream', $stamp);

            $this->assertSame('', $result['problem']);
            $this->assertSame(array_combine($tables, array_map(fn ($t) => $t . '_before_' . $stamp, $tables)), $result['moved'],
                'each table renamed, and said');

            foreach ($tables as $table) {
                $this->assertTrue($db->tableExists($table . '_before_' . $stamp), 'not dropped: ' . $table);
                $this->assertTrue($db->tableExists($table), 'created fresh: ' . $table);
            }

            $this->assertSame($module->getRequiredSchemaVersion(), (int) \OWA\Core\CoreAPI::getSetting('domstream', 'schema_version'));
            $this->assertFalse($module->hasUnknownSchemaState());

            \OWA\Core\CoreAPI::persistSetting('domstream', 'schema_version', false);

            $this->assertStringContainsString('_before_' . $stamp . ' exists. Nothing was renamed.', $module->moveAsideProblem($stamp),
                'the same stamp again collides');
        } finally {
            foreach ($result['moved'] as $from => $to) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s', $from));
                $db->renameTable($to, $from);
            }

            \OWA\Core\CoreAPI::persistSetting('domstream', 'schema_version', $version);
            \OWA\Core\CoreAPI::persistSetting('domstream', 'is_active', $active);
            $c->save();
        }
    }
}
