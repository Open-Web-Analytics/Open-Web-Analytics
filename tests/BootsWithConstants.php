<?php

/**
 * The settings a boot ends with, given owa-config.php constants. In a
 * subprocess, as a constant is read once at boot and cannot be undefined
 * (fixtures/settings_from_constants_probe.php).
 */
trait BootsWithConstants
{
    /**
     * @param array<string, mixed> $constants name => value
     * @param string ...$settings  module.key
     * @return array<string, array{value: mixed, constant: string}> by module.key
     */
    protected function bootWith(array $constants, string ...$settings): array
    {
        $cmd = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/fixtures/role_constants_prepend.php',
            __DIR__ . '/fixtures/settings_from_constants_probe.php'], $settings);
        $env = array_merge(getenv(), ['OWA_PROBE_CONSTANTS' => json_encode($constants)]);

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
        $this->assertIsResource($proc);

        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame(1, preg_match('/^PROBE (.*)$/m', $out, $m), $out);

        return json_decode($m[1], true);
    }
}
