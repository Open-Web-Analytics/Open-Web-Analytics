<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** Every cube gains channel and acq_channel; down() takes exactly those away. */
final class Update065Test extends TestCase
{
    const PROPERTY = 7791000000000001;

    public static function setUpBeforeClass(): void
    {
        if (owa_test_db_available()) {
            self::dropCube();
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            self::dropCube();
        }
    }

    private static function dropCube(): void
    {
        owa_coreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY)));
    }

    /** @return string[] which of the two columns the table has, in the table's order */
    private function channels(string $table): array
    {
        $columns = owa_coreAPI::dbSingleton()->listColumns($table);

        return array_values(array_intersect($columns, ['channel', 'acq_channel']));
    }

    /** The update and the entity describe the same columns. */
    public function testTheUpdateAndTheEntityAgree(): void
    {
        $this->assertSame(65, (new \OWA\Module\Base\Update\Update065())->schema_version);

        $columns = owa_coreAPI::entityFactory('base.event')->getColumns();

        $this->assertSame(\OWA\Module\Base\Update\Update065::COLUMNS,
            array_values(array_intersect($columns, \OWA\Module\Base\Update\Update065::COLUMNS)));
        $this->assertNotContains('channel', owa_coreAPI::entityFactory('base.event_raw')->getColumns(),
            'a reading, so the cube only');
    }

    public function testUpAndDownAreRepeatableOnACube(): void
    {
        $this->assertTrue(\OWA\Module\Base\Classes\Cube\Cubes::create(self::PROPERTY));
        $table  = \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY);
        $update = new \OWA\Module\Base\Update\Update065();

        $this->assertSame(['channel', 'acq_channel'], $this->channels($table), 'a fresh cube has both');

        $this->assertTrue($update->down());
        $this->assertTrue($update->down());
        $this->assertSame([], $this->channels($table));

        $this->assertTrue($update->up());
        $this->assertTrue($update->up());
        $this->assertSame(['channel', 'acq_channel'], $this->channels($table));

        $this->assertFalse(owa_coreAPI::dbSingleton()->hasInstantColumns($table),
            'added by a rebuild, so the cube still swaps');
    }
}
