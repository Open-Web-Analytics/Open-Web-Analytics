<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';

use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use OWA\Core\IntakeMessage;
use OWA\Core\IntakeQueue;
use OWA\Module\Base\Classes\FileEventQueue;
use OWA\Module\Base\Classes\SystemHealth;
use OWA\Module\Base\Classes\TrackerIngest;
use OWA\Module\Sqs\Classes\Sqs;
use OWA\Module\Sqs\Classes\SqsQueue;

/**
 * The file fallback: a beacon the intake does not take is kept in the file
 * queue, forwarded once the intake answers, and shown on the health screens;
 * a drain whose intake failed fails.
 *
 * Against in-memory queues and, end to end, an SqsQueue answered by
 * Aws\MockHandler and a FileEventQueue in a temporary directory. Nothing
 * reaches AWS or the install's own queue directory.
 */
final class TrackerIngestFallbackTest extends TestCase
{
    use CapturesLog;

    private string $dir = '';

    /** @var array<string,mixed> */
    private array $saved = array();

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-fallback-' . bin2hex(random_bytes(4)) . '/';

        foreach (array(array('sqs', 'region'), array('base', 'instance_role'), array('base', 'tracker_ingest_queue_type')) as [$m, $k]) {
            $this->saved["$m.$k"] = \OWA\Core\CoreAPI::getSetting($m, $k);
        }
        \OWA\Core\CoreAPI::setSetting('sqs', 'region', 'us-east-1');
        \OWA\Core\CoreAPI::setSetting('base', 'instance_role', 'logger');
    }

    protected function tearDown(): void
    {
        TrackerIngest::$queue    = null;
        TrackerIngest::$fallback = null;
        Sqs::$handler            = null;

        foreach ($this->saved as $id => $value) {
            [$m, $k] = explode('.', $id);
            \OWA\Core\CoreAPI::setSetting($m, $k, $value);
        }

        gc_collect_cycles();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function event(string $n = 'a'): \OWA\Module\Base\Classes\Event
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType('page_view');
        $event->setProperties(array('page_url' => 'https://example.test/' . $n, 'site_id' => 'fallback-test'));

        return $event;
    }

    private function use(IntakeQueue $queue, ?IntakeQueue $fallback): void
    {
        TrackerIngest::$queue    = $queue;
        TrackerIngest::$fallback = $fallback;
    }

    private function fileQueue(): FileEventQueue
    {
        return new FileEventQueue(array('path' => $this->dir, 'max_receives' => TrackerIngest::MAX_RECEIVES));
    }

    // ---------------------------------------------------------------------
    // Sending
    // ---------------------------------------------------------------------

    public function testABeaconTheIntakeRefusesIsKeptInTheFallbackAndLoggedAsAnError(): void
    {
        $main = new FallbackTestIntake();
        $main->accepts = false;
        $main->error   = 'Connection timed out';
        $kept = new FallbackTestIntake();
        $this->use($main, $kept);

        $log = $this->logDuring(function () use (&$ok) {
            $ok = TrackerIngest::send($this->event());
        });

        $this->assertTrue($ok, 'kept is queued');
        $this->assertCount(1, $kept->sent);
        $this->assertSame('https://example.test/a', $kept->sent[0][0]['properties']['page_url']);
        $this->assertStringContainsString('[ERROR]', $log);
        $this->assertStringContainsString('Connection timed out', $log);
    }

    public function testARetryIsKeptWithItsDelay(): void
    {
        $main = new FallbackTestIntake();
        $main->accepts = false;
        $kept = new FallbackTestIntake();
        $this->use($main, $kept);

        $arrived = TrackerIngest::envelope($this->event());
        $this->logDuring(fn () => TrackerIngest::retryLater($this->event(), $arrived));

        $this->assertSame(array($arrived, 60), $kept->sent[0]);
    }

    public function testATakenBeaconDoesNotTouchTheFallback(): void
    {
        $main = new FallbackTestIntake();
        $kept = new FallbackTestIntake();
        $this->use($main, $kept);

        $this->assertTrue(TrackerIngest::send($this->event()));
        $this->assertCount(1, $main->sent);
        $this->assertSame(array(), $kept->sent);
    }

    /** The fallback could never forward it, so it is not kept: refused, and said so. */
    public function testABeaconOverTheIntakesSizeLimitIsRefusedNotKept(): void
    {
        $main = new FallbackTestIntake();
        $main->max_bytes = 200;
        $kept = new FallbackTestIntake();
        $this->use($main, $kept);

        $big = $this->event();
        $big->set('page_title', str_repeat('x', 300));

        $log = $this->logDuring(function () use ($big, &$ok) {
            $ok = TrackerIngest::send($big);
        });

        $this->assertFalse($ok);
        $this->assertSame(array(), $main->sent);
        $this->assertSame(array(), $kept->sent);
        $this->assertStringContainsString('size limit of 200 bytes', $log);
    }

    public function testTheFileQueueHasNoFallback(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'tracker_ingest_queue_type', 'file');

        $this->assertNull(TrackerIngest::fallback());
    }

    /** End to end: SQS times out on log.php's path, and the beacon is a line in the file queue. */
    public function testAnSqsTimeoutKeepsTheBeaconInTheFileQueue(): void
    {
        $mock = new MockHandler();
        $timeout = fn ($cmd) => new \Aws\Exception\AwsException('cURL error 28: Operation timed out', $cmd,
            array('connection_error' => true));
        // The first attempt and its one retry (Sqs::LOGGER_RETRIES).
        $mock->append($timeout, $timeout);
        Sqs::$handler = $mock;

        $sqs = new SqsQueue(array('name' => 'owa-tracker-ingest-test'));
        (new ReflectionProperty($sqs, 'url'))->setValue($sqs, 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-tracker-ingest-test');

        $file = $this->fileQueue();
        $this->use($sqs, $file);

        $log = $this->logDuring(function () use (&$ok) {
            $ok = TrackerIngest::send($this->event('timed-out'));
        });

        $this->assertTrue($ok);
        $this->assertSame(0, $mock->count(), 'both attempts made');
        $this->assertStringContainsString('timed out', $log);

        $got = $file->receive(10, 300);
        $this->assertCount(1, $got);
        $this->assertSame('https://example.test/timed-out', $got[0]->envelope['properties']['page_url']);
    }

    // ---------------------------------------------------------------------
    // Forwarding
    // ---------------------------------------------------------------------

    private function kept(int $n): FallbackTestIntake
    {
        $kept = new FallbackTestIntake();

        for ($i = 1; $i <= $n; $i++) {
            $kept->pending[] = new IntakeMessage("r$i", TrackerIngest::envelope($this->event("k$i")), 1);
        }

        return $kept;
    }

    public function testForwardingSendsTheKeptBeaconsOnAndAcksThem(): void
    {
        $main = new FallbackTestIntake();
        $kept = $this->kept(3);
        $this->use($main, $kept);

        $this->assertSame(array('forwarded' => 3, 'waiting' => 0, 'dead' => 0), TrackerIngest::forwardFallback(time() + 30));
        $this->assertCount(3, $main->sent);
        $this->assertSame(array('ack', 'ack', 'ack'), array_column($kept->calls, 0));
    }

    /** Nothing is received from the fallback while the intake cannot be read: its receive limit is not spent. */
    public function testNothingIsForwardedWhileTheIntakeCannotBeRead(): void
    {
        $main = new FallbackTestIntake();
        $main->readable = false;
        $kept = $this->kept(2);
        $this->use($main, $kept);

        $this->assertSame(array('forwarded' => 0, 'waiting' => 2, 'dead' => 0), TrackerIngest::forwardFallback(time() + 30));
        $this->assertSame(0, $kept->receives);
        $this->assertSame(array(), $main->sent);
    }

    /** The intake stops answering part-way: what was received and not sent waits a minute. */
    public function testWhenTheIntakeStopsTakingThemTheRestWait(): void
    {
        $main = new FallbackTestIntake();
        $main->accept_only = 1;
        $kept = $this->kept(3);
        $this->use($main, $kept);

        $this->assertSame(array('forwarded' => 1, 'waiting' => 2, 'dead' => 0), TrackerIngest::forwardFallback(time() + 30));
        $this->assertSame(array(array('ack'), array('release', 60), array('release', 60)), $kept->calls);
    }

    public function testWhatCannotBeForwardedIsDeadLetteredInTheFallback(): void
    {
        $main = new FallbackTestIntake();
        $main->max_bytes = 400;
        $kept = new FallbackTestIntake();
        $big  = $this->event('big');
        $big->set('page_title', str_repeat('x', 500));
        $kept->pending = array(
            new IntakeMessage('r1', null, 1, 'garbage'),
            new IntakeMessage('r2', TrackerIngest::envelope($big), 1),
        );
        $this->use($main, $kept);

        $this->assertSame(array('forwarded' => 0, 'waiting' => 0, 'dead' => 2), TrackerIngest::forwardFallback(time() + 30));
        $this->assertSame(array('dead', 'dead'), array_column($kept->calls, 0));
    }

    /** End to end: kept in a file, forwarded to SQS, gone from the file. */
    public function testAKeptLineIsForwardedToSqs(): void
    {
        $file = $this->fileQueue();
        $file->send(TrackerIngest::envelope($this->event('kept')));

        $mock = new MockHandler();
        $sent = array();
        $mock->append(
            new Result(array('Attributes' => array('ApproximateNumberOfMessages' => '0'))),
            function ($cmd) use (&$sent) {
                $sent[] = json_decode($cmd['MessageBody'], true);
                return new Result(array());
            }
        );
        Sqs::$handler = $mock;

        $sqs = new SqsQueue(array('name' => 'owa-tracker-ingest-test'));
        (new ReflectionProperty($sqs, 'url'))->setValue($sqs, 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-tracker-ingest-test');
        $this->use($sqs, $file);

        $this->assertSame(1, TrackerIngest::forwardFallback(time() + 30)['forwarded']);
        $this->assertSame('https://example.test/kept', $sent[0]['properties']['page_url']);

        unset($file);
        TrackerIngest::$fallback = null;
        gc_collect_cycles();

        $this->assertTrue($this->fileQueue()->isProbablyEmpty());
    }

    // ---------------------------------------------------------------------
    // The drain command
    // ---------------------------------------------------------------------

    private function drain(array $params = array()): \OWA\Module\Base\Controller\DrainTrackerIngestCli
    {
        $class = new ReflectionClass(\OWA\Module\Base\Controller\DrainTrackerIngestCli::class);
        $cli   = $class->newInstanceWithoutConstructor();
        $class->getProperty('params')->setValue($cli, $params + array('seconds' => 2));

        $this->logDuring(fn () => $cli->action());

        return $cli;
    }

    /** No sqs:ReceiveMessage, say: the drain fails rather than finishing with nothing done. */
    public function testADrainWhoseIntakeFailedFails(): void
    {
        $main = new FallbackTestIntake();
        $main->pending_but_unreadable = true;
        $this->use($main, null);

        $outcome = $this->drain()->getCliOutcome();

        $this->assertSame('failed', $outcome['outcome']);
        $this->assertStringContainsString('AccessDenied', $outcome['message']);
    }

    public function testADrainThatWorkedIsOk(): void
    {
        $this->use(new FallbackTestIntake(), null);

        $this->assertSame('ok', $this->drain()->getCliOutcome()['outcome']);
    }

    /** A logging node forwards and ingests nothing. */
    public function testForwardOnlyDoesNotDrain(): void
    {
        $main = new FallbackTestIntake();
        $main->pending[] = new IntakeMessage('m1', TrackerIngest::envelope($this->event()), 1);
        $kept = $this->kept(1);
        $this->use($main, $kept);

        $outcome = $this->drain(array('forward_only' => 1))->getCliOutcome();

        $this->assertSame('ok', $outcome['outcome']);
        $this->assertCount(1, $main->sent, 'forwarded');
        $this->assertSame(0, $main->receives, 'not drained');
    }

    public function testForwardOnlyFailsWhileTheIntakeDoesNotAnswer(): void
    {
        $main = new FallbackTestIntake();
        $main->readable = false;
        $this->use($main, $this->kept(1));

        $this->assertSame('failed', $this->drain(array('forward_only' => 1))->getCliOutcome()['outcome']);
    }

    // ---------------------------------------------------------------------
    // Health
    // ---------------------------------------------------------------------

    private static function levels(array $section): array
    {
        return array_column($section['findings'], 'level', 'label');
    }

    public function testAQueueThatCannotBeReadIsRed(): void
    {
        $main = new FallbackTestIntake();
        $main->readable = false;
        $this->use($main, null);

        $intake = SystemHealth::intake();

        $this->assertSame('red', self::levels($intake)['Unreadable']);
        $this->assertStringContainsString('AccessDenied',
            array_column($intake['findings'], 'detail', 'label')['Unreadable']);
    }

    public function testBeaconsKeptInTheFileQueueAreAWarning(): void
    {
        $this->use(new FallbackTestIntake(), $this->kept(2));

        $intake = SystemHealth::intake();

        $this->assertSame('yellow', self::levels($intake)['Kept in the file queue']);
        $this->assertSame(2, $intake['kept']);
    }
}

/** The contract in memory, with the failures the fallback is for. */
class FallbackTestIntake implements IntakeQueue
{
    /** @var IntakeMessage[] */
    public array $pending = array();

    /** @var array<int, array{0:array,1:int}> envelope and delay, per send it took */
    public array $sent = array();

    public array $calls = array();

    public int $receives = 0;

    public bool $accepts = true;

    /** Takes this many sends, then refuses. */
    public ?int $accept_only = null;

    public bool $readable = true;

    /** Says it holds messages, then cannot receive them. */
    public bool $pending_but_unreadable = false;

    public ?int $max_bytes = null;

    public ?string $error = null;

    public function provision()
    {
        return true;
    }

    public function send(array $envelope, $delay = 0, $replayed = false)
    {
        if (!$this->accepts || ($this->accept_only !== null && count($this->sent) >= $this->accept_only)) {
            $this->error = $this->error ?? 'refused';
            return false;
        }

        $this->sent[] = array($envelope, $delay);

        return true;
    }

    public function receive($max, $visibility)
    {
        $this->receives++;

        if ($this->pending_but_unreadable) {
            $this->error = 'AccessDenied';
            return array();
        }

        return array_splice($this->pending, 0, $max);
    }

    public function ack(IntakeMessage $message)
    {
        $this->calls[] = array('ack');

        return true;
    }

    public function release(IntakeMessage $message, $delay)
    {
        $this->calls[] = array('release', $delay);

        return true;
    }

    public function deadLetter(IntakeMessage $message, $reason)
    {
        $this->calls[] = array('dead', $reason);

        return true;
    }

    public function deadLetterQueue()
    {
        return null;
    }

    public function isProbablyEmpty()
    {
        return !$this->pending && !$this->pending_but_unreadable;
    }

    public function stats()
    {
        if (!$this->readable) {
            return array('messages' => null, 'oldest_age' => null, 'error' => 'AccessDenied');
        }

        return array('messages' => count($this->pending), 'oldest_age' => null);
    }

    public function lastError()
    {
        return $this->error;
    }

    public function clearLastError()
    {
        $this->error = null;
    }

    public function maxMessageBytes()
    {
        return $this->max_bytes ?? PHP_INT_MAX;
    }
}
