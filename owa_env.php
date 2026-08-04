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

/**
 * Environment Configuration
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
  
if (!defined('OWA_PATH')) {
    define('OWA_PATH', dirname(__FILE__));
}
define('OWA_DIR', OWA_PATH . '/');
define('OWA_DATA_DIR', OWA_DIR . 'owa-data/');
define('OWA_MODULES_DIR', OWA_DIR.'modules/');
define('OWA_BASE_DIR', OWA_PATH); // depricated
define('OWA_BASE_CLASSES_DIR', OWA_DIR); //depricated
define('OWA_BASE_MODULE_DIR', OWA_DIR.'modules/Base/'); // PSR-4: base module dir is PascalCase on disk
define('OWA_BASE_CLASS_DIR', OWA_BASE_MODULE_DIR.'Classes/');
define('OWA_PLUGIN_DIR', OWA_DIR.'plugins/');
define('OWA_CONF_DIR', OWA_DIR.'conf/');
define('OWA_THEMES_DIR', OWA_DIR.'themes/');
define('OWA_VERSION', '1.10.1');
define('OWA_VENDOR_DIR', OWA_DIR.'vendor/');

if ( file_exists( OWA_VENDOR_DIR . 'autoload.php' ) ) {

	require_once ( OWA_VENDOR_DIR . 'autoload.php' );
}

// Backward-compat bridge for the PSR-4 namespace migration (Phase 6). Registers
// a LAZY forward-alias autoloader AFTER Composer's so migrated classes keep
// resolving by their legacy owa_* names. Inert until renames begin (its map is
// empty at stage 1). See owa_compat_aliases.php for the full rationale.
require_once ( OWA_DIR . 'owa_compat_aliases.php' );

?>
