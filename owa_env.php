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
define('OWA_VERSION', 'master');
define('OWA_VENDOR_DIR', OWA_DIR.'vendor/');

if ( file_exists( OWA_VENDOR_DIR . 'autoload.php' ) ) {

	require_once ( OWA_VENDOR_DIR . 'autoload.php' );

} else {

	/*
	 * No vendor/ -- a source checkout, or the GitHub "Source code" archive
	 * rather than the packaged release tarball.
	 *
	 * OWA's own classes are loaded by Composer's PSR-4 map, so without an
	 * autoloader the very next file fatals on `class owa extends
	 * \OWA\Core\Caller` and every request answers with a blank 500. That
	 * included install.php, whose environment check exists precisely to say
	 * "Dependencies: missing -- run composer install": it could never run.
	 *
	 * This registers the same two PSR-4 prefixes composer.json declares, for
	 * OWA's own code only. It deliberately does NOT stand in for the packages
	 * in vendor/; it boots far enough to reach the screen that explains they
	 * are missing. The one package on that path is Monolog, which
	 * modules/Base/Classes/Error.php now builds lazily for this reason.
	 */
	spl_autoload_register( function ( $class ) {

		$prefixes = array(
			'OWA\\Core\\'   => OWA_DIR . 'Core/',
			'OWA\\Module\\' => OWA_DIR . 'modules/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {

			if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {

				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {

				require_once $file;
			}

			return;
		}
	} );
}

// Backward-compat bridge for the PSR-4 namespace migration. Registers a LAZY
// forward-alias autoloader AFTER Composer's so migrated classes keep resolving
// by their legacy owa_* names. The renames are complete and its map is
// populated; the bridge stays until the v2.0 deprecation window closes. See
// owa_compat_aliases.php for the full rationale.
require_once ( OWA_DIR . 'owa_compat_aliases.php' );

?>
