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
	    
        $this->addValidation('domain', $this->getParam('domain'), 'required', ['errorMsg' => $this->getMsg(3309)]);
        $this->addValidation('user_id', $this->getParam('user_id'), 'required', array('stopOnError'	=> true));
	    $this->addValidation('user_id', $this->getParam('user_id'), 'userName', array('stopOnError'	=> true));
        $this->addValidation('timezone', $this->getParam('timezone'), 'required', ['errorMsg' => $this->getMsg(3310)]);
        $this->addValidation('email_address', $this->getParam('email_address'), 'required', ['errorMsg' => $this->getMsg(3310)]);
        $this->addValidation('password', $this->getParam('password'), 'required', ['errorMsg' => $this->getMsg(3310)]);

        /*
         * A pasted URL is no longer refused.
         *
         * The form used to ask for the scheme in a select of its own, so a
         * domain that also carried one produced "https://https://example.com"
         * -- hence a validation that rejected anything starting with "http".
         * The select is gone and the scheme is stripped instead, which is what
         * every reader of this column does anyway; refusing the most natural
         * thing to paste bought nothing.
         */
    }

    /**
     * A domain, from whatever was typed.
     *
     * Stores what the column is for. Site::getDomainName() strips a scheme on
     * the way out for the rows that still have one, so writing a bare domain
     * here means the two agree rather than one undoing the other -- and it
     * matches what the CLI installer has always stored.
     *
     * @param string $domain
     * @return string
     */
    public static function domainOnly( $domain ) {

        $domain = trim( (string) $domain );

        $separator = strpos( $domain, '://' );

        if ( $separator !== false ) {

            $domain = substr( $domain, $separator + 3 );
        }

        return rtrim( trim( $domain ), '/' );
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

            $password = $this->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), $this->getParam('password') );

            $site_id = $this->createDefaultSite( self::domainOnly( $this->getParam('domain') ) );

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

            // Set install complete flag.
            $this->c->persistSetting('base', 'install_complete', true);
            $save_status = $this->c->save();

            if ($save_status == true) {
                $this->e->notice('Install Complete Flag added to configuration');
            } else {
                $this->e->notice('Could not add Install Complete Flag to configuration.');
            }

            // fire install complete event.
            $ed = \OWA\Core\CoreAPI::getEventDispatch();
            $event = $ed->eventFactory();
            $event->set('u', $this->getParam('user_id'));
            $event->set('p', $password);
            $event->set('site_id', $site_id);
            $event->setEventType('install_complete');
            $ed->notify($event);

            // set view
            $this->set('u', $this->getParam('user_id'));

            /*
             * The password ONLY when OWA generated it.
             *
             * createAdminUser() generates one only when none was supplied, and
             * this form requires one -- so on the web path this is a password
             * the operator just chose, and printing it back tells them nothing
             * while leaving a live credential in the page, in the back-forward
             * cache, and in any screenshot of the completion screen.
             *
             * The CLI installer can be run without one, which is the case that
             * has to be told; it prints its own.
             */
            $this->set( 'p', $this->getParam('password') ? '' : $password );
            $this->set('site_id', $site_id);
            $this->setView('base.install');
            $this->setSubview('base.installFinish');
            //$this->set('status_code', 3304);

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