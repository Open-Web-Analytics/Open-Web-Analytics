<?php
/**
 * A module for tests that need one that is not shipped: no tables, one View
 * class. Loaded with require_once; it is not on disk under modules/, so
 * getPresentModules() does not list it and boot never loads it.
 * CoreAPI::moduleClassFactory() and viewFactory() find its classes by name.
 *
 * With no settings.php on disk, Module::settingsRegistry() does not give it
 * is_active and schema_version, and persistSetting() refuses a key nothing
 * declares. The constructor declares the two, as settingsRegistry() would.
 *
 * A test that activates it deletes its rows and declarations afterward
 * (Module::forget()).
 */

namespace OWA\Module\TestFixture {

    class Module extends \OWA\Core\Module {

        const NAME = 'test_fixture';

        public function __construct() {

            $this->name = self::NAME;
            $this->display_name = 'Test Fixture';
            $this->group = self::NAME;
            $this->author = 'OWA tests';
            $this->version = '1.0';
            $this->description = 'A module for tests.';
            $this->config_required = false;
            $this->required_schema_version = 1;

            foreach ( \OWA\Core\Module::mechanicalSettings() as $key => $args ) {

                $this->registerSettingsField( $key, $args );
            }

            parent::__construct();
        }

        /**
         * Delete every stored row of this module, and drop its values and
         * declarations from the settings.
         *
         * @return void
         */
        public static function forget() {

            $c = \OWA\Core\CoreAPI::configSingleton();

            foreach ( array_keys( \OWA\Core\Module::mechanicalSettings() ) as $key ) {

                $c->removeSetting( self::NAME, $key );
            }

            ( function () {
                foreach ( array_keys( \OWA\Core\Module::mechanicalSettings() ) as $key ) {
                    unset( $this->registry[ Module::NAME . '|' . $key ], $this->pending[ Module::NAME . '|' . $key ],
                        $this->loaded[ Module::NAME . '|' . $key ] );
                }
            } )->call( $c );

            $db = \OWA\Core\CoreAPI::dbSingleton();

            if ( function_exists( 'owa_test_db_available' ) && owa_test_db_available() ) {

                $db->query( sprintf( 'DELETE FROM %s WHERE module = ?',
                    \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName() ), [ self::NAME ] );
            }
        }
    }
}

namespace OWA\Module\TestFixture\View {

    /** A module's own View class. Its render() sets a template and a var. */
    class Example extends \OWA\Core\View {

        /**
         * @param  array<string, mixed> $data
         * @return void
         */
        public function render( $data ) {

            $this->body->setTemplateFile( 'base', 'json.php' );
            $this->body->set( 'json', 'set by the class' );
        }
    }
}
