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
 * Install Configuration Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_installConfigController extends owa_installController {

    function __construct($params) {

        parent::__construct($params);

        // require nonce
        $this->setNonceRequired();

    }

    public function validate()
    {
        //required params
        $this->addValidation('db_host', $this->getParam('db_host'), 'required', ['errorMsg' => 'Database host is required.']);
        $this->addValidation('db_name', $this->getParam('db_name'), 'required', ['errorMsg' => 'Database name is required.']);
        $this->addValidation('db_user', $this->getParam('db_user'), 'required', ['errorMsg' => 'Database user is required.']);
        $this->addValidation('db_password', $this->getParam('db_password'), 'required', ['errorMsg' => 'Database password is required.']);
        $this->addValidation('db_type', $this->getParam('db_type'), 'required', ['errorMsg' => 'Database type is required.']);

        // Config for the public_url validation
        $publicUrlConf = [
            'substring' => 'http',
            'match'     => '/',
            'length'    => -1,
            'position'  => -1,
            'operator'  => '=',
            'errorMsg'  => 'Your URL of OWA\'s base directory must end with a slash.'
        ];

        $this->addValidation('public_url', $this->getParam('public_url'), 'subStringMatch', $publicUrlConf);

        // Config for the domain validation
        $domainConf = [
            'substring' => 'http',
            'position'  => 0,
            'operator'  => '=',
            'errorMsg'  => 'Please add http:// or https:// to the beginning of your public url.'
        ];

        $this->addValidation('public_url', $this->getParam('public_url'), 'subStringPosition', $domainConf);
    }

    function action() {

        // define db connection constants using values submitted
        if ( ! defined( 'OWA_DB_TYPE' ) ) {
            define( 'OWA_DB_TYPE', $this->getParam( 'db_type' ) );
        }

        if ( ! defined( 'OWA_DB_HOST' ) ) {
            define('OWA_DB_HOST', $this->getParam( 'db_host' ) );
        }

        if ( ! defined( 'OWA_DB_PORT' ) ) {
            define('OWA_DB_PORT', $this->getParam( 'db_port' ) );
        }

        if ( ! defined( 'OWA_DB_NAME' ) ) {
            define('OWA_DB_NAME', $this->getParam( 'db_name' ) );
        }

        if ( ! defined( 'OWA_DB_USER' ) ) {
            define('OWA_DB_USER', $this->getParam( 'db_user' ) );
        }

        if ( ! defined( 'OWA_DB_PASSWORD' ) ) {
            define('OWA_DB_PASSWORD', $this->getParam( 'db_password' ) );
        }

        owa_coreAPI::setSetting('base', 'db_type', OWA_DB_TYPE);
        owa_coreAPI::setSetting('base', 'db_host', OWA_DB_HOST);
        owa_coreAPI::setSetting('base', 'db_port', OWA_DB_PORT);
        owa_coreAPI::setSetting('base', 'db_name', OWA_DB_NAME);
        owa_coreAPI::setSetting('base', 'db_user', OWA_DB_USER);
        owa_coreAPI::setSetting('base', 'db_password', OWA_DB_PASSWORD);

        // Check DB connection status
        $db = owa_coreAPI::dbSingleton();
        $db->connect();
        if ($db->connection_status != true) {
            $this->set('error_msg', $this->getMsg(3012));
            $this->set('config', $this->params);
            $this->setView('base.install');
            $this->setSubview('base.installConfigEntry');

        } else {
            //create config file
            $this->c->createConfigFile($this->params);
            $this->setRedirectAction('base.installDefaultsEntry');
        }

        // Check socket connection

        // Check permissions on log directory


        return;
    }

    function errorAction() {
        $this->set('config', $this->params);
        $this->setView('base.install');
        $this->setSubview('base.installConfigEntry');
    }
}

?>