<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA_SQS_QUEUE_URL configures the AWS SQS intake on its own (Core\ModuleActivation):
 * it activates the sqs module without a stored row, makes sqs the queue
 * backend, and gives the region. A collector needs nothing else.
 *
 * SUBPROCESS, because constants are process-global and this runner has
 * booted OWA already (see SettingsConfigConstantTest).
 */
final class SqsNodeConfigTest extends TestCase
{
    private const PROBE = __DIR__ . '/fixtures/sqs_node_probe.php';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the probe boots against the install\'s own config');
        }
    }

    private function probe(string $mode): array
    {
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROBE)
            . ' ' . escapeshellarg($mode) . ' 2>/dev/null');

        foreach (explode("\n", $out) as $line) {
            if (strpos($line, 'PROBE ') === 0) {
                return json_decode(substr($line, 6), true);
            }
        }

        $this->fail("the probe printed nothing:\n" . $out);
    }

    /** @return iterable<string, array{string}> */
    public static function urlModes(): iterable
    {
        yield 'full'      => array('url');
        yield 'collector' => array('collector');
    }

    /** @dataProvider urlModes */
    public function testTheQueueUrlActivatesTheModuleAndMakesItTheBackend(string $mode): void
    {
        $r = $this->probe($mode);

        $this->assertContains('sqs', $r['active']);
        $this->assertTrue($r['sqs_type'], 'the sqs queue type is registered');
        $this->assertSame('OWA_SQS_QUEUE_URL', $r['governed'], 'governed by the constant, as any constant governs its key');
        $this->assertSame('sqs', $r['backend']);
    }

    /** @dataProvider urlModes */
    public function testTheRegionIsReadFromTheQueueUrl(string $mode): void
    {
        $this->assertSame('eu-west-2', $this->probe($mode)['region']);
    }

    public function testOwaSqsRegionOverridesTheUrlsRegion(): void
    {
        $this->assertSame('eu-central-1', $this->probe('region')['region']);
    }

    /** OWA_SQS_QUEUE_URL names the queue, and its dead-letter queue is the same URL with -dlq. */
    public function testTheQueueUrlConstantNamesTheQueue(): void
    {
        $r = $this->probe('url');

        $this->assertSame('my-own-queue', $r['name']);
        $this->assertSame('my-own-queue', $r['derived'], 'what the settings screen shows');
        $this->assertSame('https://sqs.eu-west-2.amazonaws.com/210987654321/my-own-queue', $r['url'],
            'not the credentials\' account: the URL as given');
        $this->assertSame('https://sqs.eu-west-2.amazonaws.com/210987654321/my-own-queue-dlq', $r['dlq_url']);
    }

    /** Temporary credentials: the session token goes with the key and secret. */
    public function testASessionTokenIsPassedWithTheKeyAndSecret(): void
    {
        $r = $this->probe('url');

        $this->assertSame('probe-session-token', $r['token']);
        $this->assertStringContainsString('OWA_SQS_TOKEN', $r['source']);
    }

    /** On a full server the URL alone does not queue beacons; on a collector they are always queued. */
    public function testQueueingFollowsTheRoleNotTheUrl(): void
    {
        $this->assertFalse($this->probe('url')['queued']);
        $this->assertTrue($this->probe('collector')['queued']);
    }

    public function testACollectorBootsWithoutTouchingTheDatabase(): void
    {
        $this->assertFalse($this->probe('collector')['connected']);
        $this->assertTrue($this->probe('url')['connected'], 'a full server reads its stored settings');
    }

    /** Switched on without a URL, the module derives the name, and the URL is built from the credentials' account. */
    public function testWithoutAUrlTheQueueIsDerivedAndTheBackendStaysFile(): void
    {
        $r = $this->probe('derived');

        $this->assertContains('sqs', $r['active']);
        $this->assertSame('', $r['governed']);
        $this->assertSame('file', $r['backend'], 'only the URL makes sqs the backend');
        $this->assertSame('eu-west-2', $r['region']);
        $this->assertSame($r['derived'], $r['name']);
        $this->assertSame('https://sqs.eu-west-2.amazonaws.com/123456789012/' . $r['derived'], $r['url']);
        $this->assertSame('https://sqs.eu-west-2.amazonaws.com/123456789012/' . $r['derived'] . '-dlq', $r['dlq_url']);
        $this->assertNull($r['token'], 'no session token unless one is given');
    }

    public function testWithNoSqsConstantTheBackendIsTheFileQueue(): void
    {
        $r = $this->probe('none');

        $this->assertSame('file', $r['backend']);
        $this->assertSame('', $r['governed']);
    }
}
