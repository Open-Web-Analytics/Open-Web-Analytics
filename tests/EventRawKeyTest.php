<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_event_raw, the cube and the domstream tables are keyed on (yyyymmdd, id),
 * and raw's site_date index carries created_at (Update094).
 *
 * The CREATE TABLE statements Db::createTable() emits, read from a driver with
 * no connection, so no database is needed.
 */
final class EventRawKeyTest extends TestCase
{
    /** The CREATE TABLE createTable() issues for an entity. */
    private static function ddl(\OWA\Core\Entity $entity): string
    {
        $db = new class extends \OWA\Core\Db\Mysql {
            /** @var list<string> */
            public array $sql = [];
            public function __construct() {}
            function query($sql, array $params = []) { $this->sql[] = $sql; return true; }
        };

        $db->createTable($entity);

        self::assertCount(1, $db->sql);

        return $db->sql[0];
    }

    public function testRawIsKeyedOnTheDayFirst(): void
    {
        $ddl = self::ddl(\OWA\Core\CoreAPI::entityFactory('base.event_raw'));

        $this->assertStringContainsString('PRIMARY KEY (yyyymmdd, id)', $ddl);
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), 'one key, at table level: ' . $ddl);
        $this->assertStringContainsString('PARTITION BY RANGE', $ddl);
    }

    public function testRawSiteDateCarriesCreatedAt(): void
    {
        $ddl = self::ddl(\OWA\Core\CoreAPI::entityFactory('base.event_raw'));

        $this->assertStringContainsString('INDEX site_date (site_id, yyyymmdd, created_at)', $ddl);
    }

    /** The cube inherits raw's key, and has no created_at for site_date to carry. */
    public function testTheCubeIsKeyedLikeRawWithSiteDateOnTwoColumns(): void
    {
        $cube = \OWA\Core\CoreAPI::entityFactory('base.event')->bindToTable('event_zz_key_probe');
        $ddl  = self::ddl($cube);

        $this->assertStringContainsString('PRIMARY KEY (yyyymmdd, id)', $ddl);
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), $ddl);
        $this->assertStringContainsString('INDEX site_date (site_id, yyyymmdd),', $ddl);
        $this->assertStringNotContainsString('created_at', $ddl);
    }

    /** @return array<string, array{string}> */
    public static function domstreamEntities(): array
    {
        return ['chunk' => ['domstream.domstream_chunk'], 'payload' => ['domstream.domstream_payload']];
    }

    /** @dataProvider domstreamEntities */
    public function testTheDomstreamTablesAreKeyedOnTheDayFirst(string $name): void
    {
        $ddl = self::ddl(\OWA\Core\CoreAPI::entityFactory($name));

        $this->assertStringContainsString('PRIMARY KEY (yyyymmdd, id)', $ddl);
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), $ddl);
        $this->assertStringContainsString('PARTITION BY RANGE', $ddl);
    }

    /** A partitioned table that declares no key keeps (its key, the partition column). */
    public function testAPartitionedTableWithNoDeclaredKeyKeepsItsKeyFirst(): void
    {
        require_once __DIR__ . '/fixtures/TestFixtureLeadProbe.php';

        $ddl = self::ddl(new \OWA\Module\TestFixture\Entity\LeadProbe());

        $this->assertStringContainsString('PRIMARY KEY (id, yyyymmdd)', $ddl);
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), $ddl);
    }

    /**
     * The ingest idempotence lookups (raw, domstream) name each day: on a
     * (yyyymmdd, id) key, `id = ? AND yyyymmdd BETWEEN` reads every row of
     * the range, where an IN list of days is a seek per day.
     */
    public function testTheFactDateConstraintListsTheDays(): void
    {
        $day        = date('Ymd', strtotime('-2 days'));
        $constraint = \OWA\Core\Db::factDateConstraint($day);
        $range      = \OWA\Core\Db::factDateRange($day);

        $this->assertSame('in', $constraint['yyyymmdd']['operator'] ?? null);

        $days = $constraint['yyyymmdd']['value'];

        $this->assertSame((int) $range['start'], $days[0]);
        $this->assertSame((int) $range['end'], end($days));
        $this->assertContains((int) $day, $days);
        $this->assertSame(count($days), count(array_unique($days)));
        $this->assertSame((int) date('Ymd', strtotime($range['start'] . ' +1 day')), $days[1], 'consecutive days');
    }

    /** A date far in the past is a range of years: given as BETWEEN, not thousands of days. */
    public function testALongRangeStaysABetween(): void
    {
        $constraint = \OWA\Core\Db::factDateConstraint('20200101');

        $this->assertSame('between', $constraint['yyyymmdd']['operator']);
        $this->assertSame(\OWA\Core\Db::factDateRange('20200101'), $constraint['yyyymmdd']['value']);
        $this->assertSame([], \OWA\Core\Db::factDateConstraint('0'));
    }

    public function testAnUnpartitionedCompositeKeyIsUnchanged(): void
    {
        $ddl = self::ddl(\OWA\Core\CoreAPI::entityFactory('base.spam_block'));

        $this->assertStringContainsString('PRIMARY KEY (yyyymmdd, site_id, rule)', $ddl);
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), $ddl);
        $this->assertStringNotContainsString('PARTITION BY', $ddl);
    }

    /** Reverting a v1 migration deletes by day and id: an id alone is looked for in every partition. */
    public function testTheMigrationRevertNamesTheDay(): void
    {
        $m = new ReflectionMethod(\OWA\Module\Base\Classes\Migration\FactMigrator::class, 'deletesByDay');

        $this->assertSame([
            'DELETE FROM owa_event_raw WHERE yyyymmdd = 20260101 AND id IN (11,13)',
            'DELETE FROM owa_event_raw WHERE yyyymmdd = 20260102 AND id IN (12)',
        ], $m->invoke(null, 'owa_event_raw', [
            ['id' => 11, 'yyyymmdd' => 20260101],
            ['id' => '12', 'yyyymmdd' => '20260102'],
            ['id' => 13, 'yyyymmdd' => 20260101],
        ]));

        $this->assertSame([], $m->invoke(null, 'owa_event_raw', []));
    }
}
