<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\CliWithoutBoot;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Commands that run before OWA boots: Controller\Cli::needsBoot() false,
 * found by Core\CliWithoutBoot from the module config files alone, and run
 * by cli.php before `new owa()`.
 */
final class CliWithoutBootTest extends TestCase
{
    private const WITHOUT_BOOT = [
        'version-info'     => \OWA\Module\Base\Controller\VersionInfoCli::class,
        'core-update-install' => \OWA\Module\Base\Controller\CoreUpdateInstallCli::class,
    ];

    public function testTheTwoCommandsResolveFromTheFilesAlone(): void
    {
        foreach (self::WITHOUT_BOOT as $command => $class) {
            $this->assertSame($class, CliWithoutBoot::commandClass($command));
        }

        $this->assertNull(CliWithoutBoot::commandClass('core-update-schema'), 'a command that boots');
        $this->assertNull(CliWithoutBoot::commandClass('no-such-command'), 'boot says it does not exist');
    }

    /** Every registered command boots but those, and boot's registry agrees with the files. */
    public function testEveryOtherCommandBoots(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->loadCliCommands();

        $commands = (array) $s->getMap('cli_commands');
        $this->assertNotEmpty($commands);

        $without = [];

        foreach ($commands as $command => $action) {
            $map   = $s->getMapValue('actions', $action);
            $class = is_array($map) ? (string) ($map['class_name'] ?? '') : '';

            if ($class !== '' && is_subclass_of($class, \OWA\Core\Controller\Cli::class) && !$class::needsBoot()) {
                $without[$command] = ltrim($class, '\\');
            }
        }

        ksort($without);
        $expected = self::WITHOUT_BOOT;
        ksort($expected);

        $this->assertSame($expected, $without);
    }

    public function testBaseComesFirst(): void
    {
        $this->assertSame('Base', CliWithoutBoot::moduleDirs()[0]);
    }

    /**
     * cli.php runs it without booting OWA: no settings, no database. What the
     * build relies on in a checkout with no owa-config.php. Where there is
     * one, the hand-off before it (updater.php) includes it, to find the data
     * directory; nothing boots.
     */
    public function testCliRunsItWithoutBooting(): void
    {
        $root   = \OWA\Core\Env::root();
        $script = 'register_shutdown_function(function () { fwrite(STDERR, class_exists(\'OWA\\Module\\Base\\Classes\\Settings\', false) ? "BOOTED" : "NOT BOOTED"); });'
            . ' $_SERVER["argv"] = ["cli.php", "cmd=version-info"]; $argv = $_SERVER["argv"]; $argc = 2;'
            . ' require ' . var_export($root . '/cli.php', true) . ';';

        $proc = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $out  = stream_get_contents($pipes[1]);
        $err  = stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, $err);
        $this->assertSame('NOT BOOTED', trim($err));
        $this->assertSame(\OWA\Core\VersionInfo::toArray(), json_decode((string) $out, true));
    }

    /** An argument the command does not declare is refused before anything runs. */
    public function testAnUndeclaredArgumentIsRefused(): void
    {
        $root = \OWA\Core\Env::root();
        $proc = proc_open([PHP_BINARY, $root . '/cli.php', 'cmd=version-info', 'bogus=1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $out  = stream_get_contents($pipes[1]);
        $err  = stream_get_contents($pipes[2]);

        $this->assertSame(2, proc_close($proc));
        $this->assertSame('', $out);
        $this->assertStringContainsString('cmd=version-info does not take bogus', $err);
    }
}
