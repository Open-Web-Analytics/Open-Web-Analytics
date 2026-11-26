<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/DomstreamFixtures.php';

/**
 * The idempotence checks of ingest are one pruned query, found or not.
 *
 * A raw row's id and its yyyymmdd come from the same beacon, so the bounded
 * lookup is exact, and a miss -- every new beacon -- must not be repeated
 * across every partition (Entity::existsWithin()).
 */
final class ExistsWithinLookupTest extends TestCase
{
    /** @var list<array{string, string, int}> table, id, yyyymmdd */
    private $rows = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->rows as [$table, $id, $day]) {
            $db->query(sprintf('DELETE FROM %s WHERE id = ? AND yyyymmdd = ?', $table), [$id, $day]);
        }
    }

    private function queriesDuring(callable $block, &$result = null): int
    {
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $before = $db->num_queries;
        $result = $block();

        return $db->num_queries - $before;
    }

    private function newId(): string
    {
        return (string) random_int(1000000000, PHP_INT_MAX);
    }

    private function storeRow(string $entity, string $id, int $day): void
    {
        $table = \OWA\Core\CoreAPI::entityFactory($entity)->getTableName();

        $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->query(
            sprintf('INSERT INTO %s (id, yyyymmdd) VALUES (?, ?)', $table), [$id, $day]),
            'the fixture row was not written');

        $this->rows[] = [$table, $id, $day];
    }

    private function alreadyIngested(array $row): bool
    {
        $method = new ReflectionMethod(\OWA\Module\Base\Handler\EventRawHandlers::class, 'alreadyIngested');

        return $method->invoke(new \OWA\Module\Base\Handler\EventRawHandlers(), $row);
    }

    public function testANewBeaconIsOneQuery(): void
    {
        $row = ['id' => $this->newId(), 'yyyymmdd' => (int) date('Ymd')];

        $this->assertSame(1, $this->queriesDuring(fn () => $this->alreadyIngested($row), $found),
            'a miss under the date range must not be asked again without it');
        $this->assertFalse($found);
    }

    public function testAStoredBeaconIsFound(): void
    {
        $day = (int) date('Ymd');
        $id  = $this->newId();

        $this->storeRow('base.event_raw', $id, $day);

        $this->assertSame(1, $this->queriesDuring(
            fn () => $this->alreadyIngested(['id' => $id, 'yyyymmdd' => $day]), $found));
        $this->assertTrue($found);
    }

    /** Its own day and the days either side: a redelivery derives the same day; a timezone changed in between moves it one. */
    public function testABeaconIsLookedForOnItsDayAndTheDaysEitherSide(): void
    {
        $day = (int) date('Ymd', strtotime('-3 days'));
        $id  = $this->newId();

        $this->storeRow('base.event_raw', $id, (int) date('Ymd', strtotime('-2 days')));

        $this->assertTrue($this->alreadyIngested(['id' => $id, 'yyyymmdd' => $day]), 'a day later');

        $far = $this->newId();
        $this->storeRow('base.event_raw', $far, (int) date('Ymd', strtotime('-10 days')));

        $this->assertFalse($this->alreadyIngested(['id' => $far, 'yyyymmdd' => $day]), 'a week away is another beacon');
        $this->assertTrue($this->alreadyIngested(['id' => $far, 'yyyymmdd' => 0]), 'no usable date: looked for everywhere');
    }

    public function testAChunkIsLookedUpOnce(): void
    {
        DomstreamFixtures::ensure();

        $chunk = \OWA\Core\CoreAPI::entityFactory('domstream.domstream_chunk');
        $day   = (int) date('Ymd');
        $id    = $this->newId();

        $this->assertSame(1, $this->queriesDuring(
            fn () => $chunk->existsWithin('id', $id, \OWA\Core\Db::factDateConstraint($day)), $found));
        $this->assertFalse($found);

        $this->storeRow('domstream.domstream_chunk', $id, $day);

        // Already stored: one lookup, and nothing written.
        $this->assertSame(1, $this->queriesDuring(
            fn () => \OWA\Module\Domstream\Controller\ProcessEvent::store([
                'chunk'   => ['id' => $id, 'yyyymmdd' => $day],
                'payload' => ['id' => (int) $id, 'yyyymmdd' => $day, 'payload' => ''],
            ]), $stored));
        $this->assertTrue($stored);
    }

    /** The range is the bound: a row outside it is not this beacon's. */
    public function testARowOutsideTheRangeIsNotFound(): void
    {
        $id  = $this->newId();
        $old = (int) date('Ymd', strtotime('-400 days'));

        $this->storeRow('base.event_raw', $id, $old);

        $this->assertFalse($this->alreadyIngested(['id' => $id, 'yyyymmdd' => (int) date('Ymd')]));
    }
}
