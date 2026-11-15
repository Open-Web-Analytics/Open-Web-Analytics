<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * canConnect() answers whether a connection can be made without logging a
 * failure.
 *
 * The installer's first page asks whether OWA is already installed. With an
 * owa-config.php whose details do not connect, connect() logged "Could not
 * connect to database." at ALERT on that page (#1401) -- before the reader had
 * reached the environment check, which is where a failed connection is
 * reported. connect() itself still logs it.
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

    public function testTheInstallerAsksQuietly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/modules/Base/Classes/InstallManager.php');
        $fn  = substr($src, (int) strpos($src, 'function isInstallComplete('));
        $fn  = substr($fn, 0, (int) strpos($fn, "\n    }\n"));

        $this->assertStringContainsString('->canConnect()', $fn);
        $this->assertStringNotContainsString('->connect()', $fn);
    }
}
