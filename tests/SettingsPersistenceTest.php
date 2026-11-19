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
 * stops tracking the code. A form that persists the WHOLE settings array
 * rather than only changed fields would store dozens of these.
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

        $default = $c->default_config['base']['spam_detection'] ?? null;
        $this->assertNotNull($default, 'expected a code default for base.spam_detection');

        $c->persistSetting('base', 'spam_detection', $default);

        $this->assertArrayNotHasKey(
            'spam_detection',
            $c->db_settings['base'] ?? [],
            'A value identical to the code default was persisted. That pins the '
            . 'default forever and breaks silently when the default later changes.'
        );
    }

    public function testAValueDifferingFromTheDefaultIsStillPersisted(): void
    {
        $c = $this->settings();

        $c->persistSetting('base', 'spam_detection', 'customised');

        $this->assertSame(
            'customised',
            $c->db_settings['base']['spam_detection'] ?? null,
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

        $default = $c->default_config['base']['spam_detection'];

        $c->persistSetting('base', 'spam_detection', 'customised');
        $this->assertArrayHasKey('spam_detection', $c->db_settings['base']);

        $c->persistSetting('base', 'spam_detection', $default);

        $this->assertArrayNotHasKey(
            'spam_detection',
            $c->db_settings['base'],
            'Re-persisting the default should remove the stored override.'
        );
    }

    /**
     * Cross-type equivalence, the test persistSetting() drops a value by. The
     * settings form submits strings while defaults are typed, so a value
     * restating the default looks like '1' vs true or NULL vs ''.
     *
     * @dataProvider equivalentToDefaultProvider
     */
    public function testValuesEquivalentToTheDefaultAreNotStored($stored, $default, string $why): void
    {
        $this->assertTrue($this->isEquivalentToDefault($stored, $default), $why);
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
        $this->assertFalse($this->isEquivalentToDefault($stored, $default), $why);
    }

    private function isEquivalentToDefault($stored, $default): bool
    {
        return (new ReflectionMethod(\OWA\Module\Base\Classes\Settings::class, 'isEquivalentToDefault'))
            ->invoke(null, $stored, $default);
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
            'error_log_file','async_log_dir','data_dir',
            'report_wrapper','db_type','db_host','db_port','db_name',
            'db_user','db_password',
            'public_path','configuration_id','schema_version',
            'install_complete','is_active',
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
     * The hazard is being declared STATIC: a static setting is never fetched, so
     * a stored schema_version would be invisible and every update would re-run.
     *
     * configuration_id is deliberately not in this list. It named the blob row
     * Update043 retired, so it is vestigial rather than state, and its default of
     * '1' is the whole of it.
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
     * directory, and report_wrapper naming a file that does not exist.
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
            'stale path from another server' => ['/home/olduser/gone/owa-data/logs/'],
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
     * sqs.is_active=false would silently re-enable a module that was
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

        $this->assertArrayNotHasKey('report_wrapper', $static['test_fixture'] ?? [],
            'an identically named key in another module is not covered by Base');

        foreach (['domstream' => 'is_active', 'sqs' => 'is_active'] as $module => $key) {

            $this->assertTrue($c->mayPersistInstallWide($module, $key),
                "$module.$key must stay storable, or a disabled module cannot "
                . 'record that it is disabled');
        }
    }


    public static function differsFromDefaultProvider(): array
    {
        return [
            // A user turning something ON where the default is off.
            "'1' vs false"      => ['1', false, 'an enabled setting must survive a false default'],
            "text vs ''"        => ['alice@example.com', '', 'a real value must survive an empty default'],
            // The one PHP 8 loose-comparison trap left: two numeric strings in
            // different notation are == but are NOT the same stored value.
            "'1e2' vs '100'"    => ['1e2', '100', "numeric strings in different notation must not be collapsed"],
        ];
    }

    /**
     * And a config-file-only setting cannot be persisted at all.
     *
     * base.report_wrapper is static -- a stored value there would be an arbitrary
     * file include -- so persistSetting refuses it.
     */
    public function testAConfigFileOnlySettingCannotBePersistedAtAll(): void
    {
        $c = $this->settings();

        foreach ( array_keys(
            (array) ( \OWA\Module\Base\Classes\Settings::staticSettings()['base'] ?? [] ) )
            as $key ) {

            $this->assertFalse( $c->mayPersistInstallWide( 'base', $key ),
                sprintf( 'base.%s must never be storable: it is config-file-only, and for '
                       . 'report_wrapper and error_log_file a stored value is an RCE '
                       . 'primitive', $key ) );
        }
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
    public function testWritingAnEmptyStringStoresItRatherThanRemovingTheKey(): void
    {
        $c = $this->settings();
        // Declared, as only a declared key may be stored.
        $c->registerField('base', 'zz_removal_probe', ['default' => 'd', 'storable' => true]);

        $c->persistSetting('base', 'zz_removal_probe', '');

        $this->assertArrayHasKey('zz_removal_probe', $c->db_settings['base'],
            "'' is a value: persistSetting stores it, which is why it cannot express a removal");
        $this->assertSame('', $c->db_settings['base']['zz_removal_probe']);
    }

    /** removeSetting() is the one that removes it. */
    public function testRemoveSettingDropsTheStoredValue(): void
    {
        $c = $this->settings();
        $c->registerField('base', 'zz_removal_probe', ['default' => false, 'storable' => true]);

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

        $default = $c->default_config['base']['spam_detection'] ?? null;
        $this->assertNotNull($default, 'expected a code default for base.spam_detection');

        $c->persistSetting('base', 'spam_detection', 'not-the-default');
        $this->assertSame('not-the-default', $c->get('base', 'spam_detection'));

        $c->removeSetting('base', 'spam_detection');

        $this->assertSame($default, $c->get('base', 'spam_detection'),
            'removing a stored value restores the default, it does not blank the setting');
    }
}
