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

class InstallCli extends \OWA\Core\Controller\Cli {

    function __construct($params) {
        define('OWA_INSTALLING', true);
        return parent::__construct($params);
    }

    function action() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $im = \OWA\Core\CoreAPI::supportClassFactory('base', 'installManager');
        $this->e->notice('Starting OWA Install from command line.');

        //create config file
        $present = $this->c->isConfigFilePresent();

        if ( $present ) {

            $this->c->applyConfigConstants();

            // install schema
            $status = $im->installSchema();

            // schema was installed successfully
            if ($status === true) {

                /*
                 * The admin's password is generated here and printed once. It
                 * used to be the database password from owa-config.php, so
                 * whoever could read that file could sign in as the admin.
                 *
                 * The admin, the first site and the install-complete flag are
                 * saved together or not at all (InstallManager::finishInstall()).
                 */
                $created = '';

                $site_id = $im->finishInstall( function () use ( $im, &$created ) {

                    // No password: createAdminUser() generates one and returns it.
                    $created = $im->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), '' );

                    return $im->createDefaultSite(
                            $this->getParam('domain'),
                            $this->getParam('domain'),
                            $this->getParam('description'),
                            $this->getParam('site_family')
                    );
                } );

                if ( ! $site_id ) {

                    $this->e->notice('The install did not complete, and nothing was saved. Check the error log, then run this command again.');

                    return false;
                }

                $this->e->notice('Install Completed.');

                if ( $created ) {

                    echo sprintf( "Admin password (generated, shown once): %s\n", $created );
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