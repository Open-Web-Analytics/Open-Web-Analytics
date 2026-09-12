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
 * Module Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class ModuleInstallCli extends \OWA\Core\Controller\Cli {

    function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        return parent::__construct($params);
    }

    function action() {

        $module = $this->getParam('module');

        if ( ! $module ) {

            return $this->refuse( 'No module argument was specified. Use module=xxx' );
        }

        /*
         * DEPRECATED. Two commands did the same job under different names while
         * a third name, cmd=activate, did only half of it -- so which of the
         * three to type depended on knowing that history. cmd=activate now
         * installs, matching what the admin UI's "Activate" control has always
         * done, which leaves this one redundant.
         *
         * Still does exactly what it did, because scripts call it. It goes away
         * at v2.0, alongside the other deprecations.
         */
        \OWA\Core\CoreAPI::notice(
            'cmd=install-module is deprecated and will be removed in 2.0. '
          . 'Use "cmd=activate module=' . $module . '" instead -- it installs '
          . 'and activates, which is what this command does.' );

        return \OWA\Core\CoreAPI::installModule($module);
    }

}

?>