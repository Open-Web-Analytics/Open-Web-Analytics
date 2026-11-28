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
                'module=<name>' => 'Required. The module\'s name.',
            ],
        ];
    }
    
    public function __construct($params) {
    
        $this->setRequiredCapability('edit_modules');
        parent::__construct($params);
    }

    public function action() {
        
        $module = $this->getParam('module');
        
        if ( $module ) {
    
            $ret = \OWA\Core\CoreAPI::deactivateModule($module);

            if ( $ret === false ) {

                return $this->fail( sprintf( 'Module %s was not deactivated. See the messages above.', $module ) );
            }

        } else {
            return $this->usageError('No module argument was specified. Use module=xxx');
        }
    }
    
}
