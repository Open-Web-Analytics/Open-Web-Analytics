<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use OWA\Core\IntakeMessage;
use OWA\Module\Sqs\Classes\Sqs;
use OWA\Module\Sqs\Classes\SqsQueue;

/**
 * The SQS tracking intake against the contract (PLAN 2.30.4a), each SDK call
 * answered by Aws\MockHandler and recorded: what OWA asks SQS to do, without
 * AWS. tests/SqsLiveTest.php runs the same contract against real queues.
 *
 * No database: this runs in CI.
 */
final class SqsQueueTest extends TestCase
{
    private const MAIN = 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-tracker-ingest-test';
    private const DLQ  = self::MAIN . '-dlq';

    private MockHandler $mock;

    /** @var array<int, array{0:string,1:array}> command name and its arguments, in order */
    private array $sent = array();

    private string $cache;

    /** @var array<string,mixed> */
    private array $saved = array();

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/owa-sqs-' . bin2hex(random_bytes(4)) . '/';

        foreach (array(array('base', 'cache_dir'), array('sqs', 'region')) as [$m, $k]) {
            $this->saved["$m.$k"] = \OWA\Core\CoreAPI::getSetting($m, $k);
        }
        \OWA\Core\CoreAPI::setSetting('base', 'cache_dir', $this->cache);
        \OWA\Core\CoreAPI::setSetting('sqs', 'region', 'us-east-1');

        $this->mock = new MockHandler();
        Sqs::$handler = $this->mock;
    }

    protected function tearDown(): void
    {
        Sqs::$handler = null;

        foreach ($this->saved as $id => $value) {
            [$m, $k] = explode('.', $id);
            \OWA\Core\CoreAPI::setSetting($m, $k, $value);
        }

        exec('rm -rf ' . escapeshellarg($this->cache));
    }

    /** Queue SDK answers; each records the command it answered. */
    private function answers(...$results): void
    {
        foreach ($results as $r) {
            $this->mock->append(function (CommandInterface $cmd) use ($r) {
                $this->sent[] = array($cmd->getName(), $cmd->toArray());

                if ($r instanceof \Throwable) {
                    return $r;
                }

                return new Result($r);
            });
        }
    }

    private static function sqsError(string $code, CommandInterface $cmd = null): SqsException
    {
        return new SqsException($code, $cmd ?? new \Aws\Command('X'), array('code' => $code, 'message' => $code));
    }

    /** A queue whose URL is already known, so a test's first answer is its own call. */
    private function queue(array $map = array()): SqsQueue
    {
        $q = new SqsQueue($map + array('name' => 'owa-tracker-ingest-test'));

        $ref = new ReflectionProperty($q, 'url');
        $ref->setAccessible(true);
        $ref->setValue($q, self::MAIN);

        $dlq = $q->deadLetterQueue();
        $ref->setValue($dlq, self::DLQ);

        return $q;
    }

    private static function envelope(string $n): array
    {
        return array('v' => 1, 'type' => 'page_view', 'properties' => array('n' => $n), 'queued_at' => 1);
    }

    // ---------------------------------------------------------------------
    // Names
    // ---------------------------------------------------------------------

    public function testTheNameIsDerivedFromWhereTheBeaconsLand(): void
    {
        $saved = \OWA\Core\CoreAPI::getSetting('base', 'db_name');

        try {
            $a = Sqs::queueName();
            $this->assertMatchesRegularExpression('/^owa-tracker-ingest-[0-9a-f]{12}$/', $a);
            $this->assertSame($a, Sqs::queueName(), 'the same every time');

            \OWA\Core\CoreAPI::setSetting('base', 'db_name', $saved . '_other');
            $this->assertNotSame($a, Sqs::queueName(), 'another database, another queue');
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'db_name', $saved);
        }

        $this->assertSame($a . '-dlq', Sqs::deadLetterName($a));
    }

    public function testThereIsNoQueueNameSetting(): void
    {
        $settings = require dirname(__DIR__) . '/modules/Sqs/settings.php';

        $this->assertSame(array('region', 'provisioned'), array_keys($settings['settings']));
    }

    // ---------------------------------------------------------------------
    // Provisioning
    // ---------------------------------------------------------------------

    /** The dead-letter queue first, then the main one redriving to it: 6 receives, 14 days. */
    public function testProvisioningCreatesBothQueuesWithTheRedrive(): void
    {
        $this->answers(
            array('QueueUrl' => self::DLQ),
            array('Attributes' => array('QueueArn' => 'arn:aws:sqs:us-east-1:123456789012:owa-tracker-ingest-test-dlq')),
            array('QueueUrl' => self::MAIN)
        );

        $q = new SqsQueue(array('name' => 'owa-tracker-ingest-test'));

        $this->assertTrue($q->provision());

        $this->assertSame(array('CreateQueue', 'GetQueueAttributes', 'CreateQueue'), array_column($this->sent, 0));
        $this->assertSame('owa-tracker-ingest-test-dlq', $this->sent[0][1]['QueueName']);
        $this->assertSame('1209600', $this->sent[0][1]['Attributes']['MessageRetentionPeriod']);
        $this->assertArrayNotHasKey('RedrivePolicy', $this->sent[0][1]['Attributes'], 'a dead-letter queue has none');

        $main = $this->sent[2][1];
        $this->assertSame('owa-tracker-ingest-test', $main['QueueName']);
        $this->assertSame('1209600', $main['Attributes']['MessageRetentionPeriod']);
        $this->assertSame(array(
            'deadLetterTargetArn' => 'arn:aws:sqs:us-east-1:123456789012:owa-tracker-ingest-test-dlq',
            'maxReceiveCount'     => 6,
        ), json_decode($main['Attributes']['RedrivePolicy'], true));

        $this->assertSame(self::MAIN, $q->url(false));
    }

    /** A queue that exists with other attributes -- a limit changed -- is updated, not refused. */
    public function testProvisioningAQueueThatExistsDifferentlyUpdatesIt(): void
    {
        $this->answers(
            array('QueueUrl' => self::DLQ),
            array('Attributes' => array('QueueArn' => 'arn:dlq')),
            self::sqsError('QueueAlreadyExists'),
            array('QueueUrl' => self::MAIN),
            array()
        );

        $this->assertTrue((new SqsQueue(array('name' => 'owa-tracker-ingest-test')))->provision());
        $this->assertSame(array('CreateQueue', 'GetQueueAttributes', 'CreateQueue', 'GetQueueUrl', 'SetQueueAttributes'),
            array_column($this->sent, 0));
        $this->assertSame(self::MAIN, $this->sent[4][1]['QueueUrl']);
    }

    public function testAProvisioningFailureIsReportedNotThrown(): void
    {
        $this->answers(self::sqsError('AccessDenied'));

        $q = new SqsQueue(array('name' => 'owa-tracker-ingest-test'));

        $this->assertFalse($q->provision());
        $this->assertSame('AccessDenied', $q->lastError());
    }

    /** The URL is looked up once and kept, so a beacon costs one request. */
    public function testTheUrlIsLookedUpOnceAndKept(): void
    {
        $this->answers(array('QueueUrl' => self::MAIN), array(), array());

        (new SqsQueue(array('name' => 'owa-tracker-ingest-test')))->send(self::envelope('a'));
        (new SqsQueue(array('name' => 'owa-tracker-ingest-test')))->send(self::envelope('b'));

        $this->assertSame(array('GetQueueUrl', 'SendMessage', 'SendMessage'), array_column($this->sent, 0));
        $this->assertFileExists($this->cache . 'sqs/owa-tracker-ingest-test.url');
    }

    /** A queue that does not exist yet is created on first use, as the file queue makes its directories. */
    public function testAMissingQueueIsProvisionedOnFirstUse(): void
    {
        $this->answers(
            self::sqsError('AWS.SimpleQueueService.NonExistentQueue'),
            array('QueueUrl' => self::DLQ),
            array('Attributes' => array('QueueArn' => 'arn:dlq')),
            array('QueueUrl' => self::MAIN),
            array()
        );

        $this->assertTrue((new SqsQueue(array('name' => 'owa-tracker-ingest-test')))->send(self::envelope('a')));
        $this->assertSame(array('GetQueueUrl', 'CreateQueue', 'GetQueueAttributes', 'CreateQueue', 'SendMessage'),
            array_column($this->sent, 0));
    }

    // ---------------------------------------------------------------------
    // The contract
    // ---------------------------------------------------------------------

    public function testSendPutsTheEnvelopeOnTheQueue(): void
    {
        $this->answers(array(), array());

        $q = $this->queue();
        $this->assertTrue($q->send(self::envelope('a')));
        $this->assertTrue($q->send(self::envelope('b'), 3600, true));

        $this->assertSame(self::envelope('a'), json_decode($this->sent[0][1]['MessageBody'], true));
        $this->assertSame(0, $this->sent[0][1]['DelaySeconds']);
        $this->assertArrayNotHasKey('MessageAttributes', $this->sent[0][1]);

        $this->assertSame(900, $this->sent[1][1]['DelaySeconds'], 'SQS\'s longest delay');
        $this->assertSame('1', $this->sent[1][1]['MessageAttributes']['owa_replayed']['StringValue']);
    }

    public function testAFailedSendReturnsFalse(): void
    {
        $this->answers(self::sqsError('ServiceUnavailable'));

        $this->assertFalse($this->queue()->send(self::envelope('a')));
    }

    /** Ten at a time, until $max or an empty answer; the count is SQS's own. */
    public function testReceiveGathersPagesAndReadsTheMessages(): void
    {
        $page = fn (int $from, int $n) => array('Messages' => array_map(fn ($i) => array(
            'ReceiptHandle' => "r$i",
            'Body'          => json_encode(self::envelope("m$i")),
            'Attributes'    => array('ApproximateReceiveCount' => '3'),
        ), range($from, $from + $n - 1)));

        $this->answers($page(1, 10), $page(11, 4), array());

        $got = $this->queue()->receive(100, 300);

        $this->assertCount(14, $got);
        $this->assertSame(array('ReceiveMessage', 'ReceiveMessage', 'ReceiveMessage'), array_column($this->sent, 0));
        $this->assertSame(10, $this->sent[0][1]['MaxNumberOfMessages']);
        $this->assertSame(300, $this->sent[0][1]['VisibilityTimeout']);
        $this->assertSame('r1', $got[0]->receipt);
        $this->assertSame(self::envelope('m1'), $got[0]->envelope);
        $this->assertSame(3, $got[0]->receive_count);
        $this->assertFalse($got[0]->replayed);
    }

    /** A received message as SQS sends it: with the attribute digest the SDK checks. */
    private static function signed(array $m): array
    {
        if (!empty($m['MessageAttributes'])) {
            $md5 = new ReflectionMethod(\Aws\Sqs\SqsClient::class, 'calculateMessageAttributesMd5');
            $md5->setAccessible(true);
            $m['MD5OfMessageAttributes'] = $md5->invoke(null, $m);
        }

        return $m;
    }

    public function testADeadLettersAttributesAreRead(): void
    {
        $this->answers(array('Messages' => array(self::signed(array(
            'ReceiptHandle'     => 'r',
            'Body'              => 'O:9:"owa_event":0:{}',
            'Attributes'        => array('ApproximateReceiveCount' => '1'),
            'MessageAttributes' => array(
                'owa_reason'   => array('DataType' => 'String', 'StringValue' => 'gave up'),
                'owa_dead_at'  => array('DataType' => 'String', 'StringValue' => '1700000000'),
                'owa_replayed' => array('DataType' => 'String', 'StringValue' => '1'),
            ),
        )))), array());

        $m = $this->queue()->deadLetterQueue()->receive(10, 300)[0];

        $this->assertNull($m->envelope, 'a body that is not an envelope');
        $this->assertSame('O:9:"owa_event":0:{}', $m->raw);
        $this->assertSame('gave up', $m->reason);
        $this->assertSame(1700000000, $m->dead_at);
        $this->assertTrue($m->replayed);
    }

    public function testAckDeletesAndReleaseHidesForTheDelay(): void
    {
        $this->answers(array(), array(), array());

        $q = $this->queue();
        $m = new IntakeMessage('rh', self::envelope('a'), 1);

        $this->assertTrue($q->ack($m));
        $this->assertTrue($q->release($m, 21600));
        $this->assertTrue($q->release($m, 999999));

        $this->assertSame(array('DeleteMessage', 'rh'), array($this->sent[0][0], $this->sent[0][1]['ReceiptHandle']));
        $this->assertSame('ChangeMessageVisibility', $this->sent[1][0]);
        $this->assertSame(21600, $this->sent[1][1]['VisibilityTimeout']);
        $this->assertSame(43200, $this->sent[2][1]['VisibilityTimeout'], 'SQS\'s longest visibility');
    }

    /** To the dead-letter queue with why and when, then off the main one; a body that never decoded goes as it was. */
    public function testADeadLetterIsSentOnThenDeleted(): void
    {
        $this->answers(array(), array(), array(), array());

        $q = $this->queue();

        $this->assertTrue($q->deadLetter(new IntakeMessage('rh', self::envelope('a'), 6, '', true), 'Not ingested in 6 attempts.'));
        $this->assertTrue($q->deadLetter(new IntakeMessage('rh2', null, 1, 'garbage'), 'Not an envelope.'));

        $this->assertSame(array('SendMessage', 'DeleteMessage', 'SendMessage', 'DeleteMessage'), array_column($this->sent, 0));
        $this->assertSame(self::DLQ, $this->sent[0][1]['QueueUrl']);
        $this->assertSame('Not ingested in 6 attempts.', $this->sent[0][1]['MessageAttributes']['owa_reason']['StringValue']);
        $this->assertSame('1', $this->sent[0][1]['MessageAttributes']['owa_replayed']['StringValue']);
        $this->assertSame(self::MAIN, $this->sent[1][1]['QueueUrl']);
        $this->assertSame('garbage', $this->sent[2][1]['MessageBody']);
    }

    /** If the dead letter is not written, the message stays on the main queue. */
    public function testADeadLetterThatCannotBeSentIsNotDeleted(): void
    {
        $this->answers(self::sqsError('ServiceUnavailable'));

        $this->assertFalse($this->queue()->deadLetter(new IntakeMessage('rh', self::envelope('a'), 6), 'x'));
        $this->assertSame(array('SendMessage'), array_column($this->sent, 0));
    }

    public function testADeadLetterQueueHasNoneOfItsOwn(): void
    {
        $this->assertNull($this->queue()->deadLetterQueue()->deadLetterQueue());
    }

    public function testEmptinessAndStatsComeFromTheQueueAttributes(): void
    {
        $this->answers(
            array('Attributes' => array('ApproximateNumberOfMessages' => '0')),
            array('Attributes' => array('ApproximateNumberOfMessages' => '4')),
            array('Attributes' => array('ApproximateNumberOfMessages' => '4',
                'ApproximateNumberOfMessagesNotVisible' => '2', 'ApproximateNumberOfMessagesDelayed' => '1'))
        );

        $q = $this->queue();

        $this->assertTrue($q->isProbablyEmpty());
        $this->assertFalse($q->isProbablyEmpty());
        $this->assertSame(array('messages' => 7, 'oldest_age' => null), $q->stats());
    }

    /** A queue nobody can reach is not empty: the drain must not skip a minute it cannot see. */
    public function testAnUnreachableQueueIsNotEmpty(): void
    {
        $this->answers(self::sqsError('ServiceUnavailable'));

        $this->assertFalse($this->queue()->isProbablyEmpty());
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    public function testCredentialsAreNeverStored(): void
    {
        $this->assertStringContainsString('default chain', Sqs::credentialSource());

        $settings = require dirname(__DIR__) . '/modules/Sqs/settings.php';
        $this->assertSame(array(), array_filter(array_keys($settings['settings']),
            fn ($k) => preg_match('/key|secret|credential/i', $k)));
    }

    /** Saving the region provisions the queues; saving anything else, or another module's, does not. */
    public function testSavingTheRegionProvisions(): void
    {
        $module = new \OWA\Module\Sqs\Module();
        $d      = \OWA\Core\CoreAPI::getEventDispatch();
        $saved  = function (string $m, array $keys) use ($d) {
            $e = $d->makeEvent('base.install_settings_saved');
            $e->set('module', $m);
            $e->set('keys', $keys);
            return $e;
        };

        $module->onSettingsSaved($saved('base', array('region')));
        $module->onSettingsSaved($saved('sqs', array('provisioned')));
        $this->assertSame(array(), $this->sent);

        $this->answers(
            array('QueueUrl' => self::DLQ),
            array('Attributes' => array('QueueArn' => 'arn:dlq')),
            array('QueueUrl' => self::MAIN)
        );

        $c = \OWA\Core\CoreAPI::configSingleton();

        try {
            $this->assertSame(OWA_EHS_EVENT_HANDLED, $module->onSettingsSaved($saved('sqs', array('region'))));
            $this->assertSame(array('CreateQueue', 'GetQueueAttributes', 'CreateQueue'), array_column($this->sent, 0));

            $outcome = (array) \OWA\Core\CoreAPI::getSetting('sqs', 'provisioned');
            $this->assertTrue($outcome['ok'], 'recorded for the settings screen');
            $this->assertSame(self::MAIN, $outcome['main']);
        } finally {
            // provision() records its outcome where the settings screen reads it.
            $c->set('sqs', 'provisioned', null);
            if (owa_test_db_available()) {
                \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DELETE FROM %s WHERE module = ? AND name = ?',
                    \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName()), array('sqs', 'provisioned'));
            }
        }
    }

    public function testTheTypeIsRegisteredForTheIntakeSetting(): void
    {
        $module = new \OWA\Module\Sqs\Module();

        $this->assertSame(SqsQueue::class,
            \OWA\Core\CoreAPI::serviceSingleton()->getMapValue('event_queue_types', 'sqs')[0]);
        $this->assertSame('sqs', $module->name);
    }
}
