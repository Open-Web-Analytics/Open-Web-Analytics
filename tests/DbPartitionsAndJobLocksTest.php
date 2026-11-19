<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Db delegates partition management to Db\Partitions and job locks to
 * Db\JobLocks. A driver extends Db, so what it overrides there has to reach
 * the code in those classes.
 */
final class DbPartitionsAndJobLocksTest extends TestCase
{
    /** A driver overriding the protected and public methods the two classes call back into. */
    private function driver(): \OWA\Core\Db
    {
        return new class extends \OWA\Core\Db\Mysql {

            /** @var list<string> */
            public array $sql = [];

            /** @var list<string> */
            public array $reorganized = [];

            public function __construct() {}

            public function query($sql, array $params = [])
            {
                $this->sql[] = 'query: ' . $sql;

                return true;
            }

            protected function changePartitions($table_name, $sql)
            {
                $this->sql[] = 'change: ' . $sql;

                return true;
            }

            protected function jobLockTable()
            {
                return 'owatest_lock';
            }

            public function supportsPartitioning()
            {
                return true;
            }

            public function listPartitions($table_name)
            {
                return [
                    ['name' => 'p20240101', 'less_than' => '20240201', 'rows' => 0],
                    ['name' => 'p20240201', 'less_than' => '20240301', 'rows' => 0],
                    ['name' => 'pmax', 'less_than' => 'MAXVALUE', 'rows' => 0],
                ];
            }

            public function reorganizePartitions($table_name, $from, $ranges)
            {
                $this->reorganized[] = implode(',', $from);

                return true;
            }
        };
    }

    public function testAPartitionChangeGoesThroughTheDriversChangePartitions(): void
    {
        foreach ([true, false] as $through_db) {
            $db = $this->driver();
            $p  = $through_db ? $db : new \OWA\Core\Db\Partitions($db);

            $this->assertTrue($p->dropPartition('owa_t', 'p20240101'));
            $this->assertTrue($p->mergePartitions('owa_t', ['p20240101', 'p20240201'], '20240101', '20240301'));

            $this->assertSame([
                'change: ALTER TABLE owa_t DROP PARTITION p20240101',
                'change: ALTER TABLE owa_t REORGANIZE PARTITION p20240101,p20240201 INTO (PARTITION p20240101 VALUES LESS THAN (20240301))',
            ], $db->sql);
        }
    }

    public function testPartitionMethodsCallEachOtherThroughTheDriver(): void
    {
        $db = $this->driver();

        // The driver's listPartitions() feeds the spans, the catch-all and the layout.
        $this->assertSame('pmax', $db->getCatchAllPartition('owa_t'));
        $this->assertSame(['p20240101', 'p20240201'], array_column($db->getPartitionSpans('owa_t'), 'name'));
        $this->assertSame('monthly', $db->inferPartitionGranularity('owa_t'));

        // repartitionTable() rewrites through the driver's reorganizePartitions().
        $result = $db->repartitionTable('owa_t', 'half-month');

        $this->assertSame(['p20240101', 'p20240201'], $db->reorganized);
        $this->assertSame([], $result['failed']);
    }

    public function testAJobLockUsesTheDriversTable(): void
    {
        $db = $this->driver();

        $this->assertTrue($db->insertJobLock('owatest_job', 'owner', 1, 2));
        $this->assertTrue((new \OWA\Core\Db\JobLocks($db))->releaseJobLock('owatest_job', 'owner'));

        $this->assertSame([
            'query: INSERT INTO owatest_lock (job_name, owner, acquired_at, expires_at) VALUES (?, ?, ?, ?)',
            'query: DELETE FROM owatest_lock WHERE job_name = ? AND owner = ?',
        ], $db->sql);
    }

    /**
     * Every public method and constant of the two classes is on Db too, with
     * the same parameters, so code written against Db keeps working.
     *
     * @return array<string, array{class-string}>
     */
    public static function delegates(): array
    {
        return [
            'Partitions' => [\OWA\Core\Db\Partitions::class],
            'JobLocks'   => [\OWA\Core\Db\JobLocks::class],
        ];
    }

    /**
     * @dataProvider delegates
     * @param class-string $class
     */
    public function testDbKeepsEveryPublicMethodAndConstant(string $class): void
    {
        $db     = new ReflectionClass(\OWA\Core\Db::class);
        $params = static function (ReflectionMethod $m): array {
            return array_map(static function (ReflectionParameter $p): string {
                return ($p->isPassedByReference() ? '&' : '') . $p->getName()
                    . ($p->isDefaultValueAvailable() ? '=' . var_export($p->getDefaultValue(), true) : '');
            }, $m->getParameters());
        };

        $checked = 0;

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isConstructor()) {
                continue;
            }

            $this->assertTrue($db->hasMethod($m->getName()), 'Db::' . $m->getName() . '() is missing');

            $on_db = $db->getMethod($m->getName());

            $this->assertTrue($on_db->isPublic(), $m->getName());
            $this->assertSame($m->isStatic(), $on_db->isStatic(), $m->getName());
            $this->assertSame($params($m), $params($on_db), $m->getName());
            $checked++;
        }

        foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
            $this->assertSame($value, $db->getConstant($name), 'Db::' . $name);
        }

        $this->assertGreaterThan(4, $checked);
    }
}
