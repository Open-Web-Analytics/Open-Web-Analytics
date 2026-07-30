<?php

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
    /** @var array snapshot of the shared singleton's state */
    private $snapshot = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * These tests exercise the SHARED config singleton, and Settings::__destruct()
     * calls save() whenever is_dirty is set -- so without this, mutations here get
     * flushed to the real owa_configuration row at script shutdown, long after any
     * individual test finishes. (Learned the hard way: an earlier version of this
     * file rewrote query_string_filters and schema_version in the dev database.)
     *
     * Snapshot before, restore after, and clear is_dirty so nothing is written.
     */
    protected function setUp(): void
    {
        $c = $this->settings();

        $this->snapshot = [
            'db_settings'    => $c->db_settings,
            'default_config' => $c->default_config,
            'is_dirty'       => $c->is_dirty,
        ];
    }

    protected function tearDown(): void
    {
        $c = $this->settings();

        $c->db_settings    = $this->snapshot['db_settings'];
        $c->default_config = $this->snapshot['default_config'];
        // Must be last: restoring the arrays above does not reset the flag, and
        // a stale is_dirty is exactly what triggers the shutdown write.
        $c->is_dirty       = $this->snapshot['is_dirty'];
    }

    private function settings(): object
    {
        return owa_coreAPI::configSingleton();
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
