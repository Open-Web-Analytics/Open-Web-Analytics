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

require_once(OWA_BASE_CLASS_DIR.'installController.php');

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

class owa_installBaseController extends owa_installController {

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
        $this->addValidation('email_address', $this->getParam('email_address'), 'required', ['errorMsg' => $this->getMsg(3310)]);
        $this->addValidation('password', $this->getParam('password'), 'required', ['errorMsg' => $this->getMsg(3310)]);

        $domainConf = [
            'substring' => 'http',
            'position'  => 0,
            'operator'  => '!=',
            'errorMsg'  => $this->getMsg(3208)
        ];

        $this->addValidation('domain', $this->getParam('domain'), 'subStringPosition', $domainConf);
    }

    function action() {

        $status = $this->installSchema();

        if ($status == true) {
            $this->set('status_code', 3305);

            $password = $this->createAdminUser($this->getParam('user_id'), $this->getParam('email_address'), $this->getParam('password') );

            $site_id = $this->createDefaultSite($this->getParam('protocol').$this->getParam('domain'));

            // Set install complete flag.
            $this->c->persistSetting('base', 'install_complete', true);
            $save_status = $this->c->save();

            if ($save_status == true) {
                $this->e->notice('Install Complete Flag added to configuration');
            } else {
                $this->e->notice('Could not add Install Complete Flag to configuration.');
            }

            // fire install complete event.
            $ed = owa_coreAPI::getEventDispatch();
            $event = $ed->eventFactory();
            $event->set('u', $this->getParam('user_id'));
            $event->set('p', $password);
            $event->set('site_id', $site_id);
            $event->setEventType('install_complete');
            $ed->notify($event);

            // set view
            $this->set('u', $this->getParam('user_id'));
            $this->set('p', $password);
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