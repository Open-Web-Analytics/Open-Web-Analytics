<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_visitor_acquisition keyed on an ascending id, with visitor_id unique.
 */
final class Update060Test extends TestCase
{
    private const VISITOR = '9200000000000004001';

    private \OWA\Module\Base\Update\Update060 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update060();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->update->up();
            $this->clean();
        }
    }

    private function clean(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]);
    }

    private function keys(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SHOW INDEX FROM owa_visitor_acquisition') as $i) {
            $i = (array) $i;
            $out[$i['Key_name']] = ['column' => $i['Column_name'], 'unique' => !(int) $i['Non_unique']];
        }

        return $out;
    }

    private function rows(): int
    {
        return (int) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(*) AS n FROM owa_visitor_acquisition'))['n'];
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(60, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(60,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    public function testTheEntityDeclaresTheSameShape(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.visitor_acquisition');

        $this->assertSame(['visitor_id_unique' => ['visitor_id']], $entity->getUniqueIndexes());
        $this->assertSame('id', $entity->getColumns()[0]);
    }

    public function testUpKeysOnAnAscendingIdAndIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());

        $keys = $this->keys();

        $this->assertSame(['column' => 'id', 'unique' => true], $keys['PRIMARY']);
        $this->assertSame(['column' => 'visitor_id', 'unique' => true], $keys['visitor_id_unique']);
    }

    public function testDownIsTheExactInverseAndKeepsTheRows(): void
    {
        $this->update->up();
        $before = $this->rows();

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');

        $keys = $this->keys();

        $this->assertSame(['column' => 'visitor_id', 'unique' => true], $keys['PRIMARY']);
        $this->assertArrayNotHasKey('visitor_id_unique', $keys);
        $this->assertFalse((bool) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            "SHOW COLUMNS FROM owa_visitor_acquisition LIKE 'id'"));
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());
    }

    /** Insert-if-absent still rests on the database refusing a second row. */
    public function testASecondRowForOneVisitorIsRefused(): void
    {
        $this->update->up();

        $row = ['visitor_id' => self::VISITOR, 'site_id' => 'key-test', 'acq_source' => 'newsletter'];

        $first = \OWA\Core\CoreAPI::entityFactory('base.visitor_acquisition');
        $first->setProperties($row);
        $this->assertTrue($first->create());

        $second = \OWA\Core\CoreAPI::entityFactory('base.visitor_acquisition');
        $second->setProperties($row);
        $this->assertNotTrue($second->create());

        $this->assertSame(1, (int) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(*) AS n FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]))['n']);

        $loaded = \OWA\Core\CoreAPI::entityFactory('base.visitor_acquisition');
        $loaded->load(self::VISITOR, 'visitor_id');
        $this->assertSame('newsletter', $loaded->get('acq_source'));
    }
}
