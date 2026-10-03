<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA's log, written without a logging library: the threshold, the line, and
 * OWA_DEBUG as the one switch.
 */
final class ErrorLogTest extends TestCase
{
    private $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/owa-errorlog-' . bin2hex(random_bytes(4)) . '.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function errorWritingTo(string $path): \OWA\Module\Base\Classes\Error
    {
        $error = new \OWA\Module\Base\Classes\Error();

        $p = new ReflectionProperty($error, 'sinks');
        $p->setAccessible(true);
        $p->setValue($error, [['path' => $path, 'stream' => null]]);

        return $error;
    }

    /**
     * Notice and above are always written; debug and info only under
     * OWA_DEBUG. Checked in whichever mode the suite runs: on CI there is no
     * config and debug is off, here the dev config turns it on.
     */
    public function testTheThresholdIsNoticeUnlessDebugging(): void
    {
        $debug = \OWA\Core\Lib::inDebug();
        $error = $this->errorWritingTo($this->path);

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $priority) {
            $error->logMsg("a $priority line", $priority);
        }

        $log = (string) file_get_contents($this->path);

        $this->assertSame($debug, str_contains($log, 'a debug line'), 'debug is written exactly when debugging');
        $this->assertSame($debug, str_contains($log, 'a info line'));
        foreach (['notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $priority) {
            $this->assertStringContainsString("a $priority line", $log, $priority);
        }

        $error->logMsg('an unknown priority', 'loud');
        $this->assertStringNotContainsString('an unknown priority', (string) file_get_contents($this->path));
    }

    /** The line the log has always had: time, pid, level, message. Multi-line messages keep their lines. */
    public function testEachLineIsTimePidLevelMessage(): void
    {
        $error = $this->errorWritingTo($this->path);
        $error->logMsg("first\nsecond", 'warning');
        $error->logMsg(['k' => 'v'], 'error');

        $lines = explode("\n", rtrim((string) file_get_contents($this->path), "\n"));

        $this->assertMatchesRegularExpression('/^\[\d{2}:\d{2}:\d{2} \d{4}-\d{2}-\d{2}\] \[' . getmypid() . '\] \[WARNING\] first$/', $lines[0]);
        $this->assertSame('second', $lines[1]);
        $this->assertMatchesRegularExpression('/\[ERROR\] \{"k":"v"\}$/', $lines[2], 'an array is one line of JSON');
    }

    /** Lines from separate writers append; neither truncates the other's. */
    public function testWritesAppend(): void
    {
        $this->errorWritingTo($this->path)->logMsg('one', 'notice');
        $this->errorWritingTo($this->path)->logMsg('two', 'notice');

        $log = (string) file_get_contents($this->path);
        $this->assertStringContainsString('one', $log);
        $this->assertStringContainsString('two', $log);
    }

    /**
     * OWA_DEBUG is the one switch. OWA_ERROR_HANDLER, which 1.x read, no longer
     * turns debug on. Each case runs in its own process, as a constant cannot be
     * undefined.
     */
    public function testOnlyOwaDebugTurnsDebugOn(): void
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        $cases = [
            "define('OWA_DEBUG', true);"                     => 'true',
            "define('OWA_DEBUG', false);"                    => 'false',
            "define('OWA_DEBUG', 'yes');"                    => 'false',
            "define('OWA_ERROR_HANDLER', 'development');"    => 'false',
            ''                                               => 'false',
        ];

        foreach ($cases as $define => $expected) {
            $out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r '
                . escapeshellarg("$define require $autoload; echo var_export(\\OWA\\Core\\Lib::inDebug(), true);") . ' 2>/dev/null'));

            $this->assertSame($expected, $out, $define === '' ? 'nothing defined' : $define);
        }
    }

    /** Nothing in OWA's own code loads a logging library any more. */
    public function testNoLoggingLibraryIsUsed(): void
    {
        $code = '';
        foreach (['Core', 'modules'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() === 'php') {
                    $code .= php_strip_whitespace($f->getPathname());
                }
            }
        }

        $this->assertStringNotContainsString('Monolog\\', $code);
        $this->assertStringNotContainsString('Psr\\Log\\', $code);
    }
}
