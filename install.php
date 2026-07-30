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

include_once('owa_env.php');
require_once(OWA_BASE_DIR.'/owa.php');

/**
 * Install Page Wrapper Script
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

// Initialize owa
//define('OWA_ERROR_HANDLER', 'development');
define('OWA_CACHE_OBJECTS', false);
define('OWA_INSTALLING', true);

// On a fresh install there is no owa-config.php yet, so OWA_DB_TYPE is undefined.
// Booting OWA constructs the base.configuration entity, whose column types
// reference OWA_DTD_BIGINT/OWA_DTD_BLOB -- constants define()d by the DB driver
// (plugins/db/owa_db_<type>.php), which entityFactory() only loads when
// OWA_DB_TYPE is set. Without it the driver never loads and the constructor
// fatals on an undefined constant (PHP 8), so install.php dies with a blank 500
// before the wizard can even render. Pre-define the type here so the storage
// engine loads and the DTD constants exist. Gated on the config file being
// absent so a normal post-install load is untouched (the config file's own
// unguarded define('OWA_DB_TYPE', ...) stays the single source of truth). No DB
// connection is attempted at boot in this state -- owa_caller gates that on
// isConfigFilePresent(). Mirrors the same guard in tests/bootstrap.php.
if ( ! defined( 'OWA_DB_TYPE' ) && ! file_exists( OWA_DIR . 'owa-config.php' ) ) {
    define( 'OWA_DB_TYPE', 'mysql' );
}

$config = [

    'instance_role' => 'installer'
];

$owa = new owa( $config );
if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    // need third param here so that seting is not persisted.
    $owa->setSetting('base','main_url', 'install.php');
    // run controller, echo page content
    $do = \OWA\Core\CoreAPI::getRequestParam('do');
    $params = array();
    if (empty($do)) {

        $params['do'] = 'base.installStart';
    }

    // run controller or view and echo page content
    echo $owa->handleRequest($params);

} else {
    // unload owa
    $owa->restInPeace();
}

?>