<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackerIngest;
use OWA\Module\Sqs\Classes\Sqs;
use OWA\Module\Sqs\Classes\SqsQueue;

/**
 * The SQS intake against REAL queues (PLAN 2.30.4a): what the mock in
 * SqsQueueTest cannot show -- that SQS accepts what OWA sends, counts
 * receives, redrives past the limit, and that the dead-letter round trip and
 * replay work end to end.
 *
 * OFF unless OWA_TEST_SQS=1, so CI never reaches AWS. Needs credentials the
 * SDK's default chain finds, allowed the module's actions plus
 * sqs:DeleteQueue on owa-tracker-ingest-*, and a region (OWA_TEST_SQS_REGION,
 * default us-east-1). Each run makes its own pair of queues under a random
 * name and deletes both, pass or fail.
 */
final class SqsLiveTest extends TestCase
{
    private static ?SqsQueue $queue = null;

    /** @var array<string,mixed> */
    private static array $saved = array();

    public static function setUpBeforeClass(): void
    {
        if (getenv('OWA_TEST_SQS') !== '1') {
            return;
        }

        foreach (array(array('base', 'cache_dir'), array('sqs', 'region')) as [$m, $k]) {
            self::$saved["$m.$k"] = \OWA\Core\CoreAPI::getSetting($m, $k);
        }

        \OWA\Core\CoreAPI::setSetting('base', 'cache_dir', sys_get_temp_dir() . '/owa-sqs-live-' . getmypid() . '/');
        \OWA\Core\CoreAPI::setSetting('sqs', 'region', getenv('OWA_TEST_SQS_REGION') ?: 'us-east-1');

        self::$queue = new SqsQueue(array(
            'name'         => Sqs::PREFIX . 'phpunit-' . bin2hex(random_bytes(4)),
            'max_receives' => 2,
        ));
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$queue) {
            return;
        }

        foreach (array(self::$queue, self::$queue->deadLetterQueue()) as $q) {
            $url = $q->url(false);
            if ($url) {
                Sqs::client()->deleteQueue(array('QueueUrl' => $url));
            }
        }

        foreach (self::$saved as $id => $value) {
            [$m, $k] = explode('.', $id);
            \OWA\Core\CoreAPI::setSetting($m, $k, $value);
        }

        TrackerIngest::$queue = null;
    }

    protected function setUp(): void
    {
        if (!self::$queue) {
            $this->markTestSkipped('Talks to AWS SQS: set OWA_TEST_SQS=1 to run it.');
        }
    }

    private static function envelope(string $n): array
    {
        return array('v' => 1, 'type' => 'page_view', 'properties' => array('n' => $n), 'queued_at' => time());
    }

    /** Poll: SQS is eventually consistent, so an empty answer is not yet proof. */
    private static function receiveSoon(\OWA\Core\IntakeQueue $q, int $want, int $visibility = 30): array
    {
        $got = array();

        for ($i = 0; $i < 20 && count($got) < $want; $i++) {
            $got = array_merge($got, $q->receive($want - count($got), $visibility));
            if (count($got) < $want) {
                usleep(500000);
            }
        }

        return $got;
    }

    public function testProvisioningMakesBothQueuesWithTheRedrive(): void
    {
        $this->assertTrue(self::$queue->provision(), (string) self::$queue->lastError());
        $this->assertTrue(self::$queue->provision(), 'idempotent');

        $attributes = Sqs::client()->getQueueAttributes(array(
            'QueueUrl' => self::$queue->url(false), 'AttributeNames' => array('All'),
        ))['Attributes'];

        $this->assertSame('1209600', $attributes['MessageRetentionPeriod']);
        $this->assertSame(2, json_decode($attributes['RedrivePolicy'], true)['maxReceiveCount']);
        $this->assertStringEndsWith('-dlq', json_decode($attributes['RedrivePolicy'], true)['deadLetterTargetArn']);
    }

    /** @depends testProvisioningMakesBothQueuesWithTheRedrive */
    public function testWhatIsSentIsReceivedCountedAndAckedAway(): void
    {
        $q = self::$queue;

        $this->assertTrue($q->send(self::envelope('a'), 0, true));

        $got = self::receiveSoon($q, 1);
        $this->assertCount(1, $got);
        $this->assertSame(self::envelope('a')['properties'], $got[0]->envelope['properties']);
        $this->assertSame(1, $got[0]->receive_count);
        $this->assertTrue($got[0]->replayed);

        $this->assertTrue($q->release($got[0], 0));

        $again = self::receiveSoon($q, 1);
        $this->assertSame(2, $again[0]->receive_count, 'SQS counts the receive');

        $this->assertTrue($q->ack($again[0]));
    }

    /** Past max_receives, SQS itself moves the message: the redrive, with no consumer involved. */
    public function testSqsRedrivesPastTheReceiveLimit(): void
    {
        $q = self::$queue;
        $q->send(self::envelope('poison'));

        for ($i = 1; $i <= 2; $i++) {
            $got = self::receiveSoon($q, 1, 1);
            $this->assertCount(1, $got, "receive $i");
            sleep(2); // never acked or released: as a consumer that died
        }

        // The third receive finds it gone from the main queue: SQS moved it.
        $this->assertSame(array(), $q->receive(10, 1));

        $dead = self::receiveSoon($q->deadLetterQueue(), 1);
        $this->assertSame('poison', $dead[0]->envelope['properties']['n']);
        $q->deadLetterQueue()->ack($dead[0]);
    }

    /** Dead-lettered by OWA, read back with its reason, replayed once by the daily job. */
    public function testADeadLetterRoundTripsAndIsReplayedOnce(): void
    {
        $q = self::$queue;
        $q->send(self::envelope('dead'));

        $got = self::receiveSoon($q, 1);
        $this->assertTrue($q->deadLetter($got[0], 'gave up'));

        TrackerIngest::$queue = $q;
        $counts = array('replayed' => 0);
        for ($i = 0; $i < 20 && !$counts['replayed']; $i++) {
            $counts = TrackerIngest::replay(true, time() + 20);
            if (!$counts['replayed']) {
                usleep(500000);
            }
        }
        $this->assertSame(1, $counts['replayed']);

        $back = self::receiveSoon($q, 1);
        $this->assertSame('dead', $back[0]->envelope['properties']['n']);
        $this->assertTrue($back[0]->replayed, 'marked, so the daily job will not send it round again');
        $this->assertSame(1, $back[0]->receive_count, 'a fresh message, a fresh count');
        $q->ack($back[0]);
    }
}
