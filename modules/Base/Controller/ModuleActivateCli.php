<?php
namespace OWA\Module\Base\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//


/**
 * Module Activation Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class ModuleActivateCli extends \OWA\Core\Controller\Cli {
    
    function __construct($params) {
    
        $this->setRequiredCapability('edit_modules');
        return parent::__construct($params);
    }

    function action() {
        
        $module = $this->getParam('module');
        
        if ( $module ) {
    
            /*
             * INSTALL, not activate.
             *
             * The admin UI's "Activate" control has always called
             * installModule() -- it creates the module's tables, records its
             * schema version and then activates it. This command called
             * activateModule(), which sets is_active and nothing else, so the
             * same word did two different things depending on where it was
             * typed, and the CLI one left a module switched on with no tables
             * and no schema version. Nothing reported that: getSchemaVersion()
             * defaults an absent version to 1, so the module read as current.
             *
             * install() is idempotent (see Module::install), so this is safe to
             * run against a module that is already installed.
             */
            $ret = \OWA\Core\CoreAPI::installModule($module);
            
        } else {
            \OWA\Core\CoreAPI::notice('No module argument was specified. Use module=xxx');
        }
    }
    
}

?>