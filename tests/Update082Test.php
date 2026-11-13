<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update082: spam_score and spam_rules on owa_event_raw, and on no cube.
 */
final class Update082Test extends TestCase
{
    private \OWA\Module\Base\Update\Update082 $update;

    protected function setUp(): void
    {
        $this->update = new \OWA\Module\Base\Update\Update082();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->update->up();
        }
    }

    /** @return string[] the spam columns raw has, in table order */
    private function columns(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW COLUMNS FROM owa_event_raw LIKE 'spam\\_%'") as $row) {
            $out[] = ((array) $row)['Field'];
        }

        return $out;
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(82, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(82, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertSame(['spam_score', 'spam_rules'], \OWA\Module\Base\Update\Update082::COLUMNS);
    }

    /** Raw only: the cube entity drops both, so no cube gains them. */
    public function testTheColumnsAreRawOnly(): void
    {
        $raw  = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns();
        $cube = \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns();

        foreach (\OWA\Module\Base\Update\Update082::COLUMNS as $column) {
            $this->assertContains($column, $raw);
            $this->assertNotContains($column, $cube);
        }

        $this->assertNotContains(\OWA\Module\Base\Update\CubeColumn::class,
            class_uses(\OWA\Module\Base\Update\Update082::class),
            'raw only: the CubeColumn trait rebuilds every cube');
        $this->assertContains(\OWA\Module\Base\Update\CubeColumn::class,
            class_uses(\OWA\Module\Base\Update\Update073::class), 'the check can see the trait where it is used');

        $this->assertSame(['spam_score', 'spam_rules'], array_slice($raw, -2), 'appended, in the order ADD COLUMN appends them');
    }

    /** A score of 0.0 is a value: the decimal type is numeric to the entity layer, so set() keeps it. */
    public function testAZeroScoreIsStoredNotDropped(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');

        $method = new ReflectionMethod($entity, 'numericColumnTypes');

        $this->assertContains((string) OWA_DTD_DECIMAL6_1, $method->invoke($entity));

        $entity->set('spam_score', 0.0);
        $this->assertSame(0.0, $entity->get('spam_score'));
    }

    public function testUpDownAndAgain(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters owa_event_raw');
        }

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'up is idempotent');
        $this->assertSame(['spam_score', 'spam_rules'], $this->columns());

        $type = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row("SHOW COLUMNS FROM owa_event_raw LIKE 'spam_score'");
        $this->assertSame('decimal(6,1)', strtolower((string) $type['Type']));
        $this->assertSame('YES', $type['Null']);

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent');
        $this->assertSame([], $this->columns());

        $this->assertTrue($this->update->up());
        $this->assertSame(['spam_score', 'spam_rules'], $this->columns());
    }
}
