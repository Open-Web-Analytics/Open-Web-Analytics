<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\IntakeMessage;
use OWA\Module\Base\Classes\FileEventQueue;

/**
 * The file-backed tracking intake against the contract (PLAN 2.30.3): what is
 * sent is received, acked messages are gone, released ones come back counted,
 * and a consumer that dies leaves its batch to the next one.
 *
 * Each test has its own directory; nothing here touches the install's queue.
 * No database: this runs in CI.
 */
final class FileIntakeQueueTest extends TestCase
{
    private string $dir;

    /** @var bool|mixed */
    private $archive;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-intake-' . bin2hex(random_bytes(4)) . '/';

        $this->archive = \OWA\Core\CoreAPI::getSetting('base', 'archive_old_events');
        \OWA\Core\CoreAPI::setSetting('base', 'archive_old_events', false);
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'archive_old_events', $this->archive);

        gc_collect_cycles();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function queue(): FileEventQueue
    {
        return new FileEventQueue(array('path' => $this->dir, 'queue_name' => 'tracker-ingest'));
    }

    private static function envelope(string $n): array
    {
        return array('v' => 1, 'type' => 'page_view', 'properties' => array('n' => $n), 'queued_at' => 1);
    }

    /** @return string[] the n property of each message */
    private static function names(array $messages): array
    {
        return array_map(fn (IntakeMessage $m) => $m->envelope['properties']['n'] ?? null, $messages);
    }

    /** Pretend every delayed file's minute has come. */
    private function timePasses(): void
    {
        foreach ((array) glob($this->dir . 'delayed/*.txt') as $i => $f) {
            rename($f, $this->dir . 'delayed/' . $i . '.txt');
        }
    }

    public function testWhatIsSentIsReceivedOnceAndAckedAway(): void
    {
        $q = $this->queue();

        $this->assertTrue($q->isProbablyEmpty());
        $this->assertTrue($q->send(self::envelope('a')));
        $this->assertTrue($q->send(self::envelope('b')));
        $this->assertFalse($q->isProbablyEmpty());

        $got = $q->receive(10, 300);

        $this->assertSame(array('a', 'b'), self::names($got));
        $this->assertSame(array(1, 1), array_map(fn ($m) => $m->receive_count, $got));
        $this->assertSame(self::envelope('a'), $got[0]->envelope);

        foreach ($got as $m) {
            $this->assertTrue($q->ack($m));
        }

        $this->assertSame(array(), $q->receive(10, 300));
        $this->assertTrue($q->isProbablyEmpty());
        $this->assertSame(0, $q->depth());
    }

    public function testReceiveHandsOutAtMostMax(): void
    {
        $q = $this->queue();

        foreach (array('a', 'b', 'c') as $n) {
            $q->send(self::envelope($n));
        }

        $first = $q->receive(2, 300);
        $this->assertSame(array('a', 'b'), self::names($first));

        $this->assertSame(array('c'), self::names($q->receive(2, 300)));
    }

    /** A finished batch is archived when archive_old_events is on, and deleted when it is off. */
    public function testAFinishedBatchIsArchivedOrDeleted(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'archive_old_events', true);

        $q = $this->queue();
        $q->send(self::envelope('a'));
        $q->ack($q->receive(10, 300)[0]);

        $this->assertCount(1, glob($this->dir . 'archive/*.txt'));
        $this->assertSame(array(), glob($this->dir . 'processing/*'));
    }

    /** A message sent with a delay waits for its minute. */
    public function testADelayedSendIsNotReceivedBeforeItIsDue(): void
    {
        $q = $this->queue();
        $q->send(self::envelope('later'), 120);

        $this->assertSame(array(), $q->receive(10, 300));
        $this->assertTrue($q->isProbablyEmpty(), 'nothing is due');

        $this->timePasses();

        $this->assertFalse($q->isProbablyEmpty());
        $this->assertSame(array('later'), self::names($q->receive(10, 300)));
    }

    public function testAReleasedMessageComesBackWithItsCountAfterItsDelay(): void
    {
        $q = $this->queue();
        $q->send(self::envelope('a'));

        $first = $q->receive(10, 300)[0];
        $this->assertTrue($q->release($first, 60));

        $this->assertSame(array(), $q->receive(10, 300), 'not before the delay');

        $this->timePasses();

        $again = $q->receive(10, 300);
        $this->assertSame(array('a'), self::names($again));
        $this->assertSame(2, $again[0]->receive_count);
        $this->assertSame(self::envelope('a'), $again[0]->envelope, 'the envelope is unchanged');
    }

    public function testADeadLetterIsKeptWithItsReason(): void
    {
        $q = $this->queue();
        $q->send(self::envelope('a'));

        $this->assertTrue($q->deadLetter($q->receive(10, 300)[0], 'gave up'));
        $this->assertTrue($q->isProbablyEmpty());

        $letters = file($this->dir . 'dead/' . date('Y-m-d') . '.txt');
        $this->assertCount(1, $letters);

        $letter = json_decode($letters[0], true);
        $this->assertSame('gave up', $letter['reason']);
        $this->assertSame(self::envelope('a'), $letter['e']);
    }

    /** A line that is not one -- half-written, or a 1.x serialized event -- is received with no envelope, and kept when dead-lettered. */
    public function testALineThatIsNotAnEnvelopeArrivesWithoutOne(): void
    {
        mkdir($this->dir, 0700, true);
        $legacy = '12:00:00 2026-01-01|*|incoming_tracking_events|*|1|*|' . urlencode('O:9:"owa_event":0:{}');
        file_put_contents($this->dir . 'events.txt', $legacy . "\n{\"r\":0,\"e\":{\"v\":1}}\n{\"r\":0,");

        $q   = $this->queue();
        $got = $q->receive(10, 300);

        $this->assertCount(3, $got);
        $this->assertNull($got[0]->envelope);
        $this->assertSame(array('v' => 1), $got[1]->envelope, 'decoding the line is the queue\'s; reading the envelope is not');
        $this->assertNull($got[2]->envelope);

        $q->deadLetter($got[0], 'not an envelope');

        $letter = json_decode(file($this->dir . 'dead/' . date('Y-m-d') . '.txt')[0], true);
        $this->assertSame($legacy, $letter['raw']);

        // A batch of nothing but bad lines is finished, not retried forever.
        $q->deadLetter($got[1], 'not an envelope');
        $q->deadLetter($got[2], 'not an envelope');
        $this->assertTrue($q->isProbablyEmpty());
    }

    /** A consumer that dies leaves its batch; the next one gets what was not settled, counted again. */
    public function testABatchHeldByADeadConsumerIsReceivedAgain(): void
    {
        $q = $this->queue();
        $q->send(self::envelope('a'));
        $q->send(self::envelope('b'));

        $got = $q->receive(10, 300);
        $q->ack($got[0]);
        unset($q, $got);

        $next  = $this->queue();
        $again = $next->receive(10, 300);

        $this->assertSame(array('b'), self::names($again), 'what was acked is not delivered again');
        $this->assertSame(2, $again[0]->receive_count);
    }

    /** Two consumers never hold the same batch. */
    public function testAHeldBatchIsNotReceivedByAnotherConsumer(): void
    {
        $a = $this->queue();
        $a->send(self::envelope('a'));
        $this->assertCount(1, $a->receive(10, 300));

        $b = $this->queue();
        $this->assertSame(array(), $b->receive(10, 300));

        $b->send(self::envelope('b'));
        $this->assertSame(array('b'), self::names($b->receive(10, 300)), 'a new batch is free');
    }

    /** What arrives after a rotation goes to the next batch, not into the one being read. */
    public function testASendDuringADrainLandsInTheNextBatch(): void
    {
        $q = $this->queue();
        $q->send(self::envelope('a'));

        $first = $q->receive(10, 300);
        $q->send(self::envelope('b'));

        $this->assertSame(array(), $q->receive(10, 300), 'the held batch is not finished');

        $q->ack($first[0]);

        $this->assertSame(array('b'), self::names($q->receive(10, 300)));
    }

    /** A settle for a message this consumer does not hold does nothing. */
    public function testAForeignReceiptIsRefused(): void
    {
        $q = $this->queue();
        $q->send(self::envelope('a'));
        $q->receive(10, 300);

        $this->assertFalse($q->ack(new IntakeMessage(array('batch' => '/elsewhere', 'offset' => 0), self::envelope('x'), 1)));
    }

    public function testPruningRemovesOldArchivesAndDeadLetters(): void
    {
        $q = $this->queue();

        foreach (array('archive/old.txt', 'dead/2020-01-01.txt', 'archive/new.txt') as $f) {
            file_put_contents($this->dir . $f, "x\n");
        }
        touch($this->dir . 'archive/old.txt', time() - 7200);
        touch($this->dir . 'dead/2020-01-01.txt', time() - 7200);

        $this->assertSame(2, $q->pruneArchive(3600));
        $this->assertSame(array($this->dir . 'archive/new.txt'), glob($this->dir . 'archive/*'));
    }

    /** No vendor package on the tracking path (PLAN 2.30.3; the source-checkout boot constraint). */
    public function testTheQueueNeedsNoLoggingLibrary(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/modules/Base/Classes/FileEventQueue.php');

        $this->assertDoesNotMatchRegularExpression('/^use\s+Monolog/m', $source);
        $this->assertStringNotContainsString('exec(', $source);
    }
}
