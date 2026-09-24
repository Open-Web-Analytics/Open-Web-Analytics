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
 * from owa-config.php.
 *
 * This used to be enforced by a strip: the keys were removed from whatever
 * load() had fetched. Base declares them STATIC now, so the boot query never
 * asks for them and persistSetting() refuses to create one -- there is nothing
 * arriving to strip. The property to assert therefore changed from "it was
 * removed" to "it is never stored and never read", which is what these check.
 *
 * The contract has two sides and both need pinning. A value in the data store
 * does not reach the running config. A value established from the config file
 * does: owa-config.php is included from inside the settings object before the
 * configuration entity exists, so it writes the defaults array.
 *
 * The second half is the one worth guarding. The demo installation opens its
 * reports to unauthenticated visitors with a single config-file call, and a
 * change here that broke the one documented way to customise this would be a
 * poor trade for the tidiness it bought.
 */
final class CapabilitiesConfigFileOnlyTest extends TestCase
{
    private const KEYS = array( 'capabilities', 'capabilitiesThatRequireSiteAccess' );

    private function settings()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    private function staticBaseSettings(): array
    {
        return \OWA\Module\Base\Classes\Settings::staticSettings()['base'] ?? array();
    }

    public function testBothHalvesOfTheModelAreStatic(): void
    {
        $d = $this->staticBaseSettings();

        // Listed together on purpose. Freezing the role map while leaving the
        // site-access list writable would split one authorization model across
        // two storage rules, which is worse than either choice made
        // consistently.
        foreach ( self::KEYS as $key ) {

            $this->assertArrayHasKey( $key, $d );
            $this->assertTrue( $this->settings()->isRegistered( 'base', $key ),
                sprintf( 'base.%s must be declared, or nothing constrains it', $key ) );
        }
    }

    /** Neither may be written, by the form or by anything else. */
    public function testNeitherCanBePersisted(): void
    {
        foreach ( self::KEYS as $key ) {

            $this->assertFalse( $this->settings()->mayPersistInstallWide( 'base', $key ),
                sprintf( 'base.%s must be unstorable; a stored role map is an '
                       . 'authorization bypass', $key ) );
        }
    }

    /** Neither is fetched at boot. */
    public function testNeitherIsInTheBootQuery(): void
    {
        $eager = (array) ( $this->settings()->eagerSettings()['base'] ?? array() );

        foreach ( self::KEYS as $key ) {

            $this->assertNotContains( $key, $eager,
                sprintf( 'base.%s must not be fetched at boot', $key ) );
        }
    }

    /**
     * And a row in the table does not reach the running config.
     *
     * The end-to-end version of what the strip used to prove with a pure
     * function call. A real row, a real reload, and the shipped policy still
     * standing.
     */
    public function testARowInTheTableDoesNotReachTheRunningConfig(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'writes a row and reloads' );
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );
        $table  = $entity->getTableName();
        $id     = $db->prepare( (string) $entity->makeId( 'install', '1', 'base', 'capabilities' ) );

        $db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $table, $id ) );
        $db->query( sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)"
          . " VALUES ('%s', 'install', '1', 'base', 'capabilities', '%s', 1, '0')",
            $table, $id,
            $db->prepare( serialize( array( 'everyone' => array( 'edit_users', 'edit_settings' ) ) ) ) ) );

        $this->settings()->load( 1 );

        $live = \OWA\Core\CoreAPI::getSetting( 'base', 'capabilities' );

        $db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $table, $id ) );
        $this->settings()->load( 1 );

        $this->assertIsArray( $live );

        /*
         * The GRANT, not the role. `everyone` exists in the shipped policy
         * too -- it holds install_schema -- so asserting the key is absent
         * would pass against a total leak. What must not survive is the
         * stored map's contents.
         */
        $this->assertNotContains( 'edit_users', $live['everyone'] ?? array(),
            'a stored role map reached the running config: it would grant '
          . 'edit_users to every visitor' );
        $this->assertSame( array( 'install_schema' ), $live['everyone'] ?? null,
            'the shipped grant for everyone stands unchanged' );
        $this->assertArrayHasKey( 'admin', $live, 'the shipped policy still stands' );
    }

    /**
     * The supported route, which none of this may break.
     *
     * owa-config.php runs at constructor step 2 -- after the defaults are
     * built, BEFORE the configuration entity exists at step 3. set() branches
     * on whether that entity is present, so a call from the config file lands
     * in the defaults array, which is not database state and is not subject to
     * any of the above.
     */
    public function testAConfigFileGrantStillReachesTheRunningConfig(): void
    {
        $live = \OWA\Core\CoreAPI::getSetting( 'base', 'capabilities' );

        $this->assertIsArray( $live );
        $this->assertArrayHasKey( 'admin', $live );
        $this->assertContains( 'view_reports', $live['admin'],
            'the shipped policy must survive the change' );
    }

    /**
     * Vacuity guard.
     *
     * Every assertion above would also pass if EVERYTHING were static. Prove
     * the declaration discriminates: the settings the admin UI is meant to
     * edit are storable.
     */
    public function testStaticIsNotABlanketRefusal(): void
    {
        $d = $this->staticBaseSettings();

        foreach ( array( 'timezone', 'log_robots', 'anonymize_ips' ) as $editable ) {

            $this->assertArrayNotHasKey( $editable, $d,
                sprintf( 'base.%s is meant to be editable from the admin UI', $editable ) );

            $this->assertTrue( $this->settings()->mayPersistInstallWide( 'base', $editable ),
                sprintf( 'base.%s must be storable', $editable ) );
        }
    }
}
