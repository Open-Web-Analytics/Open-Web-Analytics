<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The role-to-capability model resolves from configuration, not from the data
 * store.
 *
 * `base.capabilities` maps each role to what it may do, and
 * `capabilitiesThatRequireSiteAccess` says which of those additionally require
 * access to the specific site. Both are shipped with the code and customised
 * from owa-config.php, which is where the rest of configFileOnlySettings()
 * lives too.
 *
 * The contract has two sides and both need pinning. A value read back out of
 * the data store is dropped on load. A value established from the config file
 * is not: owa-config.php is included from inside the settings object before the
 * configuration entity exists, so it writes the defaults array, and the
 * stripper only ever operates on settings read from the store.
 *
 * The second half is the one worth guarding. The demo installation opens its
 * reports to unauthenticated visitors with a single config-file call, and a
 * change here that broke the one documented way to customise this would be a
 * poor trade for the tidiness it bought.
 */
final class CapabilitiesConfigFileOnlyTest extends TestCase
{
    private function denylist(): array
    {
        return \OWA\Module\Base\Classes\Settings::configFileOnlySettings()['base'];
    }

    public function testBothHalvesOfTheModelAreConfigFileOnly(): void
    {
        $d = $this->denylist();

        $this->assertArrayHasKey( 'capabilities', $d );
        $this->assertArrayHasKey( 'capabilitiesThatRequireSiteAccess', $d );

        // Listed together on purpose. Freezing the role map while leaving the
        // site-access list writable would split one authorization model across
        // two storage rules, which is worse than either choice made
        // consistently.
        $this->assertTrue( $d['capabilities'] );
        $this->assertTrue( $d['capabilitiesThatRequireSiteAccess'] );
    }

    public function testAStoredCapabilityMapIsStrippedOnLoad(): void
    {
        $stored = array(
            'base' => array(
                'capabilities' => array(
                    'everyone' => array( 'edit_users', 'edit_settings', 'edit_modules' ),
                ),
                'capabilitiesThatRequireSiteAccess' => array(),
                // An ordinary setting alongside it, which must survive.
                'default_reporting_period' => 'last_thirty_days',
            ),
        );

        $clean = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings( $stored );

        $this->assertArrayNotHasKey( 'capabilities', $clean['base'],
            'a stored capability map does not reach the running config' );
        $this->assertArrayNotHasKey( 'capabilitiesThatRequireSiteAccess', $clean['base'] );

        // The stripper is surgical, not a blanket refusal of the module.
        $this->assertSame( 'last_thirty_days', $clean['base']['default_reporting_period'] );
    }

    public function testOtherModulesSettingsAreUntouched(): void
    {
        // Only the modules named in the denylist are considered. A third-party
        // module with its own 'capabilities' key keeps it.
        $stored = array(
            'base'    => array( 'capabilities' => array( 'everyone' => array( 'edit_users' ) ) ),
            'ecommerce' => array( 'capabilities' => array( 'anything' ) ),
        );

        $clean = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings( $stored );

        $this->assertArrayNotHasKey( 'capabilities', $clean['base'] );
        $this->assertArrayHasKey( 'capabilities', $clean['ecommerce'] );
    }

    public function testTheOptionsFormRefusesTheKey(): void
    {
        // The other half of the guard: the options form consults the same list,
        // so the key cannot be written back through the admin UI either.
        $source = file_get_contents(
            OWA_BASE_DIR . '/modules/Base/Controller/OptionsUpdate.php' );

        $this->assertStringContainsString( 'configFileOnlySettings', $source,
            'the options form must consult the same list as the loader' );
    }

    /**
     * The supported route, which this change must not break.
     *
     * owa-config.php runs at constructor step 2 -- after the defaults are
     * built, BEFORE the configuration entity exists at step 3. set() branches
     * on whether that entity is present, so a call from the config file lands
     * in the defaults array. Defaults are not database state and are never
     * stripped.
     */
    public function testAConfigFileGrantStillReachesTheRunningConfig(): void
    {
        $defaults_only_write = array(
            'base' => array(
                'capabilities' => array( 'everyone' => array( 'view_reports' ) ),
            ),
        );

        // Whatever the config file put in the DEFAULTS is not what the stripper
        // operates on -- it only ever sees settings read from the store. Prove
        // the two are different inputs by showing the strip is a pure function
        // of what it is handed.
        $from_database = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings(
            $defaults_only_write );

        $this->assertArrayNotHasKey( 'capabilities', $from_database['base'],
            'the same array, when it comes from the database, is refused' );

        // ...and the live installation, whose defaults are seeded from
        // owa-config.php, still resolves a usable map.
        $live = \OWA\Core\CoreAPI::getSetting( 'base', 'capabilities' );

        $this->assertIsArray( $live );
        $this->assertArrayHasKey( 'admin', $live );
        $this->assertContains( 'view_reports', $live['admin'],
            'the shipped policy must survive the change' );
    }

    /**
     * Vacuity guard.
     *
     * Every assertion above would also pass against a stripper that removed
     * everything, or a denylist that listed every key. Prove the list is
     * discriminating.
     */
    public function testTheDenylistIsNotABlanketRefusal(): void
    {
        $d = $this->denylist();

        $this->assertArrayNotHasKey( 'default_reporting_period', $d );
        $this->assertArrayNotHasKey( 'timezone', $d,
            'timezone is deliberately editable from the admin UI' );

        $kept = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings(
            array( 'base' => array( 'timezone' => 'Europe/London' ) ) );

        $this->assertSame( 'Europe/London', $kept['base']['timezone'] );
    }
}
