<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Params;
use OWA\Core\Controller\Cli;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Declared, typed parameters (Core\Params): how each type reads a value, why
 * one is refused, and how Controller and Controller\Cli act on a refusal.
 * The lookups that read the database (property, profile, user) are in
 * CliExitCodeTest.
 */
final class ParamsTest extends TestCase
{
    private const DECLARED = [
        'months-ahead' => [ 'type' => 'integer', 'min' => 1, 'max' => 24, 'default' => 12 ],
        'interval'     => [ 'type' => 'duration', 'min' => 1, 'default' => 86400 ],
        '--dry-run'    => [ 'type' => 'flag' ],
        'only'         => [ 'type' => 'select', 'options' => [ 'raw', 'cubes' ] ],
        'kind'         => [ 'type' => 'select', 'options' => [ 'web' => 'A website', 'app' => 'An app' ], 'default' => 'web' ],
        'queues'       => [ 'type' => 'string', 'value' => '<name>', 'list' => true ],
        'apply'        => [ 'type' => 'string', 'value' => '<module>.<sequence>', 'pattern' => '/^\w+\.\d+$/', 'pattern_problem' => 'Needs an update, such as base.98.' ],
        'from'         => [ 'type' => 'date' ],
        'to'           => [ 'type' => 'date', 'not_before' => 'from' ],
        'older-than'   => [ 'type' => 'date', 'ago' => true ],
        'email'        => [ 'type' => 'email' ],
        'module'       => [ 'type' => 'module', 'required' => true ],
        'keep'         => [ 'retired' => 'keep= is gone.' ],
    ];

    /** @return array<string, string> */
    private function problems(array $raw): array
    {
        return Params::problems(self::DECLARED, $raw + ['module' => 'base']);
    }

    /** @return array<string, mixed> */
    private function values(array $raw): array
    {
        return Params::values(self::DECLARED, $raw);
    }

    public function testANameIsItsKeyWithoutDashes(): void
    {
        $this->assertSame('dry-run', Params::name('--dry-run'));
        $this->assertSame('months-ahead', Params::name('months-ahead'));
        $this->assertSame(['months-ahead', 'interval', 'dry-run', 'only', 'kind', 'queues', 'apply', 'from', 'to', 'older-than', 'email', 'module', 'keep'],
            array_keys(Params::declarations(self::DECLARED)));
    }

    public function testADeclarationNeedsAKnownType(): void
    {
        $this->expectException(LogicException::class);
        Params::declarations(['x' => ['type' => 'number']]);
    }

    public function testASelectNeedsOptions(): void
    {
        $this->expectException(LogicException::class);
        Params::declarations(['x' => ['type' => 'select']]);
    }

    public function testNothingGivenIsEachDefault(): void
    {
        $this->assertSame([
            'months-ahead' => 12, 'interval' => 86400, 'dry-run' => false, 'only' => null, 'kind' => 'web',
            'queues' => null, 'apply' => null, 'from' => null, 'to' => null, 'older-than' => null,
            'email' => null, 'module' => null,
        ], $this->values([]));
        $this->assertSame([], $this->problems([]));
    }

    public function testAFlagIsOnOrOffAndNothingElse(): void
    {
        foreach ([true, '1', 'true', 'yes', 'YES', 1] as $on) {
            $this->assertTrue($this->values(['dry-run' => $on])['dry-run'], var_export($on, true));
            $this->assertSame([], $this->problems(['dry-run' => $on]));
        }

        foreach ([false, '0', 'false', 'no', 0] as $off) {
            $this->assertFalse($this->values(['dry-run' => $off])['dry-run'], var_export($off, true));
            $this->assertSame([], $this->problems(['dry-run' => $off]));
        }

        $this->assertSame(['dry-run' => '--dry-run=maybe: "maybe" is not an allowed value. Allowed: 1 | true | yes | 0 | false | no'],
            $this->problems(['dry-run' => 'maybe']));
        $this->assertArrayHasKey('dry-run', $this->problems(['dry-run' => '']));
    }

    public function testAnIntegerIsAWholeNumberInItsRange(): void
    {
        $this->assertSame(6, $this->values(['months-ahead' => '6'])['months-ahead']);
        $this->assertSame(6, $this->values(['months-ahead' => 6])['months-ahead']);

        foreach (['abc', '0', '-3', '25', '1.5', '6months'] as $bad) {
            $this->assertSame(['months-ahead' => "months-ahead=$bad: Must be a whole number from 1 to 24."],
                $this->problems(['months-ahead' => $bad]), $bad);
            $this->assertSame(12, $this->values(['months-ahead' => $bad])['months-ahead'], 'the default, never a guess');
        }

        $this->assertSame(['interval' => 'interval=0: Must be a whole number of at least 1.'], $this->problems(['interval' => '0']));
    }

    /** name= and a bare --name give no value, which only a flag can do without. */
    public function testAnArgumentGivenWithoutAValueIsRefused(): void
    {
        $this->assertSame(['months-ahead' => 'months-ahead= needs a value.'], $this->problems(['months-ahead' => '']));
        $this->assertSame(['months-ahead' => '--months-ahead needs a value.'], $this->problems(['months-ahead' => true]));
        $this->assertSame(['apply' => 'apply=: Needs an update, such as base.98.'], $this->problems(['apply' => '']));
        $this->assertSame(['queues' => 'queues=, needs a value.'], $this->problems(['queues' => ',']));
    }

    public function testASelectTakesOnlyItsOptions(): void
    {
        $this->assertSame('cubes', $this->values(['only' => 'cubes'])['only']);
        $this->assertSame('app', $this->values(['kind' => 'app'])['kind'], 'value => label options take the values');
        $this->assertSame(['only' => 'only=Raw: "Raw" is not an allowed value. Allowed: raw | cubes'], $this->problems(['only' => 'Raw']));
        $this->assertSame(['kind' => 'kind=foo: "foo" is not an allowed value. Allowed: web | app'], $this->problems(['kind' => 'foo']));
    }

    public function testAListIsReadAsItsItems(): void
    {
        $this->assertSame(['a', 'b'], $this->values(['queues' => ' a, ,b '])['queues']);
    }

    public function testAPatternSaysWhatItTakes(): void
    {
        $this->assertSame('base.98', $this->values(['apply' => 'base.98'])['apply']);
        $this->assertNull($this->values(['apply' => 'base'])['apply']);
        $this->assertSame(['apply' => 'apply=base: Needs an update, such as base.98.'], $this->problems(['apply' => 'base']));
    }

    public function testADateIsARealYyyymmdd(): void
    {
        $this->assertSame('20240229', $this->values(['from' => '20240229'])['from']);
        $this->assertSame('20240229', $this->values(['from' => 20240229])['from']);

        foreach (['20260229', '20261301', '2026-01-01', 'garbage', '2years'] as $bad) {
            $this->assertSame(['from' => "from=$bad: Not a date. Dates are yyyymmdd."], $this->problems(['from' => $bad]), $bad);
        }
    }

    public function testADateCanBeAPeriodBackFromToday(): void
    {
        $this->assertSame(date('Ymd', strtotime('-2 years', strtotime('today'))), $this->values(['older-than' => '2years'])['older-than']);
        $this->assertSame('20250101', $this->values(['older-than' => '20250101'])['older-than']);
        $this->assertArrayHasKey('older-than', $this->problems(['older-than' => '0days']));
        $this->assertSame(\OWA\Core\Validation\Date::resolve('18m', true), \OWA\Module\Base\Controller\PartitionsCli::resolveCutoff('18m'));
    }

    public function testADateMayNotPrecedeTheOneItFollows(): void
    {
        $this->assertSame([], $this->problems(['from' => '20260101', 'to' => '20260101']));
        $this->assertSame([], $this->problems(['to' => '20250101']), 'alone, to= has nothing to follow');
        $this->assertSame(['to' => 'to=20251231: The start date (20260101) is after the end date (20251231).'],
            $this->problems(['from' => '20260101', 'to' => '20251231']));
    }

    public function testAnEmailAddress(): void
    {
        $this->assertSame([], $this->problems(['email' => 'someone@example.com']));
        $this->assertSame(['email' => 'email=someone: The email address is not valid.'], $this->problems(['email' => 'someone']));
    }

    public function testAModuleIsOneInTheModulesDirectory(): void
    {
        $names = \OWA\Core\Validation\ModuleExists::names();

        $this->assertSame('base', $names[0]);
        $this->assertContains('maxmind_geoip', $names);
        $this->assertSame([], $this->problems(['module' => 'maxmind_geoip']));
        $this->assertSame([], $this->problems(['module' => 'MaxmindGeoip']), 'its directory name');
        $this->assertSame(['module' => 'module=nosuch: There is no module named nosuch. Modules: ' . implode(', ', $names) . '.'],
            $this->problems(['module' => 'nosuch']));
    }

    public function testRequiredMeansGivenWithAValue(): void
    {
        $this->assertSame(['module' => 'module=<name> is required.'], Params::problems(self::DECLARED, []));
        $this->assertSame(['module' => 'module= needs a value.'], Params::problems(self::DECLARED, ['module' => '']));
    }

    public function testARetiredArgumentIsRefusedWithItsMessage(): void
    {
        $this->assertSame(['keep' => 'keep= is gone.'], $this->problems(['keep' => '24']));
        $this->assertArrayNotHasKey('keep', $this->values(['keep' => '24']));
    }

    public function testAnArgumentNotDeclaredIsNotLookedAt(): void
    {
        $this->assertSame([], $this->problems(['cmd' => 'x', 'other' => 'anything']));
    }

    public function testHowEachIsWritten(): void
    {
        $d = Params::declarations(self::DECLARED);

        $this->assertSame('months-ahead=<n>', Params::written($d['months-ahead']));
        $this->assertSame('interval=<seconds>', Params::written($d['interval']));
        $this->assertSame('--dry-run', Params::written($d['dry-run']));
        $this->assertSame('only=<raw|cubes>', Params::written($d['only']));
        $this->assertSame('queues=<name>[,<name>...]', Params::written($d['queues']));
        $this->assertSame('apply=<module>.<sequence>', Params::written($d['apply']));
        $this->assertSame('older-than=<cutoff>', Params::written($d['older-than']));
        $this->assertSame('--file=<path>', Params::written(Params::declarations(['--file' => ['type' => 'string', 'value' => '<path>']])['file']));
    }

    public function testTheUsageErrorNamesTheCommandAndEachProblem(): void
    {
        $this->assertSame('cmd=partition-rotate: months-ahead=abc: Must be a whole number of at least 1. --force=maybe: no. Nothing was done.',
            Cli::usageMessage('partition-rotate', ['months-ahead' => 'months-ahead=abc: Must be a whole number of at least 1.', 'force' => '--force=maybe: no']));
    }

    /** A controller refuses a declared parameter before action(), and reads the rest as their types. */
    public function testAControllerRefusesBeforeItsAction(): void
    {
        $make = static fn (array $params) => new class($params) extends \OWA\Core\Controller {

            public $ran = null;

            public static function params()
            {
                return ['limit' => ['type' => 'integer', 'min' => 1, 'default' => 50], '--all' => ['type' => 'flag']];
            }

            public function action()
            {
                $this->ran = [$this->param('limit'), $this->param('all')];
            }

            public function read(string $name)
            {
                return $this->param($name);
            }
        };

        $ok = $make(['limit' => '5', 'all' => 'no']);
        $ok->doAction();
        $this->assertSame([5, false], $ok->ran);

        $bad  = $make(['limit' => '0']);
        $data = $bad->doAction();
        $this->assertNull($bad->ran);
        $this->assertSame(['limit' => 'limit=0: Must be a whole number of at least 1.'], $data['validation_errors']);

        $this->expectException(LogicException::class);
        $make([])->read('other');
    }

    /** A command's refused argument is a usage error: exit 2, final, one message. */
    public function testACommandsRefusedArgumentIsAUsageError(): void
    {
        $class = get_class(new class([]) extends \OWA\Module\Base\Controller\JobsCli {

            public function __construct($params)
            {
            }

            public function needsCurrentSchema()
            {
                return false;
            }

            public function getRequiredCapability()
            {
                return '';
            }
        });

        $cli = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $cli->params = ['cmd' => 'jobs', 'limit' => '-5', 'status' => 'bogus'];
        Cli::$exit_code = 0;

        try {
            $cli->doAction();

            $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
            $this->assertSame([
                'outcome' => 'failed',
                'message' => 'cmd=jobs: status=bogus: "bogus" is not an allowed value. Allowed: pending | running | failed | done.'
                    . ' limit=-5: Must be a whole number of at least 1. Nothing was done.',
                'final'   => true,
            ], $cli->getCliOutcome());
        } finally {
            Cli::$exit_code = 0;
        }
    }
}
