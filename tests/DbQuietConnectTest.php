<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * canConnect() answers whether a connection can be made without logging a
 * failure.
 *
 * The installer's first page asks whether OWA is already installed. With an
 * owa-config.php whose details do not connect, logging there would put "Could
 * not connect to database." at ALERT on that page, before the reader reaches
 * the environment check, which is where a failed connection is reported.
 * connect() itself logs it.
 */
final class DbQuietConnectTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** A driver pointed at a port nothing listens on, logging into $alerts. */
    private function refused(string $class, array &$alerts): \OWA\Core\Db
    {
        $db = new $class('127.0.0.1', 1, 'owa_none', 'nobody', 'nothing');

        $db->e = new class($alerts) {
            private $alerts;
            public function __construct(array &$alerts) { $this->alerts = &$alerts; }
            public function alert($message) { $this->alerts[] = (string) $message; }
            public function __call($name, $args) { return null; }
        };

        return $db;
    }

    public static function drivers(): array
    {
        return [
            'pdo'    => [\OWA\Core\Db\PdoMysql::class, 'pdo_mysql'],
            'mysqli' => [\OWA\Core\Db\Mysql::class, 'mysqli'],
        ];
    }

    /** @dataProvider drivers */
    public function testCanConnectLogsNothing(string $class, string $extension): void
    {
        if (!extension_loaded($extension)) {
            $this->markTestSkipped($extension . ' is not loaded');
        }

        $alerts = [];

        $warnings = [];

        set_error_handler(function (int $no, string $msg) use (&$warnings) {
            $warnings[] = $msg;
            return true;
        });

        try {
            $this->assertFalse($this->refused($class, $alerts)->canConnect());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $alerts);
        $this->assertSame([], $warnings, 'nor a PHP warning from the driver');
    }

    /** @dataProvider drivers */
    public function testConnectStillLogsTheFailure(string $class, string $extension): void
    {
        if (!extension_loaded($extension)) {
            $this->markTestSkipped($extension . ' is not loaded');
        }

        $alerts = [];

        // mysqli's own warning is left alone here: connect() is unchanged.
        set_error_handler(static function () { return true; }, E_WARNING);

        try {
            $this->assertFalse($this->refused($class, $alerts)->connect());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['Could not connect to database.'], $alerts);
    }

    /**
     * isInstallComplete(), on an owa-config.php whose database does not
     * connect, answers false and logs nothing. In a subprocess, as the
     * database a boot connects to is named by constants.
     */
    public function testTheInstallerAsksQuietly(): void
    {
        $code = <<<'PHP'
$_SERVER['HTTP_USER_AGENT'] = 'OWA quiet connect probe';
// A port nothing listens on. The install's own owa-config.php defining these too warns, and the first value stands.
foreach (['OWA_DB_HOST' => '127.0.0.1', 'OWA_DB_PORT' => '1', 'OWA_DB_NAME' => 'owa_none',
          'OWA_DB_USER' => 'nobody', 'OWA_DB_PASSWORD' => 'nothing'] as $name => $value) {
    define($name, $value);
}
set_error_handler(static function () { return true; });
// Booted as install.php boots, which reads nothing from the database.
require 'owa.php';
define('OWA_INSTALLING', true);
new owa(['request_type' => 'install']);
// As when owa-config.php was read, which CI has none of.
\OWA\Core\CoreAPI::configSingleton()->config_file_loaded = true;
$e      = \OWA\Core\CoreAPI::errorSingleton();
$stream = fopen('php://memory', 'w+');
(new ReflectionProperty($e, 'sinks'))->setValue($e, [['path' => null, 'stream' => $stream]]);
$answer = (new \OWA\Module\Base\Classes\InstallManager())->isInstallComplete();
rewind($stream);
echo 'PROBE ', json_encode(['answer' => $answer, 'log' => stream_get_contents($stream)]), "\n";
PHP;

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        $this->assertIsResource($proc);

        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame(1, preg_match('/^PROBE (.*)$/m', $out, $m), $out);

        $this->assertSame(['answer' => false, 'log' => ''], json_decode($m[1], true));
    }
}
