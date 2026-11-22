<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An update can stop for the operator -- a choice to make, something to do
 * first -- without that being reported as a failure. Update062 stops to ask
 * how much history to migrate, and cmd=core-update-schema says so rather than "Updates
 * failed", which would read as a broken upgrade.
 */
final class UpdateAwaitingTest extends TestCase
{
    private function update(string $awaiting): \OWA\Core\Update
    {
        $u = new class extends \OWA\Core\Update {
            public $stop_for = '';
            function up($force = false) {
                $this->awaiting = $this->stop_for;
                return false;
            }
        };
        $u->stop_for = $awaiting;
        $u->module_name = 'zz_awaiting_test';
        // The next update of a module with no recorded version, which reads as 1.
        $u->schema_version = 2;

        return $u;
    }

    private function noticesDuring(callable $fn): array
    {
        $e = \OWA\Core\CoreAPI::errorSingleton();
        $p = new ReflectionProperty($e, 'sinks');
        $was = $p->getValue($e);
        $stream = fopen('php://memory', 'w+');
        $p->setValue($e, [['path' => null, 'stream' => $stream]]);
        try {
            $fn();
        } finally {
            $p->setValue($e, $was);
        }
        rewind($stream);

        return explode("\n", (string) stream_get_contents($stream));
    }

    public function testAnUpdateThatAwaitsIsNotReportedAsFailed(): void
    {
        $u = $this->update('a choice of something');

        $log = implode("\n", $this->noticesDuring(function () use ($u) {
            $this->assertFalse($u->apply(), 'it has not run, so it is not applied');
        }));

        $this->assertStringContainsString('Update stopped: it needs a choice of something.', $log);
        $this->assertStringNotContainsString('Update Proceadure Failed', $log);
    }

    public function testAnUpdateThatFailsIsStillReportedAsFailed(): void
    {
        $u = $this->update('');

        $log = implode("\n", $this->noticesDuring(function () use ($u) {
            $this->assertFalse($u->apply());
        }));

        $this->assertStringContainsString('Update Proceadure Failed', $log);
        $this->assertStringNotContainsString('Update stopped', $log);
    }
}
