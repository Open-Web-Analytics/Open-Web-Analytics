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
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
if (!defined('OWA_PATH')) {
	define('OWA_PATH', dirname(__FILE__));
}
define('OWA_DIR', OWA_PATH. DIRECTORY_SEPARATOR);
define('OWA_MODULES_DIR', OWA_DIR.'modules'.DIRECTORY_SEPARATOR);
define('OWA_BASE_DIR', OWA_PATH); // depricated
define('OWA_BASE_CLASSES_DIR', OWA_DIR); //depricated
define('OWA_BASE_MODULE_DIR', OWA_DIR.'modules'.DIRECTORY_SEPARATOR.'base'.DIRECTORY_SEPARATOR);
define('OWA_BASE_CLASS_DIR', OWA_BASE_MODULE_DIR.'classes'.DIRECTORY_SEPARATOR);
define('OWA_INCLUDE_DIR', OWA_DIR.'includes'.DIRECTORY_SEPARATOR);
//define('OWA_PEARLOG_DIR', OWA_INCLUDE_DIR.'Log-1.11.5');
define('OWA_PEARLOG_DIR', OWA_INCLUDE_DIR.'Log-1.12.2');
define('OWA_PHPMAILER_DIR', OWA_INCLUDE_DIR.'PHPMailer_v2.0.3'.DIRECTORY_SEPARATOR);
define('OWA_HTTPCLIENT_DIR', OWA_INCLUDE_DIR.'httpclient-2009-09-02'.DIRECTORY_SEPARATOR);
define('OWA_SPARKLINE_DIR', OWA_INCLUDE_DIR.'sparkline-php-0.2'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR);
define('OWA_JPGRAPH_DIR', OWA_INCLUDE_DIR.'jpgraph-1.20.3'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR); //depricated
define('OWA_OFC_VERSION', 'open-flash-chart-2-Lug-Wyrm-Charmer');
define('OWA_OFC_DIR', OWA_INCLUDE_DIR.OWA_OFC_VERSION.DIRECTORY_SEPARATOR);
define('OWA_PLUGINS_DIR', OWA_DIR.'plugins'); //depricated
define('OWA_METRICS_DIR', OWA_DIR.'plugins'.DIRECTORY_SEPARATOR.'metrics'.DIRECTORY_SEPARATOR); //depricated
define('OWA_PLUGIN_DIR', OWA_DIR.'plugins'.DIRECTORY_SEPARATOR);
define('OWA_CONF_DIR', OWA_DIR.'conf'.DIRECTORY_SEPARATOR);
if(!defined('OWA_DATA_DIR')){
	define('OWA_DATA_DIR', OWA_DIR.'owa-data'.DIRECTORY_SEPARATOR);
}
define('OWA_CACHE_DIR', OWA_DATA_DIR.'caches'.DIRECTORY_SEPARATOR);
define('OWA_THEMES_DIR', OWA_DIR.'themes'.DIRECTORY_SEPARATOR);
define('OWA_VERSION', '1.4.0rc2');
?>
