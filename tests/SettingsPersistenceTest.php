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

        $default = $c->default_config['base']['log_robots'] ?? null;
        $this->assertNotNull($default, 'expected a code default for base.log_robots');

        $c->persistSetting('base', 'log_robots', $default);

        $this->assertArrayNotHasKey(
            'log_robots',
            $c->db_settings['base'] ?? [],
            'A value identical to the code default was persisted. That pins the '
            . 'default forever and breaks silently when the default later changes.'
        );
    }

    public function testAValueDifferingFromTheDefaultIsStillPersisted(): void
    {
        $c = $this->settings();

        $c->persistSetting('base', 'log_robots', 'customised');

        $this->assertSame(
            'customised',
            $c->db_settings['base']['log_robots'] ?? null,
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

        $default = $c->default_config['base']['log_robots'];

        $c->persistSetting('base', 'log_robots', 'customised');
        $this->assertArrayHasKey('log_robots', $c->db_settings['base']);

        $c->persistSetting('base', 'log_robots', $default);

        $this->assertArrayNotHasKey(
            'log_robots',
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
            \OWA\Module\Base\Classes\Settings::staticSettings()['base'],
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
     * Real database state must stay READABLE from the store.
     *
     * The old form of this asserted those keys were absent from the
     * config-file-only list, because being on it meant being dropped on load.
     * The mechanism is the declaration now, and the equivalent hazard is being
     * declared STATIC: a static setting is never fetched, so a stored
     * schema_version would be invisible and every update would re-run.
     *
     * configuration_id is deliberately not in this list any more. It named the
     * blob row Update043 retired, so it is vestigial rather than state, and
     * its default of '1' is the whole of it.
     */
    public function testDatabaseStateKeysAreStillReadFromTheStore(): void
    {
        $c = $this->settings();

        $static = \OWA\Module\Base\Classes\Settings::staticSettings()['base'];

        foreach (['schema_version', 'install_complete', 'is_active'] as $key) {

            $this->assertArrayNotHasKey($key, $static,
                "$key is real database state; declaring it static would make the "
                . 'stored value invisible and the install look uninstalled.');

            $this->assertTrue($c->mayPersistInstallWide('base', $key),
                "$key must remain storable: code writes it to record what has "
                . 'happened to this installation.');
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
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes real rows and reloads');
        }

        $c      = $this->settings();
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory('base.setting');
        $table  = $entity->getTableName();

        $keys = ['async_log_dir', 'report_wrapper', 'error_log_file'];

        $before = [];

        foreach ($keys as $key) {
            $before[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);

            $id = $db->prepare((string) $entity->makeId('install', '1', 'base', $key));
            $db->query(sprintf("DELETE FROM %s WHERE id = '%s'", $table, $id));
            $db->query(sprintf(
                "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)"
                . " VALUES ('%s', 'install', '1', 'base', '%s', '%s', 1, '0')",
                $table, $id, $db->prepare($key), $db->prepare(serialize($value))));
        }

        $c->load(1);

        $after = [];
        foreach ($keys as $key) {
            $after[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        }

        $db->query(sprintf("DELETE FROM %s WHERE scope_type = 'install' AND module = 'base'"
            . " AND name IN ('%s')", $table, implode("', '", $keys)));
        $c->load(1);

        foreach ($keys as $key) {
            $this->assertSame($before[$key], $after[$key],
                "base.$key must never be sourced from the database -- its value is "
                . 'irrelevant, because neither the form nor the config file can '
                . 'override a stored copy.');
        }
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
     * Base's declaration says nothing about other modules.
     *
     * What they store is their own schema_version and is_active -- losing
     * fileCache.is_active=false would silently re-enable a module that was
     * deliberately disabled, and losing a module's schema_version would re-run
     * its updates. A key named identically to one of Base's static settings is
     * not covered by Base's declaration.
     */
    public function testBasesDeclarationLeavesOtherModulesAlone(): void
    {
        $c = $this->settings();

        $static = \OWA\Module\Base\Classes\Settings::staticSettings();

        $this->assertArrayHasKey('report_wrapper', $static['base'],
            'base.report_wrapper is static');

        $this->assertArrayNotHasKey('report_wrapper', $static['hello'] ?? [],
            'an identically named key in another module is not covered by Base');

        foreach (['domstream' => 'is_active', 'fileCache' => 'is_active'] as $module => $key) {

            $this->assertTrue($c->mayPersistInstallWide($module, $key),
                "$module.$key must stay storable, or a disabled module cannot "
                . 'record that it is disabled');
        }
    }


    /**
     * The prune is confined to modules whose defaults are known.
     *
     * That used to mean base alone, because default_config held nothing else.
     * A module that ships settings.php now contributes its defaults too, and
     * pruning those is correct -- a stored value equal to its declared default
     * is exactly what the rule exists to drop.
     *
     * Two things must still hold, and they are what this asserts. A module that
     * has declared NOTHING is untouched, as before. And is_active /
     * schema_version are never pruned for anyone, declared or not: they carry
     * database state rather than configuration, and core registers them with
     * eagerness but no default precisely so they can never look like a value
     * restating one. Dropping them makes a module look uninstalled and re-run
     * its updates.
     */
    public function testPruneSkipsModulesWithNoKnownDefaults(): void
    {
        $c = $this->settings();

        $this->assertArrayNotHasKey(
            'domstream',
            $c->default_config,
            'domstream declares nothing, so its defaults must stay unknown'
        );

        $c->db_settings['domstream']     = ['schema_version' => 1, 'is_active' => true];
        $c->db_settings['fileCache']     = ['is_active' => false];
        $c->db_settings['maxmind_geoip'] = ['schema_version' => 1, 'is_active' => false];

        $removed = $c->pruneRedundantPersistedSettings();

        foreach ($removed as $entry) {
            $this->assertStringEndsNotWith('.is_active', $entry,
                "prune removed $entry; is_active is database state and must never be dropped");
            $this->assertStringEndsNotWith('.schema_version', $entry,
                "prune removed $entry; schema_version is database state and must never be dropped");
        }

        $this->assertSame(['schema_version' => 1, 'is_active' => true], $c->db_settings['domstream'],
            'a module that declares nothing is left alone entirely');
        $this->assertSame(['is_active' => false], $c->db_settings['fileCache']);
        $this->assertSame(['schema_version' => 1, 'is_active' => false], $c->db_settings['maxmind_geoip'],
            'and a module that DOES declare still keeps its bootstrap state');
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

    /**
     * Writing '' is not a removal, and there has to be something that is.
     *
     * persistSetting('') stores an empty string -- a row that overrides the
     * code default with emptiness, forever. SettingsShutdownSaveTest used it as
     * a cleanup and left `base.owa_settings_shutdown_probe` behind in the
     * config of every install it ever ran against, this box included, since
     * long before settings became rows. Nobody noticed because an empty string
     * reads like an absent one until you look at the table.
     */
    /**
     * And the setting these three USED to demonstrate cannot be persisted.
     *
     * They were written around base.report_wrapper, which is convenient -- a
     * code default and a documented history of being pinned -- and is also
     * config-file-only: a stored value there is an arbitrary file include.
     * Base declares all 21 of those static now, so persistSetting refuses
     * them, and the pruning rule is shown on a setting that can legitimately
     * hold a stored value.
     */
    public function testAConfigFileOnlySettingCannotBePersistedAtAll(): void
    {
        $c = $this->settings();

        foreach ( array_keys(
            (array) ( \OWA\Module\Base\Classes\Settings::staticSettings()['base'] ?? array() ) )
            as $key ) {

            $this->assertFalse( $c->mayPersistInstallWide( 'base', $key ),
                sprintf( 'base.%s must never be storable: it is config-file-only, and for '
                       . 'report_wrapper and error_log_file a stored value is an RCE '
                       . 'primitive', $key ) );
        }
    }

    public function testWritingAnEmptyStringStoresItRatherThanRemovingTheKey(): void
    {
        $c = $this->settings();

        $c->persistSetting('base', 'zz_removal_probe', '');

        $this->assertArrayHasKey('zz_removal_probe', $c->db_settings['base'],
            "'' is a value: persistSetting stores it, which is why it cannot express a removal");
        $this->assertSame('', $c->db_settings['base']['zz_removal_probe']);
    }

    /** removeSetting() is the one that removes it. */
    public function testRemoveSettingDropsTheStoredValue(): void
    {
        $c = $this->settings();

        $c->persistSetting('base', 'zz_removal_probe', 'stored');

        $this->assertSame('stored', $c->get('base', 'zz_removal_probe'));

        $c->removeSetting('base', 'zz_removal_probe');

        $this->assertArrayNotHasKey('zz_removal_probe', $c->db_settings['base'] ?? [],
            'the key is gone from what save() writes back, so the row is pruned');
        $this->assertFalse($c->get('base', 'zz_removal_probe'),
            'and the live array no longer answers with the value just removed');
    }

    /** A key WITH a code default goes back to that default, not to nothing. */
    public function testRemoveSettingFallsBackToTheCodeDefault(): void
    {
        $c = $this->settings();

        $default = $c->default_config['base']['log_robots'] ?? null;
        $this->assertNotNull($default, 'expected a code default for base.log_robots');

        $c->persistSetting('base', 'log_robots', 'not-the-default');
        $this->assertSame('not-the-default', $c->get('base', 'log_robots'));

        $c->removeSetting('base', 'log_robots');

        $this->assertSame($default, $c->get('base', 'log_robots'),
            'removing a stored value restores the default, it does not blank the setting');
    }
}
