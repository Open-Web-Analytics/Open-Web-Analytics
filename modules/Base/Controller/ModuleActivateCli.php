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
    
            $ret = \OWA\Core\CoreAPI::activateModule($module);
            
        } else {
            \OWA\Core\CoreAPI::notice('No module argument was specified. Use module=xxx');
        }
    }
    
}

?>