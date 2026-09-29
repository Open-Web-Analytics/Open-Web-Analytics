<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A log write that fails is dropped, never thrown.
 *
 * At shutdown the CLI's console handler, which writes to STDOUT, could be
 * closed before the cache persisted itself and logged that it was doing so.
 * Monolog throws from a closed resource stream, so a `cmd=update` that had
 * succeeded ended in a fatal and exit code 255.
 */
final class LogWriteFailureTest extends TestCase
{
    public function testWritingThroughAClosedHandlerDoesNotThrow(): void
    {
        if (!class_exists(\Monolog\Logger::class)) {
            $this->markTestSkipped('Monolog is not installed');
        }

        $stream = fopen('php://memory', 'w+');
        $handler = new \Monolog\Handler\StreamHandler($stream);

        $logger = new \Monolog\Logger('errors');
        $logger->pushHandler($handler);

        $error = new \OWA\Module\Base\Classes\Error();
        $error->logger = $logger;

        $attached = new ReflectionProperty($error, 'handlers_attached');
        $attached->setAccessible(true);
        $attached->setValue($error, true);

        $error->logMsg('before', 'notice');
        rewind($stream);
        $this->assertStringContainsString('before', stream_get_contents($stream), 'the handler writes while open');

        $handler->close();

        $error->logMsg('after the handler closed', 'notice');

        $this->addToAssertionCount(1);
    }
}
