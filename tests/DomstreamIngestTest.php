<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/DomstreamFixtures.php';

/**
 * domstream.processEvent stores a chunk and its payload, once.
 *
 * Driven through the processor rather than logEvent(): which processor an
 * event reaches is the router's job (BeaconCompatEventNamesTest), and the
 * module may not be active where this runs.
 */
final class DomstreamIngestTest extends TestCase
{
    private const SITE = 'domstream-ingest-site';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('stores rows');
        }

        DomstreamFixtures::ensure();
        DomstreamFixtures::deleteSite(self::SITE);
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            DomstreamFixtures::deleteSite(self::SITE);
        }
    }

    private function process(array $props): void
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('domstream');
        $event->setProperties($props + [
            'site_id'      => self::SITE,
            'visitor_id'   => '1790000000123456789',
            'session_id'   => '1790000000223456789',
            'recording_id' => '1790000000323456789',
            'seq'          => 1,
            'ts'           => time() * 1000000,
            'page_location'=> 'https://ingest.test/checkout',
            'samples'      => json_encode([[10, 'm', 5, 5], [20, 'k', 'input', 'card', '']]),
        ]);

        (new \OWA\Module\Domstream\Controller\ProcessEvent(['event' => $event]))->action();
    }

    private function rows(): array
    {
        $chunk = \OWA\Core\CoreAPI::entityFactory('domstream.domstream_chunk')->getTableName();
        $payload = \OWA\Core\CoreAPI::entityFactory('domstream.domstream_payload')->getTableName();

        return array_map(fn ($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            'SELECT c.seq, c.sample_count, c.keypress_count, c.page_path, p.payload FROM %s c'
            . ' JOIN %s p ON p.id = c.id AND p.yyyymmdd = c.yyyymmdd WHERE c.site_id = ? ORDER BY c.seq',
            $chunk, $payload), [self::SITE]));
    }

    public function testAChunkAndItsPayloadAreStored(): void
    {
        $this->process([]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['sample_count']);
        $this->assertSame(1, (int) $rows[0]['keypress_count']);
        $this->assertSame('/checkout', $rows[0]['page_path']);

        // The compressed payload survives the entity layer byte for byte.
        $this->assertSame([[10, 'm', 5, 5], [20, 'k', 'input', 'card', '']],
            json_decode((string) gzdecode($rows[0]['payload']), true));
    }

    public function testARedeliveredChunkIsStoredOnce(): void
    {
        $this->process([]);
        $this->process([]);

        $this->assertCount(1, $this->rows());
    }

    public function testTheNextSeqIsAnotherChunk(): void
    {
        $this->process([]);
        $this->process(['seq' => 2]);

        $this->assertSame([1, 2], array_map('intval', array_column($this->rows(), 'seq')));
    }

    public function testARefusedChunkStoresNothing(): void
    {
        $this->process(['samples' => json_encode([[0, 'k', 'input', 'card', '', '4']])]);

        $this->assertSame([], $this->rows());
    }
}
