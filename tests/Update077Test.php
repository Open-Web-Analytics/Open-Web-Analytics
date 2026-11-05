<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * value on owa_event_raw and every cube.
 *
 * down() IS exercised here, unlike Update073Test's anchors: nothing but a goal
 * event carries a value, and the suite stores none it needs to keep. Each test
 * ends with the column in place.
 */
final class Update077Test extends TestCase
{
    const FIXTURE_PROPERTY = 7775000000000077;

    private \OWA\Module\Base\Update\Update077 $update;

    private bool $createdCube = false;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters owa_event_raw and the cubes');
        }

        $this->update = new \OWA\Module\Base\Update\Update077();

        // CI's database has no cube until a build makes one.
        if (!\OWA\Module\Base\Classes\Cube\Cubes::allTables()) {
            $this->assertTrue((bool) \OWA\Module\Base\Classes\Cube\Cubes::create(self::FIXTURE_PROPERTY),
                'creating a fixture cube');
            $this->createdCube = true;
        }
    }

    protected function tearDown(): void
    {
        // Whatever a test did, the suite after it runs against the shape the entities declare.
        if (isset($this->update)) {
            $this->update->up();
        }

        if ($this->createdCube) {
            foreach (['', '_rebuild', '_computed'] as $suffix) {
                \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s%s',
                    \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::FIXTURE_PROPERTY), $suffix));
            }
        }
    }

    /** @return string[] raw and every cube */
    private function tables(): array
    {
        return array_merge([\OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName()],
            \OWA\Module\Base\Classes\Cube\Cubes::allTables());
    }

    private function has(string $table, string $column): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW COLUMNS FROM $table LIKE '$column'");
    }

    public function testTheUpdateTheModuleAndTheEntitiesAgree(): void
    {
        $this->assertSame(77, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(77,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);

        foreach (\OWA\Module\Base\Update\Update077::COLUMNS as $column) {
            $this->assertContains($column, \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns());
            $this->assertContains($column, \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns(),
                'the cube carries every raw column');
        }

        $this->assertNotEmpty(\OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumn('value')->nullable,
            'value is NULL on every row but a goal event');
    }

    public function testUpTwiceThenDownTwiceThenUpAgain(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'a second up() finds the column and adds nothing');

        foreach ($this->tables() as $table) {
            $this->assertTrue($this->has($table, 'value'), "$table.value after up()");
        }

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table) {
            $this->assertNotTrue($db->hasInstantColumns($table),
                "$table: a cube with instant columns refuses the build's partition swap");
        }

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'a second down() finds no column and drops nothing');

        foreach ($this->tables() as $table) {
            $this->assertFalse($this->has($table, 'value'), "$table.value after down()");
        }

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table) {
            $this->assertNotTrue($db->hasInstantColumns($table),
                "$table: down() left a row version behind, which the next swap refuses");
        }

        $this->assertTrue($this->update->up());

        foreach ($this->tables() as $table) {
            $this->assertTrue($this->has($table, 'value'), "$table.value after up() again");
        }
    }
}
