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

require_once('owa_env.php');

/**
 * OWA Base Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_base {

    /**
     * Configuration
     *
     * @var array
     */
    var $config;

    /**
     * Error Logger
     *
     * @var object
     */
    var $e;

    /**
     * Configuration Entity
     *
     * @var owa_settings  Object global configuration object
     */
    var $c;

    /**
     * Module that this class belongs to
     *
     * @var unknown_type
     */
    var $module;

    /**
     * Request Params
     *
     * @var array
     */
    var $params;

    /**
     * Base Constructor
     *
     * @return owa_base
     */
    function __construct() {
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
        $this->e = owa_coreAPI::errorSingleton();
        $this->c = owa_coreAPI::configSingleton();
        $this->config = $this->c->fetch('base');
    }

    /**
     * Retrieves string message from mesage file
     *
     * @param integer $code
     * @param array $substitutions
     * @return array
     */
    function getMsg($code, $substitutions = []) {

        static $_owa_messages;

        $msg = array();

        if (empty($_owa_messages)) {
            require(OWA_DIR.'conf/messages.php');
        }

        if ( $code && array_key_exists( $code, $_owa_messages ) ) {

            $msg = $_owa_messages[$code];
			
			if ( $substitutions ) {
	            if (isset($msg['headline'])) {
	                $msg['headline'] = vsprintf($msg['headline'], $substitutions['headline']);
	            }
	
	            if (isset($msg['message'])) {
	                $msg['message'] = vsprintf($msg['message'], $substitutions['message']);
	            }
	        }
        }

        return $msg;
    }

    /**
     * @param $code
     * @param array $substitutions
     * @return string
     */
    public function getMsgAsString($code, $substitutions = [])
    {
        $msg = $this->getMsg($code, $substitutions);

        return implode(' ', array_values($msg));
    }

    /**
     * Sets object attributes
     *
     * @param unknown_type $array
     */
    function _setObjectValues($array) {

        foreach ($array as $n => $v) {

                $this->$n = $v;

            }

        return;
    }

    /**
     * Sets array attributes
     *
     * @param unknown_type $array
     */
    function _setArrayValues($array) {

        foreach ($array as $n => $v) {

                $this->params['$n'] = $v;

            }

        return;
    }

    function __destruct() {
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
    }

}

?>