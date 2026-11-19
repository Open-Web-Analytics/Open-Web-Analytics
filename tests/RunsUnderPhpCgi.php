<?php

/**
 * Runs a script as a GET request under php-cgi, which prints the response's
 * headers, for code whose effect is a header: the CLI SAPI keeps none.
 * Skips when php-cgi is not installed.
 */
trait RunsUnderPhpCgi
{
    /**
     * @param  string               $script path under the repository root
     * @param  array<string,string> $env    request variables, over a plain GET to owa.example.com
     * @return string the response's header block
     */
    protected function cgiHeaders(string $script, array $env = []): string
    {
        $cgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));

        if ($cgi === '') {
            $this->markTestSkipped('php-cgi is not installed');
        }

        $root = dirname(__DIR__);
        $env += [
            'REQUEST_METHOD'  => 'GET',
            'SCRIPT_FILENAME' => $root . '/' . $script,
            'REDIRECT_STATUS' => '200',
            'HTTP_HOST'       => 'owa.example.com',
            'SERVER_NAME'     => 'owa.example.com',
            'SERVER_PORT'     => isset($env['HTTPS']) ? '443' : '80',
            'REQUEST_URI'     => '/owa/' . basename($script),
            'PATH'            => (string) getenv('PATH'),
        ];

        $proc = proc_open([$cgi, '-d', 'display_errors=0', '-d', 'error_log=/dev/null'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return strstr($out, "\r\n\r\n", true) ?: $out;
    }
}
