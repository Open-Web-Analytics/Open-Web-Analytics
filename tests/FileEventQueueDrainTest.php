<?php

use PHPUnit\Framework\TestCase;

/**
 * The file queue must drain past a row it cannot read.
 *
 * HOW THIS BROKE
 * receiveMessage() called a method on whatever parse_log_row() returned.
 * unserialize() with an allowed_classes list does not fail on a name outside the
 * list -- it returns __PHP_Incomplete_Class, which throws on the first method
 * call. The throw was uncaught, so it left the drain loop in
 * ProcessEventQueue::action() entirely: every event behind the bad row, and
 * every file queued after it, stayed on disk. Each subsequent run re-read the
 * same row and died in the same place, so the queue never recovered and grew by
 * one file per run.
 *
 * The trigger in practice was not corruption but an upgrade: events queued
 * before the PSR-4 relocation name the pre-namespace class, and the allowlist
 * named only the new one. See EventQueueHardeningTest for that half.
 *
 * These tests drive the real FileEventQueue against a temporary directory, so
 * they assert the drain's actual file handling -- consuming rows, archiving the
 * file, moving to the next one -- rather than a stand-in for it.
 */
final class FileEventQueueDrainTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-fq-' . getmypid() . '-' . uniqid() . '/';

        mkdir($this->dir . 'unprocessed/', 0755, true);
        mkdir($this->dir . 'archive/', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['unprocessed/', 'archive/', ''] as $sub) {
            foreach (glob($this->dir . $sub . '*') ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }

        foreach (['unprocessed/', 'archive/', ''] as $sub) {
            if (is_dir($this->dir . $sub)) {
                @rmdir($this->dir . $sub);
            }
        }
    }

    private function queue(): \OWA\Module\Base\Classes\FileEventQueue
    {
        return new \OWA\Module\Base\Classes\FileEventQueue([
            'path'       => $this->dir,
            'queue_name' => 'incoming_tracking_events',
        ]);
    }

    /** One queue-file row in the format the queue writes. */
    private function row(string $blob): string
    {
        return sprintf(
            "%s|*|incoming_tracking_events|*|%d|*|%s",
            date('H:i:s Y-m-d'),
            random_int(100000, 999999),
            urlencode($blob)
        );
    }

    private function eventBlob(string $url): string
    {
        $e = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $e->setEventType('base.page_request');
        $e->set('page_url', $url);

        return serialize($e);
    }

    /** Legacy spelling: the same bytes an install wrote before the relocation. */
    private function legacyEventBlob(string $url): string
    {
        return preg_replace('/^O:\d+:"[^"]+"/', 'O:9:"owa_event"', $this->eventBlob($url), 1);
    }

    private function writeQueueFile(string $name, array $rows): void
    {
        file_put_contents($this->dir . 'unprocessed/' . $name, implode("\n", $rows) . "\n");
    }

    /** Drain like ProcessEventQueue does, and report what came back. */
    private function drain(): array
    {
        $q = $this->queue();
        $urls = [];

        for ($i = 0; $i < 200; $i++) {
            $event = $q->receiveMessage();

            if (!$event) {
                return $urls;
            }

            $urls[] = $event->get('page_url');
        }

        $this->fail('the drain did not terminate');
    }

    public function testAnUnreadableRowDoesNotStrandTheRowsBehindIt(): void
    {
        $this->writeQueueFile('a.txt', [
            $this->row('O:8:"stdClass":1:{s:1:"x";s:3:"pwn";}'),
            $this->row($this->eventBlob('https://example.com/after-the-bad-row')),
        ]);

        $urls = $this->drain();

        $this->assertSame(['https://example.com/after-the-bad-row'], $urls,
            'the readable row behind an unreadable one must still be delivered');
        $this->assertCount(0, glob($this->dir . 'unprocessed/*'),
            'the file must be consumed, not left for a run that will fail the same way');
    }

    /**
     * The failure that was actually observed: a bad row in the OLDEST file also
     * stranded every later file, because the throw left the drain loop.
     */
    public function testAnUnreadableRowDoesNotStrandLaterFiles(): void
    {
        $this->writeQueueFile('a.txt', [$this->row('O:8:"stdClass":0:{}')]);
        touch($this->dir . 'unprocessed/a.txt', time() - 600);

        $this->writeQueueFile('b.txt', [$this->row($this->eventBlob('https://example.com/later-file'))]);
        touch($this->dir . 'unprocessed/b.txt', time() - 60);

        $urls = $this->drain();

        $this->assertSame(['https://example.com/later-file'], $urls);
        $this->assertCount(0, glob($this->dir . 'unprocessed/*'));
        $this->assertCount(2, glob($this->dir . 'archive/*'), 'both files should be consumed');
    }

    /** The upgrade case, end to end through the real queue. */
    public function testEventsQueuedBeforeTheRelocationStillDrain(): void
    {
        $this->writeQueueFile('legacy.txt', [
            $this->row($this->legacyEventBlob('https://example.com/queued-before-upgrade')),
            $this->row($this->eventBlob('https://example.com/queued-after-upgrade')),
        ]);

        $urls = $this->drain();

        $this->assertSame([
            'https://example.com/queued-before-upgrade',
            'https://example.com/queued-after-upgrade',
        ], $urls, 'a pre-upgrade event must not be lost, nor block the one behind it');
        $this->assertCount(0, glob($this->dir . 'unprocessed/*'));
    }

    public function testAFileOfNothingButBadRowsIsConsumedRatherThanRetriedForever(): void
    {
        $this->writeQueueFile('bad.txt', [
            $this->row('O:8:"stdClass":0:{}'),
            $this->row('not-even-serialized'),
        ]);

        $this->assertSame([], $this->drain());
        $this->assertCount(0, glob($this->dir . 'unprocessed/*'));
        $this->assertCount(1, glob($this->dir . 'archive/*'));
    }
}
