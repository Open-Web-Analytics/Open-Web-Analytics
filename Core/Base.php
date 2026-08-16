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

namespace OWA\Core;

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

// TODO: replace with explicit property declarations; kept for now because the
// owa_base hierarchy relies on dynamic properties (deprecated in PHP 8.2).
#[\AllowDynamicProperties]
class Base {

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
     * @var \owa_settings  Object global configuration object
     */
    var $c;

    /**
     * Module that this class belongs to
     *
     * @var string
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
     */
    function __construct() {
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        $this->e = \OWA\Core\CoreAPI::errorSingleton();
        $this->c = \OWA\Core\CoreAPI::configSingleton();
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
			
			// Substitutions are keyed by the message part they fill in, and each
			// value is that part's vsprintf() argument list. A caller that fills
			// in only one part must leave the other one untouched, and a caller
			// passing a bare scalar means a single argument -- handing either of
			// those straight to vsprintf() is a TypeError on PHP 8.
			foreach ( ['headline', 'message'] as $part ) {

				if ( isset( $msg[ $part ] ) && isset( $substitutions[ $part ] ) ) {

					$msg[ $part ] = vsprintf( $msg[ $part ], (array) $substitutions[ $part ] );
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
     * @param array $array
     */
    function _setObjectValues($array) {

        foreach ($array as $n => $v) {

                $this->$n = $v;

            }

        return;
    }

    function __destruct() {
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
    }

}

?>