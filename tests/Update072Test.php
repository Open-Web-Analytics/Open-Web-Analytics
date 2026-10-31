<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * page_depth, previous_page_path and next_page_path on every cube.
 *
 * down() is not exercised here: it drops the column from EVERY cube on the
 * database the suite runs against, and the up() after it brings the column back
 * empty, so on a developer's installation it would throw away a rebuilt
 * history. tests/tools/upgrade_cycle_run.sh rolls it back and forward on a
 * scratch install, which is where the round trip is proven.
 */
final class Update072Test extends TestCase
{
    const FIXTURE_PROPERTY = 7775000000000072;

    private \OWA\Module\Base\Update\Update072 $update;

    private bool $createdCube = false;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters the cubes');
        }

        $this->update = new \OWA\Module\Base\Update\Update072();

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

    public function testTheUpdateTheModuleAndTheEntityAgree(): void
    {
        $this->assertSame(72, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(72,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);

        foreach (\OWA\Module\Base\Update\Update072::COLUMNS as $column) {
            $this->assertContains($column, \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns());
            $this->assertNotContains($column, \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns(),
                'the build derives it from the session; raw has nothing to carry');
        }
    }

    public function testUpIsIdempotentAndEveryCubeHasTheColumns(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table) {
            foreach (\OWA\Module\Base\Update\Update072::COLUMNS as $column) {
                $this->assertNotEmpty((array) $db->get_results("SHOW COLUMNS FROM $table LIKE '$column'"),
                    "$table.$column");
            }

            $this->assertNotTrue($db->hasInstantColumns($table),
                "$table: a cube with instant columns refuses the build's partition swap");
        }
    }
}
