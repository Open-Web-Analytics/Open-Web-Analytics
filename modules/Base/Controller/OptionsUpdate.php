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
 * Base Options Update Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class OptionsUpdate extends \OWA\Core\AdminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_settings');
        $this->setNonceRequired();
        return parent::__construct($params);

    }

    function action() {

        $c = \OWA\Core\CoreAPI::configSingleton();

        $config_values = $this->get('config');

        if (!empty($config_values)) {

            foreach ($config_values as $k => $v) {

                list($module, $name) = explode('.', $k);

                if ( $module && $name ) {

                    if ( self::isSensitiveSettingKey( $module, $name ) ) {

                        \OWA\Core\CoreAPI::notice( sprintf( 'Refusing to persist restricted setting %s.%s via options form.', $module, $name ) );
                        continue;
                    }

                    $c->persistSetting($module, $name, $v);
                }
            }

            $c->save();
            \OWA\Core\CoreAPI::notice("Configuration changes saved to database.");
            $this->setStatusCode(2500);
        }

        $this->setRedirectAction( $this->returnAction() );
    }

    /**
     * The settings page to go back to after saving.
     *
     * This used to be base.optionsGeneral unconditionally, which is right for
     * the page that form came from and wrong for every other one: saving the
     * GeoIP settings landed the administrator on the general settings page,
     * with the success message attached to a page they had not been editing.
     * It reads as though the save went somewhere unexpected.
     *
     * The submitting page names itself in a hidden field. A page that does not
     * -- every existing form -- gets the old destination, so nothing changes
     * for them.
     *
     * VALIDATED, because this is a form value and it becomes the next action
     * dispatched. Only a REGISTERED action is accepted, so the field cannot be
     * used to bounce an administrator into some unrelated part of OWA, and a
     * page that is renamed or removed falls back rather than 404ing after a
     * save that actually succeeded.
     *
     * @return string
     */
    protected function returnAction() {

        $requested = trim( (string) $this->getParam( 'return_action' ) );

        if ( $requested === '' ) {

            return 'base.optionsGeneral';
        }

        $registered = \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'actions', $requested );

        if ( ! $registered ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Ignoring an unrecognised return_action "%s" after saving settings.', $requested ) );

            return 'base.optionsGeneral';
        }

        return $requested;
    }

    /**
     * Restrict which settings the web options form is allowed to persist.
     *
     * These keys either name filesystem paths / stream targets that feed the
     * error logger and template loader, or hold credentials and directory
     * roots that must only ever be set via the config file or the installer.
     * Allowing them to be overwritten by an authenticated web request is an
     * RCE primitive (see error_log_file + report_wrapper chain).
     */
    private static function isSensitiveSettingKey( $module, $key ) {

        // Composed from the two lists on Settings so there is a single source
        // of truth, and so the REASON a key is denylisted stays visible:
        //
        //   configFileOnlySettings() - must never live in the database at all;
        //                              load() drops them, Update012 clears them.
        //   databaseStateSettings()  - legitimate database state that the form
        //                              simply must not edit (schema_version,
        //                              install_complete, ...).
        //
        // The union is identical to the previous hard-coded denylist; a test
        // pins that so the form's protection cannot narrow by accident.
        static $denylist = null;

        if ( $denylist === null ) {

            $denylist = \OWA\Module\Base\Classes\Settings::configFileOnlySettings();

            foreach ( \OWA\Module\Base\Classes\Settings::databaseStateSettings() as $m => $keys ) {

                $denylist[ $m ] = isset( $denylist[ $m ] )
                    ? array_merge( $denylist[ $m ], $keys )
                    : $keys;
            }
        }

        return isset( $denylist[ $module ][ $key ] );
    }

}


?>