<?php
namespace OWA\Core;


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
 * Install Abstract Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class Install extends \OWA\Core\Base{

    /**
     * Data access object
     *
     * @var object
     */
    var $db;

    /**
     * Version of string
     *
     * @var string
     */
    var $version;

    /**
     * Params array
     *
     * @var array
     */
    var $params;

    /**
     * Module name
     *
     * @var mixed
     */
    var $module;

    /**
     * Constructor
     *
     * @return \OWA\Core\Install
     */

    function __construct() {

        parent::__construct();
        $this->db = \OWA\Core\CoreAPI::dbSingleton();
    }

}

?>