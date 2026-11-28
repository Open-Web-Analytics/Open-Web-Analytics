<?php
namespace OWA\Module\Base\Controller;

/**
 * Module Activation Controller
 */

class ModuleActivateCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::serverRoles(). The same administration the console's screens offer. */
    public static function serverRoles() {

        return [ \OWA\Core\ServerRole::FULL, \OWA\Core\ServerRole::WORKER, \OWA\Core\ServerRole::CONSOLE ];
    }

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Installs and activates a module.',
            'arguments'   => [
                'module' => [
                    'type'        => 'module',
                    'required'    => true,
                    'description' => 'The module\'s name.',
                ],
            ],
        ];
    }
    
    public function __construct($params) {
    
        $this->setRequiredCapability('edit_modules');
        parent::__construct($params);
    }

    public function action() {
        
        $module = (string) $this->param( 'module' );

        /*
         * INSTALL, not activate.
         *
         * The admin UI's "Activate" control has always called
         * installModule() -- it creates the module's tables, records its
         * schema version and then activates it. This command called
         * Module::activate(), which sets is_active and nothing else, so the
         * same word did two different things depending on where it was
         * typed, and the CLI one left a module switched on with no tables
         * and no schema version. Nothing reported that: getSchemaVersion()
         * defaults an absent version to 1, so the module read as current.
         *
         * install() is idempotent (see Module::install), so this is safe to
         * run against a module that is already installed.
         */
        $ret = \OWA\Core\CoreAPI::installModule( $module );

        if ( ! $ret ) {

            return $this->fail( sprintf( 'Module %s was not installed. See the messages above.', $module ) );
        }
    }
}
