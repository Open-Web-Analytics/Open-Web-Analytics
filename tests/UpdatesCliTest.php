<?php

require_once __DIR__ . '/CliControllerTestCase.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * Probe update. Extends the real Core\Update so the guard logic under test is
 * the production code, but replaces up()/down() so no schema is ever touched --
 * including on the paths where a guard fails to hold, which is exactly when a
 * naive test would start mutating the developer's database.
 */
final class UpdateGuardProbe extends \OWA\Core\Update
{
    public static bool $upCalled   = false;
    public static bool $downCalled = false;

    /** Simulate a down() that runs but does not finish. */
    public static bool $downShouldFail = false;

    public static function reset(): void
    {
        self::$upCalled       = false;
        self::$downCalled     = false;
        self::$downShouldFail = false;
    }

    function up($force = false)
    {
        self::$upCalled = true;

        return true;
    }

    function down()
    {
        self::$downCalled = true;

        return ! self::$downShouldFail;
    }
}

/** Runs of cmd=update's bare apply-everything path, counted by the test's subclass. */
final class UpdatesCliPendingCount
{
    public static int $runs = 0;
}

/**
 * The CLI update path: `php cli.php cmd=update [listpending|apply=|rollback=]`
 *
 * This path matters more than it looks. base.updatesApply (web) is gated on the
 * edit_modules capability, so if a future update ever breaks authentication
 * itself -- new code querying a column an old schema lacks -- the web route
 * cannot complete. The CLI is the documented escape hatch, and updates
 * 005/006/007/010 already REQUIRE it via isCliModeRequired(). If the CLI
 * command stops being wired up, an operator in that state has no way out.
 *
 * The sequencing guards in Core\Update are tested through a probe subclass:
 * a guard that fails open would otherwise run real migrations against whatever
 * database the suite is pointed at.
 */
final class UpdatesCliTest extends CliControllerTestCase
{
    use CapturesLog;

    protected function setUp(): void
    {
        parent::setUp();
        UpdateGuardProbe::reset();
    }

    private function currentSchemaVersion(): int
    {
        return (int) \OWA\Core\CoreAPI::getSetting('base', 'schema_version');
    }

    private function makeProbe(int $schemaVersion): UpdateGuardProbe
    {
        $probe = new UpdateGuardProbe();
        $probe->module_name    = 'base';
        $probe->schema_version = $schemaVersion;

        return $probe;
    }

    // -----------------------------------------------------------------
    // Command wiring -- the escape hatch has to actually exist
    // -----------------------------------------------------------------

    public function testUpdateCommandIsRegistered(): void
    {
        $this->assertSame(
            'base.updatesApplyCli',
            $this->commandClass('update'),
            'The `update` CLI command is the only way to apply updates when the '
            . 'web path is unavailable. It must stay registered.'
        );
    }

    public function testCliUpdateControllerIsGatedToCliOnly(): void
    {
        // Its protection is the SAPI gate in Core\Controller\Cli::__construct
        // (which exits unless request_type === 'cli'), NOT a capability. If it
        // ever stops extending that base it becomes web-reachable with no gate
        // at all.
        $this->assertTrue(
            is_subclass_of(\OWA\Module\Base\Controller\UpdatesApplyCli::class, \OWA\Core\Controller\Cli::class),
            'UpdatesApplyCli must extend Core\\Controller\\Cli -- that base is '
            . 'what confines it to the command line.'
        );
    }

    /**
     * cmd=update rollback=<module>.<sequence>, run on the probe, and what it
     * logged.
     *
     * @return array{0: \OWA\Module\Base\Controller\UpdatesApplyCli, 1: string}
     */
    private function rollBack(UpdateGuardProbe $probe): array
    {
        return $this->runCli(['rollback' => 'base.' . $probe->schema_version], $probe);
    }

    /**
     * cmd=update with $params, and what it logged. apply= and rollback= reach
     * $probe; the bare command counts its runs in UpdatesCliPendingCount
     * instead of applying the install's pending updates. Built without the
     * constructor's OWA_UPDATING define, which a second construction in one
     * process would repeat.
     *
     * @param array<string, mixed> $params
     * @return array{0: \OWA\Module\Base\Controller\UpdatesApplyCli, 1: string}
     */
    private function runCli(array $params, UpdateGuardProbe $probe): array
    {
        $cli = new class($params, $probe)
            extends \OWA\Module\Base\Controller\UpdatesApplyCli {
            private $probe;

            public function __construct($params, $probe)
            {
                $this->probe = $probe;
                \OWA\Core\Controller\Cli::__construct($params);
            }

            protected function namedUpdate($update, $arg)
            {
                return $this->probe;
            }

            protected function applyPending()
            {
                UpdatesCliPendingCount::$runs++;

                return null;
            }
        };

        $log = $this->logDuring(static function () use ($cli) {
            $cli->action();
        });

        return [$cli, $log];
    }

    /**
     * The arguments, and the probe's sequence relative to the schema: rollback=
     * one ahead runs down() and leaves schema_version alone; apply= --force at
     * the current sequence runs up() and writes back the same schema_version.
     *
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function changingRuns(): array
    {
        return [
            'cmd=update'           => [[], 1],
            'cmd=update apply='    => [['apply' => 'base.next', 'force' => true], 0],
            'cmd=update rollback=' => [['rollback' => 'base.next'], 1],
        ];
    }

    private function heldLease(): \OWA\Module\Base\Classes\JobLease
    {
        $held = new \OWA\Module\Base\Classes\JobLease(\OWA\Module\Base\Controller\UpdatesApply::LOCK);
        $this->assertTrue($held->acquire(60), 'the test could not take the lease first');

        return $held;
    }

    /** Whether the lease is free, left free. */
    private function leaseIsFree(): bool
    {
        $probe = new \OWA\Module\Base\Classes\JobLease(\OWA\Module\Base\Controller\UpdatesApply::LOCK);

        if (! $probe->acquire(60)) {
            return false;
        }

        $probe->release();

        return true;
    }

    /**
     * While base.updatesApply's lease is held, by a web request or another
     * cmd=update, a run that would change the schema applies nothing and exits 1.
     *
     * @dataProvider changingRuns
     * @param array<string, mixed> $params
     * @param int                  $ahead
     */
    public function testARunThatChangesTheSchemaRefusesWhileTheLeaseIsHeld(array $params, int $ahead): void
    {
        UpdatesCliPendingCount::$runs = 0;
        $held = $this->heldLease();

        try {
            list($cli, $log) = $this->runCli($params, $this->makeProbe($this->currentSchemaVersion() + $ahead));
        } finally {
            $held->release();
        }

        $this->assertFalse(UpdateGuardProbe::$upCalled, 'apply= ran while the lease was held');
        $this->assertFalse(UpdateGuardProbe::$downCalled, 'rollback= ran while the lease was held');
        $this->assertSame(0, UpdatesCliPendingCount::$runs, 'cmd=update applied updates while the lease was held');
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_FAILED, \OWA\Core\Controller\Cli::$exit_code);
        $this->assertStringContainsString('Updates are already being applied by another run.', $log);
        $this->assertStringContainsString('job=updates-apply', $log);
    }

    /**
     * Once it finishes, the next run, web or CLI, can take the lease.
     *
     * @dataProvider changingRuns
     * @param array<string, mixed> $params
     * @param int                  $ahead
     */
    public function testARunThatChangesTheSchemaReleasesTheLease(array $params, int $ahead): void
    {
        UpdatesCliPendingCount::$runs = 0;
        $this->assertTrue($this->leaseIsFree(), 'the lease was held before the test');

        $current = $this->currentSchemaVersion();

        list($cli) = $this->runCli($params, $this->makeProbe($current + $ahead));

        $this->assertSame($current, $this->currentSchemaVersion());
        $this->assertTrue(
            UpdateGuardProbe::$upCalled || UpdateGuardProbe::$downCalled || UpdatesCliPendingCount::$runs === 1,
            'the run did not reach the update'
        );
        $this->assertTrue($this->leaseIsFree(), 'cmd=update kept the lease after it finished');
    }

    /** A run that fails part way lets go too. */
    public function testAFailedRollbackReleasesTheLease(): void
    {
        UpdateGuardProbe::$downShouldFail = true;

        list($cli) = $this->rollBack($this->makeProbe($this->currentSchemaVersion() + 1));

        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertTrue($this->leaseIsFree(), 'a failed rollback kept the lease');
    }

    /** listpending changes nothing, so a held lease does not stop it. */
    public function testListPendingRunsWhileTheLeaseIsHeld(): void
    {
        $held = $this->heldLease();

        try {
            list($cli, $log) = $this->runCli(['listpending' => true], $this->makeProbe($this->currentSchemaVersion() + 1));
        } finally {
            $held->release();
        }

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertStringNotContainsString('already being applied', $log);
    }

    /** The only rollback interface OWA has: `cmd=update rollback=base.UpdateNNN`. */
    public function testRollbackIsReachableFromTheCli(): void
    {
        list($cli, $log) = $this->rollBack($this->makeProbe($this->currentSchemaVersion() + 1));

        $this->assertTrue(UpdateGuardProbe::$downCalled, 'rollback= did not reach the update');
        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertStringContainsString('Rollback completed.', $log);
    }

    /**
     * `rollback=base.34` has to reach the same class as `rollback=base.Update034`.
     *
     * The CLI's apply= and rollback= arguments are written by a human, who
     * writes the sequence number the update is known by; Module::getUpdates()
     * passes the PSR-4 class basename it read off disk. Both forms resolve. The
     * bare `cmd=update` path does not go through here, so it cannot catch a form
     * that fails.
     */
    public function testAnUpdateResolvesByBareSequenceAsWellAsByClassName(): void
    {
        $bySequence = \OWA\Core\CoreAPI::updateFactory('base', '34');
        $byClass    = \OWA\Core\CoreAPI::updateFactory('base', 'Update034');

        $this->assertSame(
            get_class($byClass),
            get_class($bySequence),
            'cmd=update rollback=base.34 must load the update, not a legacy filename'
        );

        $this->assertSame(34, (int) $bySequence->schema_version);
    }

    /**
     * The zero-padding is the part that is easy to get wrong: the classes are
     * UpdateNNN, so a single-digit sequence has to grow leading zeroes. Base
     * starts at 34, so Domstream's second update stands in.
     */
    public function testASingleDigitSequenceResolvesToItsPaddedClass(): void
    {
        $u = \OWA\Core\CoreAPI::updateFactory('domstream', '2');

        $this->assertSame('OWA\\Module\\Domstream\\Update\\Update002', get_class($u));
        $this->assertSame(2, (int) $u->schema_version);
    }

    // -----------------------------------------------------------------
    // apply() sequencing guards
    // -----------------------------------------------------------------

    public function testApplyRefusesWhenSchemaVersionIsUnset(): void
    {
        $probe = $this->makeProbe(0);
        $probe->schema_version = null;

        $this->assertFalse(
            $probe->apply(),
            'apply() must refuse when schema_version is unset, or updates '
            . 'silently drift out of sequence.'
        );
        $this->assertFalse(
            UpdateGuardProbe::$upCalled,
            'up() ran despite schema_version being unset.'
        );
    }

    public function testApplyRefusesAnAlreadyAppliedUpdate(): void
    {
        $current = $this->currentSchemaVersion();
        $probe   = $this->makeProbe($current);

        $this->assertFalse(
            $probe->apply(),
            'apply() must refuse an update already recorded as applied.'
        );
        $this->assertFalse(
            UpdateGuardProbe::$upCalled,
            're-applying an already-applied update executed up() -- this is how '
            . 'a schema gets double-migrated.'
        );
    }

    public function testApplyWithForceBypassesTheAlreadyAppliedGuard(): void
    {
        $current = $this->currentSchemaVersion();
        $probe   = $this->makeProbe($current);

        $probe->apply(true);

        $this->assertTrue(
            UpdateGuardProbe::$upCalled,
            '--force must bypass the already-applied guard; that is its purpose.'
        );

        // Restore: apply() persists schema_version on success. Value is
        // unchanged here (we forced the CURRENT version), but be explicit.
        \OWA\Core\CoreAPI::setSetting('base', 'schema_version', $current);
        $this->assertSame($current, $this->currentSchemaVersion());
    }

    // -----------------------------------------------------------------
    // rollback() sequencing guards
    // -----------------------------------------------------------------

    public function testRollbackRefusesAnOutOfSequenceUpdate(): void
    {
        $current = $this->currentSchemaVersion();

        // Far ahead of the installed schema: this update was never applied.
        $probe = $this->makeProbe($current + 5);

        $probe->rollback();

        $this->assertFalse(
            UpdateGuardProbe::$downCalled,
            'down() ran for an update that was never applied. Rolling back out '
            . 'of sequence tears down schema the instance still depends on.'
        );
        $this->assertSame(
            $current,
            $this->currentSchemaVersion(),
            'A refused rollback still moved schema_version.'
        );
    }

    /**
     * rollback() must report refusal, not success, so UpdatesApplyCli does not
     * print "Rollback completed." when nothing ran.
     */
    public function testRollbackReturnsFalseWhenItRefuses(): void
    {
        $current = $this->currentSchemaVersion();
        $probe   = $this->makeProbe($current + 5);

        $this->assertFalse(
            $probe->rollback(),
            'A refused rollback reported success. The CLI relies on this return '
            . 'value to decide what to tell the operator.'
        );
        $this->assertFalse(UpdateGuardProbe::$downCalled);
    }

    /**
     * The success path, exercised WITHOUT mutating anything: when
     * current === schema_version - 1 (rolling back an update that failed part
     * way), rollback() runs down() and reports success but does not move
     * schema_version -- there is nothing to move.
     */
    public function testRollbackReturnsTrueWhenDownSucceeds(): void
    {
        $current = $this->currentSchemaVersion();
        $probe   = $this->makeProbe($current + 1);

        $this->assertTrue(
            $probe->rollback(),
            'A rollback whose down() succeeded must report success.'
        );
        $this->assertTrue(UpdateGuardProbe::$downCalled, 'down() never ran.');
        $this->assertSame(
            $current,
            $this->currentSchemaVersion(),
            'schema_version moved on a branch that should not persist it.'
        );
    }

    /**
     * The dangerous outcome: down() ran and did NOT finish. The schema may be
     * partially torn down, so schema_version must stay put and the caller must
     * be told it failed -- silently reporting success here is how an operator
     * ends up believing a rollback happened that did not.
     */
    public function testRollbackReturnsFalseWhenDownFailsPartWay(): void
    {
        $current = $this->currentSchemaVersion();
        UpdateGuardProbe::$downShouldFail = true;

        $probe = $this->makeProbe($current + 1);

        $this->assertFalse(
            $probe->rollback(),
            'A rollback whose down() failed reported success.'
        );
        $this->assertTrue(UpdateGuardProbe::$downCalled, 'down() never ran.');
        $this->assertSame(
            $current,
            $this->currentSchemaVersion(),
            'schema_version was moved despite down() failing -- the recorded '
            . 'version would then disagree with the actual schema.'
        );
    }

    /**
     * The CLI must branch on that return value rather than announcing success
     * unconditionally.
     */
    public function testCliReportsAFailedRollbackHonestly(): void
    {
        UpdateGuardProbe::$downShouldFail = true;

        list($cli, $log) = $this->rollBack($this->makeProbe($this->currentSchemaVersion() + 1));

        $this->assertTrue(UpdateGuardProbe::$downCalled);
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_FAILED, \OWA\Core\Controller\Cli::$exit_code);
        $this->assertStringContainsString('Rollback did NOT complete', $log);
        $this->assertStringNotContainsString('Rollback completed.', $log);
    }
}
