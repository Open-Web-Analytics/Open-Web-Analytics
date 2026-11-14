<?php

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use OWA\Module\Base\Controller\ServerCheckCli;
use OWA\Module\Sqs\Classes\Sqs;
use OWA\Module\Sqs\Classes\SqsQueue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * cmd=server-check's queue round trip on a collector: send a test message,
 * receive it, delete it, and hand anything else it receives straight back.
 * SQS is Aws\MockHandler: nothing here reaches AWS. The report itself, run
 * through cli.php, is ServerRoleBootTest's.
 */
final class ServerCheckCliTest extends TestCase
{
    private const URL = 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-server-check-test';

    private MockHandler $mock;

    /** @var array<int, array{0:string,1:array}> */
    private array $sent = array();

    /** @var string|null the body of the test message sent */
    private ?string $body = null;

    private $savedType;

    private $savedRegion;

    protected function setUp(): void
    {
        $this->savedType   = \OWA\Core\CoreAPI::getSetting('base', 'request_type');
        $this->savedRegion = \OWA\Core\CoreAPI::getSetting('sqs', 'region');

        // A CLI controller built outside request type cli exits the process.
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
        \OWA\Core\CoreAPI::setSetting('sqs', 'region', 'us-east-1');

        $this->mock   = new MockHandler();
        Sqs::$handler = $this->mock;
    }

    protected function tearDown(): void
    {
        Sqs::$handler = null;
        \OWA\Core\Controller\Cli::$exit_code = 0;

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->savedType);
        \OWA\Core\CoreAPI::setSetting('sqs', 'region', $this->savedRegion);
    }

    /** Queue SDK answers: an array, a Throwable, or a callable given the command. */
    private function answers(...$results): void
    {
        foreach ($results as $r) {
            $this->mock->append(function (CommandInterface $cmd) use ($r) {
                $this->sent[] = array($cmd->getName(), $cmd->toArray());

                if ($cmd->getName() === 'SendMessage') {
                    $this->body = (string) $cmd['MessageBody'];
                }

                if ($r instanceof \Throwable) {
                    return $r;
                }

                return new Result(is_callable($r) ? $r() : $r);
            });
        }
    }

    private function queue(): SqsQueue
    {
        $q = new SqsQueue(array('name' => 'owa-server-check-test'));
        (new ReflectionProperty($q, 'url'))->setValue($q, self::URL);

        return $q;
    }

    private function check(): ServerCheckCli
    {
        return new ServerCheckCli(array());
    }

    public function testItSendsReceivesAndDeletesItsOwnMessageAndReleasesAnyOther(): void
    {
        $this->answers(
            array('MessageId' => 'm-1'),
            fn () => array('Messages' => array(
                array('ReceiptHandle' => 'r-beacon', 'Body' => '{"v":1,"type":"page_view","properties":{},"queued_at":1}'),
                array('ReceiptHandle' => 'r-check', 'Body' => $this->body),
            )),
            array(),   // ReceiveMessage again: nothing more
            array(),   // ChangeMessageVisibility: the beacon goes back
            array()    // DeleteMessage: the test message
        );

        $trip = $this->check()->roundTrip($this->queue());

        $this->assertTrue($trip['ok'], $trip['message']);
        $this->assertStringStartsWith('ok: sent, received and deleted a test message', $trip['message']);
        $this->assertSame(array('SendMessage', 'ReceiveMessage', 'ReceiveMessage', 'ChangeMessageVisibility', 'DeleteMessage'),
            array_column($this->sent, 0));
        $this->assertSame('r-beacon', $this->sent[3][1]['ReceiptHandle']);
        $this->assertSame(0, $this->sent[3][1]['VisibilityTimeout'], 'the beacon is visible to the drain again at once');
        $this->assertSame('r-check', $this->sent[4][1]['ReceiptHandle']);
    }

    /** Not a tracker envelope: a drain that takes it first dead-letters it rather than ingesting it. */
    public function testTheTestMessageIsNotATrackerEnvelope(): void
    {
        $this->answers(array('MessageId' => 'm-1'), fn () => array('Messages' => array(
            array('ReceiptHandle' => 'r-check', 'Body' => $this->body))), array(), array());

        $this->check()->roundTrip($this->queue());

        $envelope = json_decode((string) $this->body, true);

        $this->assertSame('owa-server-check', $envelope['type']);
        $this->assertNull(\OWA\Module\Base\Classes\TrackerIngest::event($envelope));
    }

    public function testASendRefusalIsReported(): void
    {
        $this->answers(new SqsException('denied', new \Aws\Command('SendMessage'),
            array('code' => 'InvalidClientTokenId', 'message' => 'The security token included in the request is invalid.')));

        $trip = $this->check()->roundTrip($this->queue());

        $this->assertFalse($trip['ok']);
        $this->assertSame('failed to send: The security token included in the request is invalid.', $trip['message']);
    }

    public function testAReceiveRefusalIsReported(): void
    {
        $this->answers(array('MessageId' => 'm-1'), new SqsException('denied', new \Aws\Command('ReceiveMessage'),
            array('code' => 'AccessDenied', 'message' => 'not authorized to perform sqs:ReceiveMessage')));

        $trip = $this->check()->roundTrip($this->queue());

        $this->assertFalse($trip['ok']);
        $this->assertSame('sent, but receiving failed: not authorized to perform sqs:ReceiveMessage', $trip['message']);
    }

    public function testADeleteRefusalIsReported(): void
    {
        $this->answers(array('MessageId' => 'm-1'), fn () => array('Messages' => array(
            array('ReceiptHandle' => 'r-check', 'Body' => $this->body))), array(),
            new SqsException('denied', new \Aws\Command('DeleteMessage'),
                array('code' => 'AccessDenied', 'message' => 'not authorized to perform sqs:DeleteMessage')));

        $trip = $this->check()->roundTrip($this->queue());

        $this->assertFalse($trip['ok']);
        $this->assertStringContainsString('deleting failed: not authorized to perform sqs:DeleteMessage', $trip['message']);
    }

    /** With OWA_TRACKING_URL, a collector requests its own log.php through it. */
    public function testTheTrackingUrlMustAnswerWithLogPhpsImage(): void
    {
        $asked = array();

        ServerCheckCli::$fetch = function ($url) use (&$asked) {
            $asked[] = $url;

            return count($asked) === 1 ? array(200, 'image/gif') : array(403, 'text/html');
        };

        try {
            $ok = $this->check()->trackingUrlCheck('https://t.example.test/log.php');
            $no = $this->check()->trackingUrlCheck('https://t.example.test/log.php');
        } finally {
            ServerCheckCli::$fetch = null;
        }

        $this->assertSame(array('https://t.example.test/log.php', 'https://t.example.test/log.php'), $asked);
        $this->assertTrue($ok['ok']);
        $this->assertSame('ok: https://t.example.test/log.php answers.', $ok['message']);
        $this->assertFalse($no['ok']);
        $this->assertSame('https://t.example.test/log.php answered HTTP 403 (text/html), not log.php\'s image.', $no['message']);
    }

    public function testItRunsOnEveryRoleAndAFailureExitsOne(): void
    {
        $this->assertSame(\OWA\Core\ServerRole::ALL, ServerCheckCli::serverRoles());

        $check = $this->check();
        (new ReflectionMethod($check, 'fail'))->invoke($check, 'Server check failed: see above.');

        $this->assertSame(1, \OWA\Core\Controller\Cli::$exit_code);
    }
}
