<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * Settings must not persist values that merely restate the code default.
 *
 * WHY THIS EXISTS
 * ---------------
 * A persisted value overrides the code default forever. Storing one that is
 * currently identical to the default looks like a no-op, but it silently pins
 * that value: when the default later changes, the install keeps the old one and
 * stops tracking the code.
 *
 * That is not hypothetical. An old config GUI persisted the WHOLE settings
 * array rather than only changed fields, so installs accumulated dozens of
 * redundant copies -- three real installs carried 26, 21 and 13 persisted keys
 * of which only 2-3 were genuine customisations. One of them, report_wrapper,
 * held 'wrapper_default.tpl' from back when that WAS the default. The
 * .tpl -> .php template migration therefore could not reach it, and every
 * report render died on include('') with "ValueError: Path cannot be empty" --
 * the only diagnostic written to OWA's own log, not the web server's.
 *
 * Update012 repairs installs that already have these. This test guards the
 * behaviour that stops them being created again.
 */
final class SettingsPersistenceTest extends TestCase
{
    /**
     * These tests exercise the SHARED config singleton, and Settings::__destruct()
     * calls save() whenever is_dirty is set -- so without this, mutations here get
     * flushed to the real owa_configuration row at script shutdown, long after any
     * individual test finishes. See the trait for what that cost twice.
     */
    use SettingsSingletonSnapshot;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
    }

    public function testAValueEqualToTheCodeDefaultIsNotPersisted(): void
    {
        $c = $this->settings();

        $default = $c->default_config['base']['report_wrapper'] ?? null;
        $this->assertNotNull($default, 'expected a code default for base.report_wrapper');

        $c->persistSetting('base', 'report_wrapper', $default);

        $this->assertArrayNotHasKey(
            'report_wrapper',
            $c->db_settings['base'] ?? [],
            'A value identical to the code default was persisted. That pins the '
            . 'default forever and breaks silently when the default later changes.'
        );
    }

    public function testAValueDifferingFromTheDefaultIsStillPersisted(): void
    {
        $c = $this->settings();

        $c->persistSetting('base', 'report_wrapper', 'wrapper_public.php');

        $this->assertSame(
            'wrapper_public.php',
            $c->db_settings['base']['report_wrapper'] ?? null,
            'A genuine customisation must still be stored.'
        );
    }

    /**
     * Writing the default over a previously stored copy should clear it, so an
     * install heals itself rather than needing the update to run again.
     */
    public function testPersistingTheDefaultClearsAPreviouslyStoredCopy(): void
    {
        $c = $this->settings();

        $default = $c->default_config['base']['report_wrapper'];

        $c->persistSetting('base', 'report_wrapper', 'wrapper_public.php');
        $this->assertArrayHasKey('report_wrapper', $c->db_settings['base']);

        $c->persistSetting('base', 'report_wrapper', $default);

        $this->assertArrayNotHasKey(
            'report_wrapper',
            $c->db_settings['base'],
            'Re-persisting the default should remove the stored override.'
        );
    }

    public function testPruneRemovesRedundantCopiesButKeepsRealCustomisations(): void
    {
        $c = $this->settings();

        $default = $c->default_config['base']['report_wrapper'];

        // one redundant, one genuine
        $c->db_settings['base']['report_wrapper']      = $default;
        $c->db_settings['base']['query_string_filters'] = 'fbclid';

        $removed = $c->pruneRedundantPersistedSettings();

        $this->assertContains('base.report_wrapper', $removed);
        $this->assertArrayNotHasKey('report_wrapper', $c->db_settings['base']);
        $this->assertSame(
            'fbclid',
            $c->db_settings['base']['query_string_filters'] ?? null,
            'prune must not touch a setting that differs from the default.'
        );
    }

    /**
     * schema_version and install_complete have NO code default, so they can
     * never compare equal and must survive a prune. If they were dropped, get()
     * would return null and the install would look uninstalled.
     */
    public function testStructuralSettingsWithNoDefaultAreNeverPruned(): void
    {
        $c = $this->settings();

        foreach (['schema_version', 'install_complete'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $c->default_config['base'],
                "$key must have no code default, or pruning could drop it"
            );
        }

        $c->db_settings['base']['schema_version']   = 12;
        $c->db_settings['base']['install_complete'] = true;

        $c->pruneRedundantPersistedSettings();

        $this->assertSame(12, $c->db_settings['base']['schema_version'] ?? null);
        $this->assertTrue($c->db_settings['base']['install_complete'] ?? null);
    }

    /**
     * Cross-type equivalence. The settings form submits strings while defaults
     * are typed, so real redundant copies look like '1' vs true or NULL vs ''.
     * A strict test matches none of them and the prune becomes a no-op.
     *
     * @dataProvider equivalentToDefaultProvider
     */
    public function testValuesEquivalentToTheDefaultArePruned($stored, $default, string $why): void
    {
        $c = $this->settings();

        $c->default_config['base']['zz_test_key'] = $default;
        $c->db_settings['base']['zz_test_key']    = $stored;

        $c->pruneRedundantPersistedSettings();

        $this->assertArrayNotHasKey('zz_test_key', $c->db_settings['base'], $why);
    }

    public static function equivalentToDefaultProvider(): array
    {
        return [
            "'1' vs true"   => ['1',  true,  "form-submitted '1' against a true default"],
            "NULL vs false" => [null, false, 'unset checkbox against a false default'],
            "NULL vs ''"    => [null, '',    'empty text field against an empty-string default'],
            "'' vs ''"      => ['',   '',    'identical empty strings'],
        ];
    }

    /**
     * @dataProvider differsFromDefaultProvider
     */
    public function testValuesThatDifferAreKept($stored, $default, string $why): void
    {
        $c = $this->settings();

        $c->default_config['base']['zz_test_key'] = $default;
        $c->db_settings['base']['zz_test_key']    = $stored;

        $c->pruneRedundantPersistedSettings();

        $this->assertSame($stored, $c->db_settings['base']['zz_test_key'] ?? null, $why);
    }

    /**
     * The form's protection must not narrow. isSensitiveSettingKey() is now
     * composed from the two Settings lists; their union has to stay exactly the
     * denylist that was hard-coded before, or a setting silently becomes
     * form-writable again (an RCE primitive, per that method's own docblock).
     */
    public function testFormDenylistStillCoversEveryOriginalKey(): void
    {
        $union = array_merge(
            \OWA\Module\Base\Classes\Settings::configFileOnlySettings()['base'],
            \OWA\Module\Base\Classes\Settings::databaseStateSettings()['base']
        );

        $original = [
            'error_log_file','async_error_log_file','async_log_file','async_log_dir',
            'async_lock_file','report_wrapper','db_type','db_host','db_port','db_name',
            'db_user','db_password','db_class_dir','plugin_dir','module_dir',
            'templates_dir','public_path','configuration_id','schema_version',
            'install_complete','is_active','search_engines.ini','query_strings.ini',
        ];

        foreach ($original as $key) {
            $this->assertArrayHasKey(
                $key,
                $union,
                "base.$key dropped out of the form denylist. Allowing the options "
                . 'form to write it is an RCE primitive.'
            );
        }
    }

    /**
     * The two categories must stay disjoint, and the ones that are real
     * database state must NOT be in the config-file-only list -- dropping
     * schema_version or install_complete makes a working install look
     * uninstalled and re-run every update.
     */
    public function testDatabaseStateKeysAreNotTreatedAsConfigFileOnly(): void
    {
        $cfo = \OWA\Module\Base\Classes\Settings::configFileOnlySettings()['base'];

        foreach (['schema_version', 'install_complete', 'configuration_id', 'is_active'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $cfo,
                "$key is real database state; dropping it on load would break the install."
            );
        }
    }

    /**
     * THE CORE BEHAVIOUR. A config-file-only setting stored in the database is
     * unreachable -- load() merges the DB array OVER the config file so the
     * stored value wins, and the form refuses to rewrite it. It must therefore
     * be dropped on load whatever it holds.
     *
     * This is the real-world shape: async_log_dir naming a previous server's
     * directory, and report_wrapper naming a .tpl file that no longer exists.
     *
     * @dataProvider configFileOnlyValueProvider
     */
    public function testConfigFileOnlySettingsAreStrippedRegardlessOfValue($value): void
    {
        $stripped = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings([
            'base' => [
                'async_log_dir'  => $value,
                'report_wrapper' => $value,
                'error_log_file' => $value,
                // must survive: not config-file-only
                'notice_email'   => 'peter@example.com',
            ],
        ]);

        foreach (['async_log_dir', 'report_wrapper', 'error_log_file'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $stripped['base'],
                "base.$key must never be sourced from the database -- its value is "
                . 'irrelevant, because neither the form nor the config file can '
                . 'override a stored copy.'
            );
        }

        $this->assertSame(
            'peter@example.com',
            $stripped['base']['notice_email'] ?? null,
            'a normal setting must pass through untouched'
        );
    }

    public static function configFileOnlyValueProvider(): array
    {
        return [
            'stale path from another server' => ['/home/padams/gone/owa-data/logs/'],
            'dangling .tpl name'             => ['wrapper_default.tpl'],
            'value equal to the default'     => [''],
            'null'                           => [null],
            'plausible correct value'        => ['/var/www/html/site/owa-data/logs/'],
        ];
    }

    /**
     * The strip must not touch other modules. What they store is their own
     * schema_version and is_active -- dropping fileCache.is_active=false would
     * silently re-enable a module that was deliberately disabled, and dropping
     * a module's schema_version would re-run its updates.
     */
    public function testStripLeavesOtherModulesAlone(): void
    {
        $stripped = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings([
            'base'      => ['async_log_dir' => '/gone/', 'notice_email' => 'a@b.c'],
            'domstream' => ['schema_version' => 1, 'is_active' => true],
            'fileCache' => ['is_active' => false],
            // a module that happens to use a name from the base list
            'hello'     => ['report_wrapper' => 'something.php', 'is_active' => true],
        ]);

        $this->assertArrayNotHasKey('async_log_dir', $stripped['base']);

        $this->assertSame(['schema_version' => 1, 'is_active' => true], $stripped['domstream']);
        $this->assertSame(['is_active' => false], $stripped['fileCache'],
            'a deliberately disabled module must stay disabled');
        $this->assertSame(
            ['report_wrapper' => 'something.php', 'is_active' => true],
            $stripped['hello'],
            'the base list is scoped to base; an identically named key in another '
            . 'module is not covered by it'
        );
    }

    /**
     * The prune is likewise confined to modules whose defaults are known.
     * default_config holds only 'base', so every other module is skipped -- the
     * guard that keeps their schema_version / is_active intact.
     */
    public function testPruneSkipsModulesWithNoKnownDefaults(): void
    {
        $c = $this->settings();

        $this->assertArrayNotHasKey(
            'domstream',
            $c->default_config,
            'default_config is expected to hold only base; if a module now '
            . 'contributes defaults, re-check that pruning it is safe'
        );

        $c->db_settings['domstream'] = ['schema_version' => 1, 'is_active' => true];
        $c->db_settings['fileCache'] = ['is_active' => false];

        $removed = $c->pruneRedundantPersistedSettings();

        foreach ($removed as $entry) {
            $this->assertStringStartsWith(
                'base.',
                $entry,
                "prune touched $entry; it must only ever act on modules whose "
                . 'defaults it actually knows'
            );
        }

        $this->assertSame(['schema_version' => 1, 'is_active' => true], $c->db_settings['domstream']);
        $this->assertSame(['is_active' => false], $c->db_settings['fileCache']);
    }

    public static function differsFromDefaultProvider(): array
    {
        return [
            // A user turning something ON where the default is off.
            "'1' vs false"      => ['1', false, 'an enabled setting must survive a false default'],
            "text vs ''"        => ['peter@example.com', '', 'a real value must survive an empty default'],
            // The one PHP 8 loose-comparison trap left: two numeric strings in
            // different notation are == but are NOT the same stored value.
            "'1e2' vs '100'"    => ['1e2', '100', "numeric strings in different notation must not be collapsed"],
        ];
    }
}
