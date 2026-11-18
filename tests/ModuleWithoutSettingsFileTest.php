<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A module needs a settings.php only for settings of its own.
 *
 * is_active and schema_version are OWA's bookkeeping. A module that shipped no
 * settings.php had neither declared, so persistSetting() refused its
 * is_active and the module could never be switched on. They now count as
 * declared for any module whose code is on disk; any other key still has to
 * be declared, and nothing becomes active until something activates it.
 *
 * Uses a real, empty module directory for its duration: whether the module is
 * on disk is the thing being decided.
 */
final class ModuleWithoutSettingsFileTest extends TestCase
{
    private const MODULE = 'zz_no_settings_probe';

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = \OWA\Core\Env::modulesDir() . \OWA\Core\Lib::moduleDirName( self::MODULE );

        if ( ! is_dir( $this->dir ) ) {
            mkdir( $this->dir );
        }
    }

    protected function tearDown(): void
    {
        if ( owa_test_db_available() ) {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->query( 'DELETE FROM owa_setting WHERE module IN ( ?, ? )', array( self::MODULE, 'zz_declares_nothing_probe' ) );
        }

        if ( is_dir( $this->dir ) ) {
            rmdir( $this->dir );
        }
    }

    private function settings(): \OWA\Module\Base\Classes\Settings
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    public function testItsMechanicalKeysAreDeclaredWithoutTheFile(): void
    {
        $s = $this->settings();

        $this->assertTrue( $s->isRegistered( self::MODULE, 'is_active' ) );
        $this->assertTrue( $s->isRegistered( self::MODULE, 'schema_version' ) );
        $this->assertTrue( $s->mayPersistInstallWide( self::MODULE, 'is_active' ), 'it can be activated' );
    }

    public function testAnyOtherKeyStillNeedsADeclaration(): void
    {
        $s = $this->settings();

        $this->assertFalse( $s->isRegistered( self::MODULE, 'some_setting' ) );
        $this->assertFalse( $s->mayPersistInstallWide( self::MODULE, 'some_setting' ) );
    }

    public function testAModuleNotOnDiskGetsNothing(): void
    {
        $this->assertFalse( $this->settings()->isRegistered( 'zz_absent_probe', 'is_active' ) );
    }

    public function testDeclaringIsNotActivating(): void
    {
        $this->assertNotTrue( $this->settings()->get( self::MODULE, 'is_active' ) );
    }

    public function testOnlyMechanicalRowsAreNotReportedAsUndeclared(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'This asks the database.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $insert = "INSERT INTO owa_setting ( id, scope_type, scope_id, module, name, value, creation_date ) VALUES ( ?, 'install', '1', ?, ?, ?, 0 )";

        $this->assertNotFalse( $db->query( $insert, array( 7770000000000101, self::MODULE, 'is_active', serialize( true ) ) ) );
        $this->assertNotFalse( $db->query( $insert, array( 7770000000000102, self::MODULE, 'schema_version', serialize( 1 ) ) ) );
        $this->assertNotFalse( $db->query( $insert, array( 7770000000000103, 'zz_declares_nothing_probe', 'colour', serialize( 'red' ) ) ) );

        $undeclared = $this->settings()->undeclaredModules();

        $this->assertNotContains( self::MODULE, $undeclared, 'only its bookkeeping is stored' );
        $this->assertContains( 'zz_declares_nothing_probe', $undeclared, 'a real undeclared setting is still reported' );
    }
}
