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
 * base Schema Installation Controller
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class InstallBase extends \OWA\Core\Controller\Install {

    function __construct($params) {

        parent::__construct($params);

        // require nonce
        $this->setNonceRequired();
    }
    
    public function validate() {
	    
	    // Do not add any validations here that require DB lookups
	    
        $this->addValidation('domain', \OWA\Module\Base\Classes\SiteManager::domainOnly( $this->getParam('domain') ), 'required', ['errorMsg' => $this->getMsg(3309)]);
        $this->addValidation('user_id', $this->getParam('user_id'), 'required', array('stopOnError'	=> true));
	    $this->addValidation('user_id', $this->getParam('user_id'), 'userName', array('stopOnError'	=> true));
        $this->addValidation('timezone', $this->getParam('timezone'), 'required', ['errorMsg' => $this->getMsg(3310)]);
        $this->addValidation('email_address', $this->getParam('email_address'), 'required', ['errorMsg' => $this->getMsg(3310)]);
        $this->addValidation('password', $this->getParam('password'), 'required', ['errorMsg' => $this->getMsg(3310)]);
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', [
            'operator' => '>=',
            'length'   => \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH,
            'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'The password must be at least %d characters.' ), \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH ),
        ]);

        /*
         * A pasted URL is no longer refused.
         *
         * The form used to ask for the scheme in a select of its own, so a
         * domain that also carried one produced "https://https://example.com"
         * -- hence a validation that rejected anything starting with "http".
         * The select is gone and the scheme is stripped instead
         * (SiteManager::domainOnly(), in InstallManager::createDefaultSite());
         * refusing the most natural thing to paste bought nothing.
         */
    }

    function action() {

        $status = $this->installSchema();

        if ($status == true) {
            /*
             * No status banner on the way to the finish screen.
             *
             * It announced "Base Database Schema Installed." above a page whose
             * own headline is "Installation complete" -- the same news, told
             * twice, the second time as a sub-step nobody asked about.
             */

            /*
             * The admin, the first site, the timezone and the install-complete
             * flag are saved together or not at all (finishInstall()): a step
             * that fails part way can be run again.
             */
            $site_id = $this->im->finishInstall( function () {

                $this->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), $this->getParam('password') );

                $site_id = $this->createDefaultSite( $this->getParam('domain') );

                /*
                 * Persisted here, with the rest of the install, because the choice
                 * is NOT retroactive: yyyymmdd and the nine date-part columns are
                 * derived in this timezone and written into every fact row, so
                 * changing it later re-buckets new rows while history keeps the old
                 * boundaries -- and nothing records which zone a row was derived
                 * under. Asking at install is the only point where the answer costs
                 * nothing.
                 *
                 * Guarded against a bad value rather than trusted: this arrives from
                 * a form, and date_default_timezone_set() on an unknown identifier
                 * would leave every subsequent date derivation on whatever the
                 * previous default was.
                 */
                $timezone = $this->getParam('timezone');

                /*
                 * A constant in owa-config.php already decides this, and wins on
                 * every boot, so storing a value here would be inert -- and would
                 * quietly take effect if the constant were ever removed. The form
                 * renders the field disabled in that case; this is the half that
                 * does not depend on the browser having honoured it.
                 */
                if ( $this->c->configFileConstantFor( 'base', 'timezone' ) ) {

                    $this->e->notice( 'Timezone supplied by a config file constant; not storing the submitted value.' );

                } elseif ( $timezone && in_array( $timezone, \DateTimeZone::listIdentifiers(), true ) ) {

                    $this->c->persistSetting('base', 'timezone', $timezone);
                }

                return $site_id;
            } );

            if ( ! $site_id ) {

                $this->set('error_msg', \OWA\Core\CoreAPI::t( 'The install did not complete, and nothing was saved. Check the error log, then submit this form again.' ));
                $this->errorAction();

                return;
            }

            // fire install complete event.
            $ed = \OWA\Core\CoreAPI::getEventDispatch();
            $event = $ed->eventFactory();
            $event->set('u', $this->getParam('user_id'));
            $event->set('site_id', $site_id);
            $event->setEventType('install_complete');
            $ed->notify($event);

            // set view
            $this->set('u', $this->getParam('user_id'));

            $this->set('site_id', $site_id);
            $this->setView('base.install');
            $this->setSubview('base.installFinish');

        } else {

            $this->set('error_msg', $this->getMsg(3302));
            $this->errorAction();
        }
    }

    function errorAction() {

        $this->set('defaults', $this->params);
        $this->setView('base.install');
        $this->setSubView('base.installDefaultsEntry');
    }
}

?>