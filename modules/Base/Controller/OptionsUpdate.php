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

                    if ( ! $this->mayWriteModule( $module ) ) {

                        \OWA\Core\CoreAPI::notice( sprintf(
                            'Refusing to persist %s.%s: this form may only write %s settings.',
                            $module, $name, $this->allowedModule() ) );
                        continue;
                    }

                    /*
                     * A setting supplied by a config-file constant is not
                     * editable here, so it is REFUSED rather than stored.
                     *
                     * The constant beats the stored value on every boot, so
                     * persisting this would succeed and then be ignored -- a
                     * settings page that silently does nothing, which is worse
                     * than a config file that silently does nothing, because more
                     * people use the settings page. The form renders such fields
                     * disabled, so a browser will not submit them; this is the
                     * guarantee behind that courtesy, for a crafted POST or a
                     * stale page.
                     *
                     * Named in the notice, because "set in owa-config.php" leaves
                     * the operator hunting for which line.
                     */
                    $governing_constant = $c->configFileConstantFor( $module, $name );

                    if ( $governing_constant ) {

                        \OWA\Core\CoreAPI::notice( sprintf(
                            'Refusing to change %s.%s: it is set by %s in owa-config.php. '
                            . 'Change it there, or remove the constant to edit it from this page.',
                            $module, $name, $governing_constant ) );
                        continue;
                    }

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
     * The module whose settings this form is allowed to write, or null for any.
     *
     * The module name comes from the FIELD NAME -- config[module.setting] --
     * which means it is chosen by the browser, exactly like the redirect
     * destination was. A settings page that knows which module it edits can say
     * so, and then a posted field naming some other module is refused instead of
     * saved.
     *
     * Null by default, which is the behaviour every existing form has: the
     * shared controller cannot know what a third-party module's settings page
     * intends to write, and silently dropping those writes on upgrade would be
     * worse than the looseness. A page that subclasses this opts in by naming
     * its module.
     *
     * This is a narrowing, not the protection itself -- isSensitiveSettingKey()
     * still guards the settings that must never be written from a form at all,
     * whichever page is asking.
     *
     * @return string|null
     */
    protected function allowedModule() {

        return null;
    }

    /**
     * @param string $module
     * @return bool
     */
    protected function mayWriteModule( $module ) {

        $allowed = $this->allowedModule();

        return $allowed === null || $allowed === $module;
    }

    /**
     * The settings page to go back to after saving.
     *
     * This controller is the action every settings form in OWA posts to, so on
     * its own it can only send everyone to the same place -- which is right for
     * the general settings page the form came from, and wrong for every other
     * one. Saving the GeoIP settings used to land the administrator on the
     * general settings page, with "Options Saved." attached to a page they had
     * not been editing.
     *
     * A page that wants to be returned to subclasses this and says so. That
     * keeps the destination a fact about the code rather than a value posted by
     * the browser: nothing to validate, nothing to tamper with, and the same
     * shape as every other controller, which passes a literal to
     * setRedirectAction().
     *
     * @return string
     */
    protected function returnAction() {

        return 'base.optionsGeneral';
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