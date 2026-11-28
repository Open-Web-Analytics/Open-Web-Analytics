<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Controller\Cli;
use OWA\Core\Params;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/tools/wiki/lib/modules.php';

/**
 * Every command reads its arguments through param(), and only the ones its
 * usage() declares, each declared with a type Core\Params knows.
 *
 * Read from the source of the methods the command runs (sourceOf()).
 * Controller::param() also throws at run time for a name the command does
 * not declare.
 */
final class CliArgumentsContractTest extends TestCase
{
    /** Commands whose arguments are declared but still read with getParam(). */
    private const READS_WITH_GETPARAM = [
        'install', 'core-install', 'schedule-run', 'schedule-status', 'server-check', 'tables-cleanup', 'backup',
    ];

    /** Read in code these commands never reach: PartitionsCli's budget checks, and the JobsCli modes other than their own. */
    private const UNREACHED = [
        'partition-status' => ['force'],
        'jobs'             => ['id'],
        'jobs-retry'       => ['status', 'limit'],
        'jobs-forget'      => ['status', 'limit'],
        'jobs-prune'       => ['id', 'status', 'limit'],
    ];

    /** @return array<string, string> command => class, from every shipped module */
    private function commands(): array
    {
        $s        = \OWA\Core\CoreAPI::serviceSingleton();
        $commands = [];

        foreach (owa_wiki_all_modules() as $module) {
            foreach ((array) $module->cli_commands as $command => $action) {
                $commands[$command] = (string) ($s->getMapValue('actions', $action)['class_name'] ?? '');
            }
        }

        $this->assertArrayHasKey('geoip-update-db', $commands);

        return $commands;
    }

    /**
     * The source of every method the command runs: its own, and those it
     * inherits from the Cli classes it extends or takes from a trait, each as
     * the class resolves it, so a method replaced by an override or an
     * `insteadof` is not read. Core\PropertyScopedAccess is not read: the
     * capability check reads the request as it does for every controller.
     */
    private function sourceOf(string $class): string
    {
        $src = '';

        foreach ((new ReflectionClass($class))->getMethods() as $method) {

            $declaring = $method->getDeclaringClass()->getName();

            if (!$method->getFileName() || ($declaring !== $class && !is_subclass_of($declaring, Cli::class))
                    || realpath($method->getFileName()) === realpath(dirname(__DIR__) . '/Core/PropertyScopedAccess.php')) {
                continue;
            }

            $lines = file($method->getFileName());
            $src  .= implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        }

        return $src;
    }

    public function testEveryDeclarationHasAKnownType(): void
    {
        foreach ($this->commands() as $command => $class) {

            $declared = Params::declarations($class::params());

            foreach ($declared as $name => $d) {

                if (isset($d['retired'])) {
                    continue;
                }

                $this->assertIsString($d['description'] ?? null, "cmd=$command $name has no description");
                $this->assertFalse(!empty($d['required']) && array_key_exists('default', $d), "cmd=$command $name is required and has a default");

                if (array_key_exists('default', $d) && $d['default'] !== null) {
                    $this->assertSame([], Params::problems([$d['key'] => $d], [$name => $d['default']]), "cmd=$command $name: its default is not a value it takes");
                }

                if (isset($d['not_before'])) {
                    $this->assertSame('date', $declared[$d['not_before']]['type'] ?? null, "cmd=$command $name follows no date");
                }
            }
        }
    }

    public function testNoCommandReadsAnArgumentAroundParam(): void
    {
        foreach ($this->commands() as $command => $class) {

            if (in_array($command, self::READS_WITH_GETPARAM, true)) {
                continue;
            }

            $src = $this->sourceOf($class);

            preg_match_all('/(?:->(?:getParam|isParam)\(\s*[\'"]|getRequestParam\(|->params\[|\$params\[)[^)\]]*/', $src, $m);

            $this->assertSame([], $m[0], "cmd=$command reads an argument without param()");
        }
    }

    public function testEveryArgumentACommandReadsIsDeclared(): void
    {
        foreach ($this->commands() as $command => $class) {

            $accepted = array_keys(Params::declarations($class::params()));

            preg_match_all('/->(?:param|getParam|isParam|profileInput)\(\s*[\'"]([^\'"]+)[\'"]/', $this->sourceOf($class), $m);

            $this->assertSame([], array_values(array_diff(array_unique($m[1]), $accepted, self::UNREACHED[$command] ?? [])),
                "cmd=$command reads arguments its usage() does not declare");
        }
    }

    /** What cli.php and the scheduler accept is what usage() declares. */
    public function testTheAcceptedArgumentsAreTheDeclaredOnes(): void
    {
        foreach ($this->commands() as $command => $class) {

            $this->assertSame(array_merge(Cli::GLOBAL_ARGUMENTS, array_keys(Params::declarations($class::params()))),
                Cli::acceptedArgumentsFor($class), "cmd=$command");
        }
    }

    /** cmd=install-module is gone; cli.php names its replacement. */
    public function testInstallModuleIsActivate(): void
    {
        $this->assertArrayNotHasKey('install-module', $this->commands());
        $this->assertFalse(class_exists('OWA\\Module\\Base\\Controller\\ModuleInstallCli'));
        $this->assertSame('activate', Cli::RENAMED['install-module']);
    }
}
