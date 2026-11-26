<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\PrivateFiles;
use OWA\Module\Base\Classes\FileEventQueue;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * The tracking queue's two directories: the web server writes only the inbox
 * (owa-data/logs/incoming-tracking-events/), and the drain copies its files
 * into the queue's own directory (owa-data/queue/), which it alone writes
 * (FileEventQueue::intake()). Only the queue's own names are taken, each a
 * plain file of its own; a link is not followed and nothing is renamed out of
 * the inbox.
 */
final class FileQueueInboxTest extends TestCase
{
    use CapturesLog;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/owa-inbox-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->root . 'inbox', 0775, true);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    private function queue(): FileEventQueue
    {
        return new FileEventQueue(['path' => $this->root . 'queue/', 'inbox' => $this->root . 'inbox/', 'queue_name' => 'tracker-ingest']);
    }

    private static function envelope(string $n): array
    {
        return ['v' => 1, 'type' => 'page_view', 'properties' => ['n' => $n], 'queued_at' => 1];
    }

    private function inbox(string $name): string
    {
        return $this->root . 'inbox/' . $name;
    }

    public function testASendGoesToTheInboxAndTheDrainCopiesItIntoTheQueue(): void
    {
        $q = $this->queue();
        $this->assertTrue($q->send(self::envelope('a')));

        $file = $this->inbox(PrivateFiles::name('events', '.txt'));
        $this->assertFileExists($file, 'the web server writes the inbox, by the hashed name');
        $this->assertDirectoryDoesNotExist($this->root . 'queue', 'and makes nothing else');

        $got = $this->queue()->receive(10, 300);
        $this->assertSame(['a'], array_map(fn ($m) => $m->envelope['properties']['n'], $got));
        $this->assertFileDoesNotExist($file, 'taken in');

        $batches = glob($this->root . 'queue/processing/*.txt');
        $this->assertCount(1, $batches);
        $this->assertSame(0640, fileperms($batches[0]) & 0777, 'a file of the drain\'s own');
    }

    public function testAnotherNameInTheInboxIsLeftAlone(): void
    {
        file_put_contents($this->inbox('channels.php'), "<?php return [];\n");

        $this->assertSame([], $this->queue()->receive(10, 300));
        $this->assertFileExists($this->inbox('channels.php'));
        $this->assertSame([], glob($this->root . 'queue/*/channels.php'));
    }

    public function testALinkAtTheQueuesOwnNameIsNotFollowed(): void
    {
        file_put_contents($this->root . 'secret.txt', json_encode(['r' => 0, 'e' => self::envelope('secret')]) . "\n");
        symlink($this->root . 'secret.txt', $this->inbox(PrivateFiles::name('events', '.txt')));

        $this->assertSame([], $this->queue()->receive(10, 300));
        $this->assertFileExists($this->root . 'secret.txt', 'untouched');
        $this->assertTrue(is_link($this->inbox(PrivateFiles::name('events', '.txt'))), 'left where it is');
    }

    public function testAHardLinkToAnotherFileIsNotTakenIn(): void
    {
        file_put_contents($this->root . 'other.txt', json_encode(['r' => 0, 'e' => self::envelope('other')]) . "\n");
        link($this->root . 'other.txt', $this->inbox(PrivateFiles::name('events', '.txt')));

        $log = $this->logDuring(fn () => $this->assertSame([], $this->queue()->receive(10, 300)));
        $this->assertStringContainsString('is not a plain file of its own', $log);
    }

    public function testAQueueDirectoryThatIsALinkIsRefused(): void
    {
        mkdir($this->root . 'elsewhere');
        symlink($this->root . 'elsewhere', $this->root . 'queue');

        $this->assertFalse($this->queue()->provision());
        $this->assertSame(['.', '..'], scandir($this->root . 'elsewhere'), 'nothing made through it');
    }

    public function testADelayedSendWaitsInTheQueueForItsMinute(): void
    {
        $q = $this->queue();
        $this->assertTrue($q->send(self::envelope('later'), 600));
        $this->assertCount(1, glob($this->inbox('delayed_*_' . \OWA\Core\CoreAPI::generateInstanceSpecificHash() . '.txt')));

        $this->assertSame([], $this->queue()->receive(10, 300), 'not due');
        $this->assertSame([], glob($this->inbox('delayed_*')), 'taken in');
        $this->assertCount(1, glob($this->root . 'queue/delayed/*.txt'));
    }

    public function testEveryDirectoryTheQueueMakesHasAnIndexAndNoneIsTakenAsABatch(): void
    {
        $q = $this->queue();
        $this->assertTrue($q->provision());

        foreach (['inbox', 'queue', 'queue/unprocessed', 'queue/processing', 'queue/delayed', 'queue/archive', 'queue/dead-letter'] as $d) {
            $this->assertFileExists($this->root . $d . '/index.php', $d);
        }

        $this->assertSame([], $q->receive(10, 300));
        $this->assertFileExists($this->root . 'queue/unprocessed/index.php');
    }
}
