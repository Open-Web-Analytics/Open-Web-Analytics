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

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Installs OWA: applies the database schema, creates the admin user and adds the first tracked website. owa-config.php must exist first.',
            'arguments'   => array(
                'user_id=<name>'        => 'Required. The admin account\'s user name.',
                'password=<password>'   => 'Required. The admin account\'s password, at least ' . \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH . ' characters.',
                'email_address=<email>' => 'Required. The admin account\'s email address, used for password recovery.',
                'domain=<url>'          => 'Required. The domain of the first website to track.',
                'description=<text>'    => 'A description of the first website.',
                'site_family=<text>'    => 'A family name to group the first website under.',
            ),
        );
    }

    function __construct($params) {
        define('OWA_INSTALLING', true);
        return parent::__construct($params);
    }

    /**
     * The admin account's password is required. It used to be set to the
     * database password from owa-config.php, so whoever could read that file
     * could sign in as the admin, and changing one meant remembering the other.
     */
    public function validate()
    {
        $this->addValidation('password_required', $this->getParam('password'), 'required',
            ['errorMsg' => 'A password for the admin account is required: password=<password>.']);
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', [
            'operator' => '>=',
            'length'   => \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH,
            'errorMsg' => sprintf( 'The password must be at least %d characters.', \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH ),
        ]);
    }

    /** A refused install prints why, and has done nothing. */
    function errorAction() {

        $this->setView('base.cli');
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

                //create admin user
                $im->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), $this->getParam('password') );

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