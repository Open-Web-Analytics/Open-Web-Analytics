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
 * cmd=server-check, per role: each role runs only the checks it uses
 * (ServerCheckCli::checks()). SQS is Aws\MockHandler and the HTTP requests
 * are ServerCheckCli::$fetch: nothing here reaches AWS or the network. The
 * database and scheduler checks are stubbed. The report run through cli.php
 * is ServerRoleBootTest's.
 */
final class ServerCheckCliTest extends TestCase
{
    private const URL = 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-server-check-test';

    private MockHandler $mock;

    /** @var array<int, array{0:string,1:array}> */
    private array $sent = [];

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
        ServerCheckCli::$fetch = null;
        \OWA\Core\Controller\Cli::$exit_code = 0;

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->savedType);
        \OWA\Core\CoreAPI::setSetting('sqs', 'region', $this->savedRegion);
    }

    /** Queue SDK answers: an array, a Throwable, or a callable given the command. */
    private function answers(...$results): void
    {
        foreach ($results as $r) {
            $this->mock->append(function (CommandInterface $cmd) use ($r) {
                $this->sent[] = [$cmd->getName(), $cmd->toArray()];

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
        $q = new SqsQueue(['name' => 'owa-server-check-test']);
        (new ReflectionProperty($q, 'url'))->setValue($q, self::URL);

        return $q;
    }

    private function check(): ServerCheckCli
    {
        return new ServerCheckCli([]);
    }

    /** A check whose queue is the mocked one and whose database and scheduler checks answer ok. */
    private function stubbed(): ServerCheckCli
    {
        $queue = $this->queue();

        return new class([], $queue) extends ServerCheckCli {
            private $q;
            public function __construct($params, $q) { $this->q = $q; parent::__construct($params); }
            protected function queue() { return $this->q; }
            protected function databaseCheck() { return ['ok' => true, 'message' => 'ok: connected.']; }
            protected function schedulerCheck() { return ['ok' => true, 'message' => 'ok: cron is running schedule-run.']; }
        };
    }

    /** The constants a role with a database needs. */
    private static function databaseConstants(): array
    {
        return array_fill_keys(array_merge(\OWA\Core\Requirements::DATABASE_CONSTANTS, \OWA\Core\Requirements::URL_CONSTANTS), 'x');
    }

    /** A REST envelope as the REST API renders it, for a 401 with this message. */
    private static function restRefusal(string $msg): string
    {
        $type = \OWA\Core\CoreAPI::getSetting('base', 'request_type');
        $code = http_response_code() ?: 200;
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);

        try {
            http_response_code(401);
            ob_start();
            $returned = \OWA\Core\CoreAPI::displayView(['view' => 'base.restApi',
                'error_msg' => ['headline' => 'Not authenticated.', 'msg' => $msg]]);
            $captured = ob_get_clean();
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'request_type', $type);
            http_response_code($code);
        }

        return ($returned !== null && $returned !== '') ? (string) $returned : (string) $captured;
    }

    /**
     * The REST API's answers, recorded: without credentials, the general 401;
     * with an Authorization header, $withHeader's message.
     */
    private function restAnswers(array &$asked, ?string $withHeader = null, int $status = 401): void
    {
        $withHeader = $withHeader ?? \OWA\Core\Controller::refusedAppPasswordMessage();

        ServerCheckCli::$fetch = function ($url, $headers) use (&$asked, $withHeader, $status) {
            $asked[] = [$url, $headers];

            return [$status, 'application/json', self::restRefusal($headers
                ? $withHeader : \OWA\Core\Controller::notAuthenticatedMessage())];
        };
    }

    private static function labels(array $lines): array
    {
        return array_values(array_filter(array_map(static fn ($l) => preg_match('/^([A-Z][^:]+):/', $l, $m) ? $m[1] : null, $lines)));
    }

    /** A collector sends one test message, and makes no other queue call: a send-only IAM policy passes. */
    public function testACollectorSendsOneTestMessageAndNothingElse(): void
    {
        $this->answers(['MessageId' => 'm-1']);

        list($lines, $failed) = $this->stubbed()->report(new \OWA\Core\ServerRole('collector'), [],
            ['sqs' => 'OWA_SQS_QUEUE_URL']);

        $this->assertFalse($failed, implode("\n", $lines));
        $this->assertSame(['SendMessage'], array_column($this->sent, 0));
        $this->assertSame([
            'Server role:      collector',
            'Serves:           tracking, cli',
            'Configuration:    ok',
            'Queue backend:    sqs (OWA_SQS_QUEUE_URL)',
            'Queue send:       ok: sent a test message, which the drain deletes.',
        ], $lines);
    }

    /** The test message is not a tracker envelope, and the drain deletes it rather than dead-lettering it. */
    public function testTheDrainDeletesTheTestMessage(): void
    {
        $this->answers(['MessageId' => 'm-1']);
        $this->check()->sendCheck($this->queue());

        $envelope = json_decode((string) $this->body, true);

        $this->assertSame(\OWA\Module\Base\Classes\TrackerIngest::CHECK_TYPE, $envelope['type']);
        $this->assertNull(\OWA\Module\Base\Classes\TrackerIngest::event($envelope));

        $this->answers([]);   // DeleteMessage
        $outcome = \OWA\Module\Base\Classes\TrackerIngest::ingestMessage($this->queue(),
            new \OWA\Core\IntakeMessage('r-check', $envelope, 1, (string) $this->body));

        $this->assertSame('discarded', $outcome);
        $this->assertSame(['SendMessage', 'DeleteMessage'], array_column($this->sent, 0));
        $this->assertSame('r-check', $this->sent[1][1]['ReceiptHandle']);
    }

    public function testASendRefusalIsReported(): void
    {
        $this->answers(new SqsException('denied', new \Aws\Command('SendMessage'),
            ['code' => 'InvalidClientTokenId', 'message' => 'The security token included in the request is invalid.']));

        $sent = $this->check()->sendCheck($this->queue());

        $this->assertFalse($sent['ok']);
        $this->assertSame('failed to send a test message: The security token included in the request is invalid.', $sent['message']);
    }

    /**
     * A full server with SQS reads the queue's attributes once, never
     * receiving, and runs the database, scheduler and REST checks.
     */
    public function testAFullServerReadsTheQueueAttributesOnly(): void
    {
        $this->answers(['Attributes' => [
            'ApproximateNumberOfMessages'           => '7',
            'ApproximateNumberOfMessagesNotVisible' => '2',
            'ApproximateNumberOfMessagesDelayed'    => '1',
            'RedrivePolicy' => json_encode(['deadLetterTargetArn' => 'arn:aws:sqs:us-east-1:123456789012:owa-server-check-test-dlq',
                'maxReceiveCount' => 6]),
        ]]);
        $asked = [];
        $this->restAnswers($asked);

        list($lines, $failed) = $this->stubbed()->report(new \OWA\Core\ServerRole('full'), self::databaseConstants(),
            ['sqs' => 'OWA_SQS_QUEUE_URL']);

        $this->assertFalse($failed, implode("\n", $lines));
        $this->assertSame(['GetQueueAttributes'], array_column($this->sent, 0));
        $this->assertSame(['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible',
            'ApproximateNumberOfMessagesDelayed', 'RedrivePolicy'], $this->sent[0][1]['AttributeNames']);
        $this->assertSame(['Server role', 'Serves', 'Configuration', 'Database', 'Scheduler', 'Queue backend', 'Queue', 'REST API'],
            self::labels($lines));
        $this->assertContains('Queue:            ok: about 10 messages waiting; a message goes to owa-server-check-test-dlq after 6 receives.', $lines);
        $this->assertCount(2, $asked, 'the two REST requests');
    }

    /** A full server on the file queue asks no queue anything. */
    public function testAFullServerOnTheFileQueueMakesNoQueueCall(): void
    {
        $asked = [];
        $this->restAnswers($asked);

        list($lines, $failed) = $this->stubbed()->report(new \OWA\Core\ServerRole('full'), self::databaseConstants(), []);

        $this->assertFalse($failed, implode("\n", $lines));
        $this->assertSame([], $this->sent);
        $this->assertSame(['Server role', 'Serves', 'Configuration', 'Database', 'Scheduler', 'Queue backend', 'REST API'],
            self::labels($lines));
        $this->assertContains('Queue backend:    file', $lines);
    }

    public function testAQueueWithoutADeadLetterQueueFails(): void
    {
        $this->answers(['Attributes' => ['ApproximateNumberOfMessages' => '1', 'ApproximateNumberOfMessagesNotVisible' => '0',
            'ApproximateNumberOfMessagesDelayed' => '0']]);

        $q = $this->check()->queueCheck($this->queue());

        $this->assertFalse($q['ok']);
        $this->assertSame('about 1 message waiting, and no dead-letter queue: the queue has no redrive policy. cmd=tracker-ingest-provision sets one.',
            $q['message']);
    }

    public function testAnAttributesRefusalIsReported(): void
    {
        $this->answers(new SqsException('denied', new \Aws\Command('GetQueueAttributes'),
            ['code' => 'AccessDenied', 'message' => 'not authorized to perform sqs:GetQueueAttributes']));

        $q = $this->check()->queueCheck($this->queue());

        $this->assertFalse($q['ok']);
        $this->assertSame('reading the queue\'s attributes failed: not authorized to perform sqs:GetQueueAttributes', $q['message']);
    }

    /** An api server checks the database and the REST API, and nothing else. */
    public function testAnApiServerChecksTheDatabaseAndTheRestApi(): void
    {
        $asked = [];
        $this->restAnswers($asked);

        list($lines, $failed) = $this->stubbed()->report(new \OWA\Core\ServerRole('api'), self::databaseConstants(),
            ['sqs' => 'OWA_SQS_QUEUE_URL']);

        $this->assertFalse($failed, implode("\n", $lines));
        $this->assertSame([], $this->sent, 'no queue call');
        $this->assertSame(['Server role', 'Serves', 'Configuration', 'Database', 'REST API'], self::labels($lines));
    }

    /**
     * The REST check asks a route twice, without credentials and with an
     * invalid Bearer token, and expects a different 401 for each.
     */
    public function testTheRestCheckExpectsTheRefusalForAnInvalidToken(): void
    {
        $asked = [];
        $this->restAnswers($asked);

        $rest = $this->check()->restCheck('https://a.example.test/api/index.php');

        $this->assertTrue($rest['ok'], $rest['message']);
        $this->assertSame([
            ['https://a.example.test/api/index.php?owa_rest_params=base/v1/sites', []],
            ['https://a.example.test/api/index.php?owa_rest_params=base/v1/sites', ['Authorization: Bearer x']],
        ], $asked);
        $this->assertSame('ok: https://a.example.test/api/index.php?owa_rest_params=base/v1/sites refuses a request without credentials, and one with an invalid application password as such.',
            $rest['message']);
    }

    /** A web server that drops the Authorization header answers both requests alike, and the check says so. */
    public function testTheRestCheckNamesADroppedAuthorizationHeader(): void
    {
        $asked = [];
        $this->restAnswers($asked, \OWA\Core\Controller::notAuthenticatedMessage());

        $rest = $this->check()->restCheck('https://a.example.test/api/index.php');

        $this->assertFalse($rest['ok']);
        $this->assertStringContainsString('the web server does not pass the header to PHP', $rest['message']);
    }

    public function testTheRestCheckFailsWhenTheApiDoesNotAnswer401(): void
    {
        $asked = [];
        $this->restAnswers($asked, null, 404);

        $rest = $this->check()->restCheck('https://a.example.test/api/index.php');

        $this->assertFalse($rest['ok']);
        $this->assertSame('https://a.example.test/api/index.php?owa_rest_params=base/v1/sites answered HTTP 404 without credentials, not the REST API\'s 401.',
            $rest['message']);
        $this->assertCount(1, $asked);
    }

    /** With OWA_TRACKING_URL, a collector requests its own log.php through it. */
    public function testTheTrackingUrlMustAnswerWithLogPhpsImage(): void
    {
        $asked = [];

        ServerCheckCli::$fetch = function ($url) use (&$asked) {
            $asked[] = $url;

            return count($asked) === 1 ? [200, 'image/gif', ''] : [403, 'text/html', ''];
        };

        $ok = $this->check()->trackingUrlCheck('https://t.example.test/log.php');
        $no = $this->check()->trackingUrlCheck('https://t.example.test/log.php');

        $this->assertSame(['https://t.example.test/log.php', 'https://t.example.test/log.php'], $asked);
        $this->assertTrue($ok['ok']);
        $this->assertSame('ok: https://t.example.test/log.php answers.', $ok['message']);
        $this->assertFalse($no['ok']);
        $this->assertSame('https://t.example.test/log.php answered HTTP 403 (text/html), not log.php\'s image.', $no['message']);
    }

    /** Missing configuration stops the checks: none runs. */
    public function testMissingConfigurationRunsNoCheck(): void
    {
        list($lines, $failed) = $this->stubbed()->report(new \OWA\Core\ServerRole('api'), [], []);

        $this->assertTrue($failed);
        $this->assertSame(['Server role', 'Serves', 'Configuration'], self::labels($lines));
        $this->assertSame('Configuration:    incomplete', $lines[2]);
    }

    public function testItRunsOnEveryRoleAndAFailureExitsOne(): void
    {
        $this->assertSame(\OWA\Core\ServerRole::ALL, ServerCheckCli::serverRoles());

        $check = $this->check();
        (new ReflectionMethod($check, 'fail'))->invoke($check, 'Server check failed: see above.');

        $this->assertSame(1, \OWA\Core\Controller\Cli::$exit_code);
    }
}
