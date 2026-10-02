<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A logging node configured only in owa-config.php (PLAN 2.30.4a):
 * OWA_ACTIVE_MODULES makes the sqs module active without a stored row, and
 * with credentials that carry their account id the queue URL is built
 * locally, so a beacon needs no lookup.
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

    private function probe(string $mode = ''): array
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
    public static function modes(): iterable
    {
        yield 'settings from the database' => array('');
        yield 'static config only'         => array('static');
    }

    /** @dataProvider modes */
    public function testTheConstantActivatesTheModuleAndItsQueueType(string $mode): void
    {
        $r = $this->probe($mode);

        $this->assertContains('sqs', $r['active']);
        $this->assertTrue($r['sqs_type'], 'so tracker_ingest_queue_type = sqs resolves');
        $this->assertSame('OWA_ACTIVE_MODULES', $r['governed'], 'governed by the constant, as any constant governs its key');
        $this->assertNotContains('no_such_module', $r['active'], 'a name with no module is skipped, not fatal');
    }

    /** @dataProvider modes */
    public function testTheQueueUrlIsBuiltFromTheRegionTheDerivedNameAndTheAccount(string $mode): void
    {
        $r = $this->probe($mode);

        $this->assertSame('eu-west-2', $r['region']);
        $this->assertSame($r['derived'], $r['name'], 'the derived name: nothing names the queue');
        $this->assertSame('https://sqs.eu-west-2.amazonaws.com/123456789012/' . $r['derived'], $r['url']);
        $this->assertSame('https://sqs.eu-west-2.amazonaws.com/123456789012/' . $r['derived'] . '-dlq', $r['dlq_url']);
    }
}
