<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Turning a module on must leave it INSTALLED, and doing it twice must be safe.
 *
 * THE STATE THIS EXISTS TO PREVENT. A module has two things: its tables, and a
 * recorded schema_version. install() creates both; activate() sets is_active and
 * nothing else. The admin UI's "Activate" control has always called install(),
 * but `cmd=activate` called activate() -- so the same word did different things
 * depending on where it was typed, and the CLI one left a module switched on
 * with no tables and no version. Nothing reported it, because
 * Module::getSchemaVersion() defaults an absent version to 1 and every module
 * currently requires 1, so the module read as current. demo's maxmind_geoip has
 * been in exactly that state.
 *
 * WHY RE-RUNNING install() USED TO BE DESTRUCTIVE. createTable() is
 * CREATE TABLE IF NOT EXISTS, so a re-run leaves existing tables untouched --
 * but install() then wrote the REQUIRED schema version anyway. update() selects
 * work with `$seq > $current_schema_version`, so every update between the real
 * version and the required one was skipped, permanently, and isSchemaCurrent()
 * answered true afterwards. Reachable from the UI: deactivate a module, upgrade
 * OWA, activate it again.
 *
 * So install() now records the version only when that is provably right, and
 * these tests pin each branch of that decision.
 */
final class ModuleActivateInstallTest extends TestCase
{
    /** @var mixed */
    private $saved;
    private bool $had = false;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database');
        }

        $this->saved = \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'schema_version');
        $this->had = (bool) $this->saved;
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        // Put the installation back exactly as it was found.
        if ($this->had) {
            \OWA\Core\CoreAPI::persistSetting('maxmind_geoip', 'schema_version', $this->saved);
            \OWA\Core\CoreAPI::configSingleton()->save();
        }
    }

    /**
     * The repair. A module carrying no recorded version, whose required version
     * is 1, has nothing that could be pending -- so recording it is safe, and
     * turning the module on should do it.
     */
    public function testActivatingRepairsAMissingSchemaVersion(): void
    {
        // Reproduce demo: activated but never installed -- is_active present,
        // schema_version absent. Writing false is how a key is removed from the
        // settings blob, so this produces a genuinely absent key rather than a
        // zero, which is the state the repair has to recognise.
        \OWA\Core\CoreAPI::persistSetting('maxmind_geoip', 'schema_version', false);
        \OWA\Core\CoreAPI::configSingleton()->save();

        $this->assertEmpty(
            \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'schema_version'),
            'the precondition must hold: no schema version recorded'
        );

        \OWA\Core\CoreAPI::installModule('maxmind_geoip');

        $this->assertSame(
            1,
            (int) \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'schema_version'),
            'installing a module with no recorded version must record it'
        );
    }

    /**
     * The destructive case. A module that already has a version keeps it, so
     * its pending updates survive a re-install.
     */
    public function testReinstallingDoesNotOverwriteAnExistingSchemaVersion(): void
    {
        \OWA\Core\CoreAPI::persistSetting('maxmind_geoip', 'schema_version', 1);
        \OWA\Core\CoreAPI::configSingleton()->save();

        \OWA\Core\CoreAPI::installModule('maxmind_geoip');

        $this->assertSame(
            1,
            (int) \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'schema_version'),
            'a recorded version must survive a re-install, or pending updates are skipped'
        );
    }

    /**
     * The destructive case, pinned where it can actually fail.
     *
     * maxmind_geoip requires schema 1, so "do not overwrite" cannot be wrong
     * there -- the value written would be the value already present. base is the
     * only module that has ever shipped updates, so it is the only place the
     * difference between "keep 20" and "jump to 27" is observable.
     */
    public function testReinstallingAnOutOfDateModuleLeavesItsUpdatesPending(): void
    {
        $real = \OWA\Core\CoreAPI::getSetting('base', 'schema_version');

        $this->assertGreaterThan(1, (int) $real, 'base must have a real schema version');

        try {
            // Pretend this install is several updates behind.
            \OWA\Core\CoreAPI::persistSetting('base', 'schema_version', 20);
            \OWA\Core\CoreAPI::configSingleton()->save();

            \OWA\Core\CoreAPI::installModule('base');

            $this->assertSame(
                20,
                (int) \OWA\Core\CoreAPI::getSetting('base', 'schema_version'),
                'install() must not advance the schema version of a module whose tables '
                . 'it did not create -- doing so skips every update in between, permanently'
            );

        } finally {
            \OWA\Core\CoreAPI::persistSetting('base', 'schema_version', $real);
            \OWA\Core\CoreAPI::configSingleton()->save();

            $this->assertSame((int) $real,
                (int) \OWA\Core\CoreAPI::getSetting('base', 'schema_version'),
                'the installation must be left exactly as it was found');
        }
    }

    /**
     * install() must be able to tell a fresh table from one that was already
     * there. Without this the decision above cannot be made at all.
     */
    public function testTheDialectCanTellWhetherATableExists(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertTrue(method_exists($db, 'tableExists'),
            'schema introspection belongs in the dialect');

        $e = \OWA\Core\CoreAPI::entityFactory('base.site');

        $this->assertTrue($db->tableExists($e->getTableName()),
            'an installed table must be reported as present');

        $this->assertFalse($db->tableExists('owa_no_such_table_here'),
            'a table that does not exist must not be reported as present');

        $this->assertFalse($db->tableExists("owa_site'; DROP TABLE owa_site; --"),
            'the name is validated before it reaches the query');
    }

    /**
     * The two CLI commands must agree with the admin UI, which has always
     * installed. Asserted against the source: the difference between
     * installModule() and activateModule() is one identifier, and it is the
     * whole defect.
     */
    public function testTheActivateCommandInstalls(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/ModuleActivateCli.php');

        $this->assertStringContainsString('CoreAPI::installModule(', $src,
            'cmd=activate must install, matching the admin UI');

        $this->assertStringNotContainsString('CoreAPI::activateModule(', $src,
            'cmd=activate must not set is_active alone -- that leaves a module with no tables');
    }

    public function testTheInstallModuleCommandIsDeprecatedButStillWorks(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/ModuleInstallCli.php');

        $this->assertMatchesRegularExpression('/deprecated/i', $src,
            'cmd=install-module must announce its deprecation');

        $this->assertStringContainsString('cmd=activate', $src,
            'the deprecation notice must name the command that replaces it');

        $this->assertStringContainsString('CoreAPI::installModule(', $src,
            'it must keep working for the scripts that call it');
    }
}
