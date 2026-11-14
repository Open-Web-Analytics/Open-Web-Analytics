<?php

/**
 * What OWA's error log is sent during a callable: the logger's sinks are
 * pointed at memory for its length, so nothing reaches the install's log.
 */
trait CapturesLog
{
    protected function logDuring(callable $fn): string
    {
        $e   = \OWA\Core\CoreAPI::errorSingleton();
        $p   = new ReflectionProperty($e, 'sinks');
        $was = $p->getValue($e);
        $stream = fopen('php://memory', 'w+');
        $p->setValue($e, [['path' => null, 'stream' => $stream]]);

        try {
            $fn();
        } finally {
            $p->setValue($e, $was);
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
