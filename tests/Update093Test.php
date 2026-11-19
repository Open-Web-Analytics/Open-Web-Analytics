<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update093;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update093: owa_site is indexed on site_id, unique where the rows allow it,
 * and owa_setting loses its index on autoload; down() puts both back.
 *
 * Against scratch copies of the two tables.
 */
final class Update093Test extends TestCase
{
    const SITE    = 'owa_site_update093_test';
    const SETTING = 'owa_setting_update093_test';

    private Update093 $update;

    protected function setUp(): void
    {
        $this->update = new Update093();

        if (!owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::SITE => 'base.site', self::SETTING => 'base.setting'] as $table => $entity) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
            $db->query(sprintf('CREATE TABLE %s LIKE %s', $table,
                \OWA\Core\CoreAPI::entityFactory($entity)->getTableName()));
        }

        // The shape before the update, whatever the install's tables have.
        $this->update->tables = ['base.site' => self::SITE, 'base.setting' => self::SETTING];
        $this->update->down();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s, %s', self::SITE, self::SETTING));
        }
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters scratch tables');
        }
    }

    /** @return array<string, array{columns: string, unique: bool}> index name => its columns */
    private function indexes(string $table): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                'SELECT INDEX_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, MIN(NON_UNIQUE) AS nu'
              . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
              . ' GROUP BY INDEX_NAME', [$table]) as $row) {
            $out[$row['name']] = ['columns' => $row['cols'], 'unique' => (int) $row['nu'] === 0];
        }

        ksort($out);

        return $out;
    }

    public function testTheSchemaNumber(): void
    {
        $this->assertSame(93, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(93,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    public function testUpIndexesSiteIdAndDropsTheAutoloadIndex(): void
    {
        $this->requireDb();

        $site    = $this->indexes(self::SITE);
        $setting = $this->indexes(self::SETTING);

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'a second run changes nothing');

        $this->assertSame(['columns' => 'site_id', 'unique' => true],
            $this->indexes(self::SITE)[\OWA\Module\Base\Entity\Site::SITE_ID_INDEX] ?? null);
        $this->assertSame([], array_filter($this->indexes(self::SETTING), fn ($ix) => $ix['columns'] === 'autoload'));

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'a second run changes nothing');

        $this->assertSame($site, $this->indexes(self::SITE));
        $this->assertSame($setting, $this->indexes(self::SETTING));
    }

    public function testASiteIdOnTwoRowsGetsAPlainIndex(): void
    {
        $this->requireDb();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf("INSERT INTO %s (id, site_id) VALUES (1, 'owatest-twice'), (2, 'owatest-twice')", self::SITE));

        $this->assertTrue($this->update->up());

        $this->assertSame(['columns' => 'site_id', 'unique' => false],
            $this->indexes(self::SITE)[\OWA\Module\Base\Entity\Site::SITE_ID_INDEX] ?? null);

        $this->assertTrue($this->update->down());
        $this->assertArrayNotHasKey(\OWA\Module\Base\Entity\Site::SITE_ID_INDEX, $this->indexes(self::SITE));
    }

    /** A fresh install has what an upgraded one has. */
    public function testTheEntitiesDeclareTheSame(): void
    {
        $this->assertSame(['site_id'],
            \OWA\Core\CoreAPI::entityFactory('base.site')->getUniqueIndexes()[\OWA\Module\Base\Entity\Site::SITE_ID_INDEX] ?? null);
        $this->assertFalse(\OWA\Core\CoreAPI::entityFactory('base.setting')->isColumnIndexed('autoload'));
    }
}
