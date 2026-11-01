<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * element_anchor, anchor_x and anchor_y on owa_event_raw and every cube.
 *
 * down() is not exercised here: it drops the columns from raw and from EVERY
 * cube on the database the suite runs against, taking every recorded anchor
 * with them. tests/tools/upgrade_cycle_run.sh rolls it back and forward on a
 * scratch install, which is where the round trip is proven.
 */
final class Update073Test extends TestCase
{
    const FIXTURE_PROPERTY = 7775000000000073;

    private \OWA\Module\Base\Update\Update073 $update;

    private bool $createdCube = false;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters owa_event_raw and the cubes');
        }

        $this->update = new \OWA\Module\Base\Update\Update073();

        // CI's database has no cube until a build makes one.
        if (!\OWA\Module\Base\Classes\Cube\Cubes::allTables()) {
            $this->assertTrue((bool) \OWA\Module\Base\Classes\Cube\Cubes::create(self::FIXTURE_PROPERTY),
                'creating a fixture cube');
            $this->createdCube = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdCube) {
            foreach (['', '_rebuild', '_computed'] as $suffix) {
                \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s%s',
                    \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::FIXTURE_PROPERTY), $suffix));
            }
        }
    }

    public function testTheUpdateTheModuleAndTheEntitiesAgree(): void
    {
        $this->assertSame(73, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(73,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);

        foreach (\OWA\Module\Base\Update\Update073::COLUMNS as $column) {
            $this->assertContains($column, \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns());
            $this->assertContains($column, \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns(),
                'the cube carries every raw column');
        }
    }

    public function testUpIsIdempotentAndRawAndEveryCubeHaveTheColumns(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $tables = array_merge([\OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName()],
            \OWA\Module\Base\Classes\Cube\Cubes::allTables());

        foreach ($tables as $table) {
            foreach (\OWA\Module\Base\Update\Update073::COLUMNS as $column) {
                $this->assertNotEmpty((array) $db->get_results("SHOW COLUMNS FROM $table LIKE '$column'"),
                    "$table.$column");
            }
        }

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table) {
            $this->assertNotTrue($db->hasInstantColumns($table),
                "$table: a cube with instant columns refuses the build's partition swap");
        }
    }
}
