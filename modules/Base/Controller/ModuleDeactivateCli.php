<?php
namespace OWA\Module\Base\Controller;

/**
 * Module Deactivation Controller
 */

class ModuleDeactivateCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Deactivates a module.',
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

        if ( \OWA\Core\CoreAPI::deactivateModule( $module ) === false ) {

            return $this->fail( sprintf( 'Module %s was not deactivated. See the messages above.', $module ) );
        }
    }
}
