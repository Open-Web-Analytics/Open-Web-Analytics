<?php

require_once __DIR__ . '/CliControllerTestCase.php';

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
    protected function setUp(): void
    {
        parent::setUp();
        UpdateGuardProbe::reset();
    }

    private function currentSchemaVersion(): int
    {
        return (int) owa_coreAPI::getSetting('base', 'schema_version');
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
        $src = file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/UpdatesApplyCli.php'
        );

        // Its protection is the SAPI gate in Core\Controller\Cli::__construct
        // (which exits unless request_mode === 'cli'), NOT a capability. If it
        // ever stops extending that base it becomes web-reachable with no gate
        // at all.
        $this->assertMatchesRegularExpression(
            '/class\s+UpdatesApplyCli\s+extends\s+\\\\OWA\\\\Core\\\\Controller\\\\Cli/',
            $src,
            'UpdatesApplyCli must extend Core\\Controller\\Cli -- that base is '
            . 'what confines it to the command line.'
        );
    }

    public function testRollbackIsReachableFromTheCli(): void
    {
        $src = file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/UpdatesApplyCli.php'
        );

        $this->assertStringContainsString(
            "getParam('rollback')",
            $src,
            'The CLI must keep exposing rollback (`cmd=update rollback=base.UpdateNNN`); '
            . 'it is the only rollback interface OWA has.'
        );
    }

    /**
     * `rollback=base.17` has to reach the same class as `rollback=base.Update017`.
     *
     * The CLI's apply= and rollback= arguments are written by a human, who
     * writes the sequence number the update is known by. Module::getUpdates()
     * instead passes the PSR-4 class basename it read off disk. Only the second
     * form resolved, so every targeted apply and rollback fell through to the
     * legacy branch and died looking for owa_base_17_update.php -- a filename
     * that stopped existing when the modules moved to PSR-4.
     *
     * Nothing caught it because the bare `cmd=update` path does not go through
     * here: it applies the updates the module enumerated, already named the way
     * this used to require.
     */
    public function testAnUpdateResolvesByBareSequenceAsWellAsByClassName(): void
    {
        $bySequence = \OWA\Core\CoreAPI::updateFactory('base', '17');
        $byClass    = \OWA\Core\CoreAPI::updateFactory('base', 'Update017');

        $this->assertSame(
            get_class($byClass),
            get_class($bySequence),
            'cmd=update rollback=base.17 must load the update, not a legacy filename'
        );

        $this->assertSame(17, (int) $bySequence->schema_version);
    }

    /**
     * The zero-padding is the part that is easy to get wrong: the classes are
     * UpdateNNN, so a single-digit sequence has to grow leading zeroes.
     */
    public function testASingleDigitSequenceResolvesToItsPaddedClass(): void
    {
        $u = \OWA\Core\CoreAPI::updateFactory('base', '3');

        $this->assertSame('OWA\\Module\\Base\\Update\\Update003', get_class($u));
        $this->assertSame(3, (int) $u->schema_version);
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
        owa_coreAPI::setSetting('base', 'schema_version', $current);
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
     * rollback() must report refusal, not success. It previously returned true
     * unconditionally, so UpdatesApplyCli printed "Rollback completed." even
     * when nothing had run.
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
        $src = file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/UpdatesApplyCli.php'
        );

        $this->assertMatchesRegularExpression(
            '/\$ret\s*=\s*\$u->rollback\(\)\s*;/',
            $src,
            'UpdatesApplyCli::rollback() must capture the return value.'
        );
        $this->assertStringContainsString(
            'did NOT complete',
            $src,
            'UpdatesApplyCli must tell the operator when a rollback did not '
            . 'complete instead of always printing "Rollback completed."'
        );
    }
}
