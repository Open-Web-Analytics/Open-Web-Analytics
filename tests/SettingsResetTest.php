<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** Settings whose save() records instead of writing, so nothing reaches the database. */
class SettingsResetProbe extends \OWA\Module\Base\Classes\Settings
{
    /** @var int */
    public $saved = 0;

    public function save()
    {
        $this->saved++;

        return true;
    }
}

/**
 * Settings::reset($module) resets THAT module's stored settings.
 *
 * It replaces the module's own values,
 * keeping its schema version and is_active (database state, not
 * configuration), and base's install flag; a module that is not installed is
 * refused.
 *
 * Runs on a copy of the live settings with save() stubbed, and a made-up module
 * injected, so it depends on no installed module and writes nothing.
 */
final class SettingsResetTest extends TestCase
{
    private function probe(): SettingsResetProbe
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'Settings need a booted install.' );
        }

        $real  = \OWA\Core\CoreAPI::configSingleton();
        $probe = ( new ReflectionClass( SettingsResetProbe::class ) )->newInstanceWithoutConstructor();

        foreach ( ( new ReflectionClass( \OWA\Module\Base\Classes\Settings::class ) )->getProperties() as $p ) {
            if ( $p->isStatic() ) {
                continue;
            }
            $value = $p->getValue( $real );
            $p->setValue( $probe, is_object( $value ) ? clone $value : $value );
        }

        $probe->saved = 0;

        /*
         * A config of its own. clone is shallow, and the entity holds its
         * values in column objects, so the probe's set() below wrote base's
         * schema_version 90 into the live settings, which the next test that
         * reads it then saw.
         */
        $probe->config = \OWA\Core\CoreAPI::entityFactory( 'base.configuration' );
        $probe->config->set( 'settings', $real->config->get( 'settings' ) );

        $module = [ 'schema_version' => 3, 'is_active' => true, 'custom_key' => 'customised' ];
        $settings = $probe->config->get( 'settings' );
        $settings['resetprobe'] = $module;
        $settings['base']['schema_version'] = 90;
        $probe->config->set( 'settings', $settings );

        $probe->db_settings['resetprobe'] = $module;
        $probe->db_settings['base'] = [ 'schema_version' => 90, 'install_complete' => true, 'some_base_key' => 'kept' ];

        return $probe;
    }

    public function testResettingAModuleReplacesOnlyItsOwnSettings(): void
    {
        $s = $this->probe();

        $this->assertTrue( $s->reset( 'resetprobe' ) );
        $this->assertSame( 1, $s->saved );

        $this->assertSame( [ 'schema_version' => 3, 'is_active' => true ], $s->db_settings['resetprobe'],
            'its configuration goes; its schema version and is_active stay' );

        $this->assertSame(
            [ 'schema_version' => 90, 'install_complete' => true, 'some_base_key' => 'kept' ],
            $s->db_settings['base'],
            'base is not touched' );
    }

    public function testAnInactiveModuleStaysInactive(): void
    {
        $s = $this->probe();
        $settings = $s->config->get( 'settings' );
        $settings['resetprobe']['is_active'] = false;
        $s->config->set( 'settings', $settings );

        $this->assertTrue( $s->reset( 'resetprobe' ) );
        $this->assertFalse( $s->db_settings['resetprobe']['is_active'] );
    }

    public function testResettingBaseKeepsItsSchemaVersionAndInstallFlag(): void
    {
        $s = $this->probe();

        $this->assertTrue( $s->reset( 'base' ) );
        $this->assertSame( [ 'schema_version' => 90, 'install_complete' => true ], $s->db_settings['base'] );
    }

    public function testAModuleThatIsNotInstalledIsRefused(): void
    {
        $s = $this->probe();

        $this->assertFalse( $s->reset( 'no_such_module_installed' ) );
        $this->assertSame( 0, $s->saved );
        $this->assertArrayNotHasKey( 'no_such_module_installed', $s->db_settings );
    }

    /** The Reset screen says when nothing was reset, rather than nothing at all. */
    public function testTheResetScreenReportsARefusal(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'Settings need a booted install.' );
        }

        $c = new \OWA\Module\Base\Controller\OptionsReset( [ 'module' => 'no_such_module_installed' ] );
        $c->action();

        $this->assertSame( 3018, (int) $c->data['status_code'] );
        $this->assertSame( 'base.optionsGeneral', $c->data['do'] ?? null );
        $this->assertSame( 'The settings were not reset: the module is not installed, or the save failed. The error log says which.',
            $c->getMsg( 3018 )['message'] );
    }
}
