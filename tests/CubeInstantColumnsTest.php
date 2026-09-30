<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * A cube with instant-column history is found and rebuilt, and the cube
 * updates stop creating that history.
 *
 * An instant ADD or DROP leaves row-format metadata that a staging table made
 * by CREATE TABLE cannot have, so EXCHANGE PARTITION refuses every build of
 * that cube with 1731. CubeColumn::clearCubeInstantColumns() is the backstop,
 * and it acts on Db::hasInstantColumns() -- which answered null on every
 * server until the dialect implemented it, so the backstop never ran.
 *
 * MySQL answers from information_schema.INNODB_TABLES. MariaDB has no such
 * table and answers null; its swap does not refuse an instant column (#1155's
 * CI), so there is nothing for it to clear.
 */
final class CubeInstantColumnsTest extends TestCase
{
    const PROPERTY = 7786000000000001;
    const PROBE    = 'owa_instant_probe';

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Instant columns fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This asks the database server.');
        }
    }

    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            owa_coreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        foreach ([Cubes::tableFor(self::PROPERTY), self::PROBE] as $table) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        foreach (['_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    private function db()
    {
        return owa_coreAPI::dbSingleton();
    }

    private function isMariaDb(): bool
    {
        return stripos((string) ($this->db()->get_row('SELECT VERSION() AS v')['v'] ?? ''), 'mariadb') !== false;
    }

    private function requireMysql(): void
    {
        if ($this->isMariaDb()) {
            $this->markTestSkipped('MariaDB reports no instant-column state; testTheAnswerOnMariaDbIsUnknown covers it.');
        }
    }

    private function cube(): string
    {
        return Cubes::tableFor(self::PROPERTY);
    }

    /** A column added or dropped instantly, the way a plain ALTER does it. */
    private function makeInstant(string $table, string $column): void
    {
        $this->assertNotFalse($this->db()->query(sprintf(
            'ALTER TABLE %s ADD COLUMN %s INT NULL, ALGORITHM=INSTANT', $table, $column)),
            'adding an instant column: ' . $this->db()->lastQueryError());
    }

    private function dropPlain(string $table, string $column): void
    {
        $this->db()->query(sprintf(OWA_SQL_DROP_COLUMN, $table, $column));
    }

    /** The trait's methods, on a bare Update. */
    private function update()
    {
        return new class extends \OWA\Core\Update {
            use \OWA\Module\Base\Update\CubeColumn;

            public $e;

            public function __construct()
            {
                $this->e = new class {
                    public function __call($name, $args) {}
                };
            }

            public function drop(string $column): bool
            {
                return $this->dropCubeColumn($column);
            }

            public function clear(): bool
            {
                return $this->clearCubeInstantColumns();
            }
        };
    }

    public function testAFreshCubeHasNone(): void
    {
        $this->requireMysql();

        $this->assertFalse($this->db()->hasInstantColumns($this->cube()));
    }

    public function testAnInstantColumnOnAPartitionedTableIsFound(): void
    {
        $this->requireMysql();

        $this->makeInstant($this->cube(), 'instant_probe');

        try {
            $this->assertTrue($this->db()->hasInstantColumns($this->cube()));

            $this->assertTrue($this->db()->rebuildTable($this->cube()));
            $this->assertFalse($this->db()->hasInstantColumns($this->cube()), 'FORCE clears it');
        } finally {
            $this->db()->query(sprintf('ALTER TABLE %s DROP COLUMN instant_probe, FORCE', $this->cube()));
        }
    }

    public function testAnUnpartitionedTableIsReadToo(): void
    {
        $this->requireMysql();

        $db = $this->db();
        $db->query(sprintf('CREATE TABLE %s (id BIGINT PRIMARY KEY, a INT) ENGINE=InnoDB', self::PROBE));

        $this->assertFalse($db->hasInstantColumns(self::PROBE));

        $this->makeInstant(self::PROBE, 'b');
        $this->assertTrue($db->hasInstantColumns(self::PROBE));
    }

    public function testATableInnoDbDoesNotListIsUnknown(): void
    {
        $this->assertNull($this->db()->hasInstantColumns('owa_no_such_table_here'));
        $this->assertNull($this->db()->hasInstantColumns('not a name'));
    }

    public function testTheAnswerOnMariaDbIsUnknown(): void
    {
        if (!$this->isMariaDb()) {
            $this->markTestSkipped('MySQL answers; the tests above cover it.');
        }

        $this->assertNull($this->db()->hasInstantColumns($this->cube()));
    }

    /**
     * THE BACKSTOP RUNS: a cube left with an instant column is rebuilt, and a
     * build swaps into it afterwards.
     */
    public function testClearingRebuildsACubeWithHistorySoItsBuildSwaps(): void
    {
        $this->requireMysql();

        $this->makeInstant($this->cube(), 'backstop_probe');
        $this->dropPlain($this->cube(), 'backstop_probe');

        $this->assertTrue($this->db()->hasInstantColumns($this->cube()), 'the fixture has history');

        $this->assertTrue($this->update()->clear());

        $this->assertFalse($this->db()->hasInstantColumns($this->cube()));

        $builder = new Builder(self::PROPERTY);
        $span    = $builder->partitions((int) date('Ymd'), (int) date('Ymd'))[0];
        $result  = $builder->rebuild($span);

        $this->assertTrue($result['ok'], sprintf('%s (the server said: %s)',
            $result['error'], $this->db()->lastQueryError()));
    }

    /** A cube column dropped by an update leaves no history to clear. */
    public function testDroppingACubeColumnRebuilds(): void
    {
        $this->requireMysql();

        $this->assertTrue($this->db()->alterColumnsRebuilding($this->cube(), ['drop_probe' => 'INT NULL']));
        $this->assertFalse($this->db()->hasInstantColumns($this->cube()));

        $this->assertTrue($this->update()->drop('drop_probe'));

        $this->assertSame([], $this->db()->listColumns($this->cube(), 'drop\_probe'));
        $this->assertFalse($this->db()->hasInstantColumns($this->cube()));
    }

    /** The two rebuilding forms say FORCE, which MariaDB needs to rule INSTANT out. */
    public function testTheRebuildingFormsForceARebuild(): void
    {
        $this->assertStringContainsString('FORCE', OWA_SQL_ADD_COLUMN_REBUILD);
        $this->assertStringContainsString('FORCE', OWA_SQL_ALTER_COLUMNS_REBUILD);
    }
}
