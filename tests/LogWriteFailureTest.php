<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A log write that cannot be made is dropped, never thrown.
 *
 * At shutdown the CLI's STDOUT could be closed before the cache persisted
 * itself and logged that it was doing so; the logging library then threw from
 * the closed stream, so a `cmd=update` that had succeeded ended in a fatal and
 * exit code 255. A file that cannot be opened is the same case.
 */
final class LogWriteFailureTest extends TestCase
{
    private function errorWithSinks(array $sinks): \OWA\Module\Base\Classes\Error
    {
        $error = new \OWA\Module\Base\Classes\Error();

        $p = new ReflectionProperty($error, 'sinks');
        $p->setAccessible(true);
        $p->setValue($error, $sinks);

        return $error;
    }

    public function testWritingToAClosedStreamDoesNotThrow(): void
    {
        $stream = fopen('php://memory', 'w+');
        $error  = $this->errorWithSinks([['path' => null, 'stream' => $stream]]);

        $error->logMsg('before', 'notice');
        rewind($stream);
        $this->assertStringContainsString('before', stream_get_contents($stream), 'the stream is written while open');

        fclose($stream);

        $error->logMsg('after the stream closed', 'notice');

        $this->addToAssertionCount(1);
    }

    public function testAFileThatCannotBeOpenedDoesNotThrow(): void
    {
        $error = $this->errorWithSinks([['path' => sys_get_temp_dir() . '/no-such-dir-' . bin2hex(random_bytes(4)) . '/errors.txt', 'stream' => null]]);

        $error->logMsg('nowhere to go', 'error');

        $this->addToAssertionCount(1);
    }
}
