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

    public static function reset(): void
    {
        self::$upCalled   = false;
        self::$downCalled = false;
    }

    function up($force = false)
    {
        self::$upCalled = true;

        return true;
    }

    function down()
    {
        self::$downCalled = true;

        return true;
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
     * NOTE: Core\Update::rollback() returns true unconditionally -- including
     * on the refusal branch above -- and UpdatesApplyCli::rollback() ignores
     * the return value and always prints "Rollback completed."
     *
     * So an operator rolling back out of sequence is told it worked when
     * nothing happened. This test pins the CURRENT behaviour so the
     * discrepancy is visible and deliberate rather than assumed; the misleading
     * report is worth fixing separately, and when it is, this expectation
     * should be inverted.
     */
    public function testRollbackReturnValueIsCurrentlyUninformative(): void
    {
        $current = $this->currentSchemaVersion();
        $probe   = $this->makeProbe($current + 5);

        $this->assertTrue(
            $probe->rollback(),
            'If rollback() now reports refusal properly, invert this test and '
            . 'update UpdatesApplyCli::rollback() to stop printing '
            . '"Rollback completed." unconditionally.'
        );
        $this->assertFalse(UpdateGuardProbe::$downCalled);
    }
}
