<?php

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
 * Installation CLI Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_installCliController extends owa_cliController {

    function __construct($params) {
        define('OWA_INSTALLING', true);
        return parent::__construct($params);
    }

    function action() {

        $service = owa_coreAPI::serviceSingleton();
        $im = owa_coreAPI::supportClassFactory('base', 'installManager');
        $this->e->notice('Starting OWA Install from command line.');

        //create config file
        $present = $this->c->isConfigFilePresent();

        if ( $present ) {

            $this->c->applyConfigConstants();

            // install schema
            $status = $im->installSchema();

            // schema was installed successfully
            if ($status === true) {

                //create admin user
                //owa_coreAPI::debug('password: '.owa_lib::encryptPassword( $this->c->get('base', 'db_password') ) );
                $im->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), $this->c->get('base', 'db_password') );

                // create default site
                $im->createDefaultSite(
                        $this->getParam('domain'),
                        $this->getParam('domain'),
                        $this->getParam('description'),
                        $this->getParam('site_family')
                );

                // Persist install complete flag.
                $this->c->persistSetting('base', 'install_complete', true);
                $save_status = $this->c->save();

                if ($save_status === true) {
                    $this->e->notice('Install Completed.');
                } else {
                    $this->e->notice('Could not persist Install Complete Flag to the Database');
                }

            // schema was not installed successfully
            } else {
                $this->e->notice('Aborting embedded install due to errors installing schema. Try dropping all OWA tables and try again.');
                return false;
            }


        } else {
            $this->e->notice("Could not locate config file. Aborting installation.");
        }
    }
}

?>