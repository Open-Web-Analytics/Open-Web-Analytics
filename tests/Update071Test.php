<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * profile_name on every cube.
 *
 * down() is not exercised here: it drops the column from EVERY cube on the
 * database the suite runs against, and the up() after it brings the column back
 * empty, so on a developer's installation it would throw away a rebuilt
 * history. tests/tools/upgrade_cycle_run.sh rolls it back and forward on a
 * scratch install, which is where the round trip is proven.
 */
final class Update071Test extends TestCase
{
    const FIXTURE_PROPERTY = 7775000000000071;

    private \OWA\Module\Base\Update\Update071 $update;

    private bool $createdCube = false;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters the cubes');
        }

        $this->update = new \OWA\Module\Base\Update\Update071();

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
        $this->assertSame(71, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(71,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertContains('profile_name', \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns());
        $this->assertNotContains('profile_name', \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns(),
            'the build reads it from the Profile; raw has nothing to carry');
    }

    public function testUpIsIdempotentAndEveryCubeHasTheColumn(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table) {
            $this->assertNotEmpty((array) $db->get_results("SHOW COLUMNS FROM $table LIKE 'profile_name'"),
                "$table.profile_name");
        }
    }
}
