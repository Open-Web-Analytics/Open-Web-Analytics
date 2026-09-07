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
 * Server Environment Check Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class InstallCheckEnv extends \OWA\Core\Controller\Install {

    function action() {

        /*
         * The checks themselves live in EnvironmentCheck, because
         * cmd=instance-info asks exactly the same questions of a running
         * instance. Two copies would disagree about what "supported" means, and
         * the disagreement would be invisible: nobody runs the installer on a
         * working instance, or the diagnostic on a fresh one.
         */
        $config_file = $this->c->get( 'base', 'config_file' );

        $checks = \OWA\Module\Base\Classes\EnvironmentCheck::all( $config_file );

        /*
         * The database connection, only when there is already a config file
         * naming one. Without a config file there is nothing to connect with,
         * and the next screen is where those details get entered.
         */
        $config_file_present = (bool) $this->c->isConfigFilePresent();

        if ( $config_file_present ) {

            $connected = (bool) $this->checkDbConnection();

            $checks[] = \OWA\Module\Base\Classes\EnvironmentCheck::check(
                'Database connection',
                $connected ? 'connected' : 'failed',
                $connected,
                'Check the database settings in ' . $config_file . '.' );
        }

        $failed = array_values( array_filter( $checks, static function ( $check ) {

            return ! $check['passed'];
        } ) );

        if ( ! $failed ) {

            if ( $config_file_present ) {

                // Everything is in place already; go straight to the defaults.
                $this->setRedirectAction( 'base.installDefaultsEntry' );

                return;
            }

            $this->setView( 'base.install' );
            $this->setSubview( 'base.installConfigEntry' );

            return;
        }

        /*
         * Every check, not just the failures.
         *
         * The screen used to list only what was wrong, which left no way to
         * tell "this was checked and is fine" from "this was never looked at"
         * -- and on a screen whose whole job is to describe the environment,
         * that is most of the information.
         */
        $this->set( 'checks', $checks );
        $this->set( 'errors', $failed );
        $this->setView( 'base.install' );
        $this->setSubview( 'base.installCheckEnv' );
    }

}

?>
