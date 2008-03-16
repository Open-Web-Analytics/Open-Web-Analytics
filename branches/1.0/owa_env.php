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

define('OWA_DIR', dirname(__FILE__). DIRECTORY_SEPARATOR);
define('OWA_BASE_DIR', dirname(__FILE__)); // depricated
define('OWA_BASE_CLASSES_DIR', dirname(__FILE__). DIRECTORY_SEPARATOR); //depricated
define('OWA_BASE_MODULE_DIR', OWA_DIR.'modules'.DIRECTORY_SEPARATOR.'base'.DIRECTORY_SEPARATOR);
define('OWA_BASE_CLASS_DIR', OWA_BASE_MODULE_DIR.'classes'.DIRECTORY_SEPARATOR);
define('OWA_INCLUDE_DIR', OWA_DIR.'includes'.DIRECTORY_SEPARATOR);
define('OWA_PEARLOG_DIR', OWA_INCLUDE_DIR.'Log-1.9.13');
define('OWA_PHPMAILER_DIR', OWA_INCLUDE_DIR.'phpmailer-1.73'.DIRECTORY_SEPARATOR);
define('OWA_JPGRAPH_DIR', OWA_INCLUDE_DIR.'jpgraph-1.20.3'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR);
define('OWA_PLUGINS_DIR', OWA_DIR.'plugins'); //depricated
define('OWA_METRICS_DIR', OWA_DIR.'plugins'.DIRECTORY_SEPARATOR.'metrics'.DIRECTORY_SEPARATOR); //depricated
define('OWA_GRAPHS_DIR', OWA_DIR.'plugins'.DIRECTORY_SEPARATOR.'graphs'.DIRECTORY_SEPARATOR); //depricated
define('OWA_PLUGIN_DIR', OWA_DIR.'plugins'.DIRECTORY_SEPARATOR);
define('OWA_CONF_DIR', OWA_DIR.'conf'.DIRECTORY_SEPARATOR);
define('OWA_DATA_DIR', OWA_DIR.'owa-data'.DIRECTORY_SEPARATOR);
define('OWA_CACHE_DIR', OWA_DATA_DIR.'caches'.DIRECTORY_SEPARATOR);
define('OWA_THEMES_DIR', OWA_DIR.'themes'.DIRECTORY_SEPARATOR);
define('OWA_VERSION', '1.1.0');
?>