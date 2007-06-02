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
 * DB Configuration
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
 
define('OWA_DB_TYPE', ''); // options: mysql
define('OWA_DB_NAME', ''); // name of the database
define('OWA_DB_HOST', ''); // host name of the server housing the database
define('OWA_DB_USER', ''); // database user
define('OWA_DB_PASSWORD', ''); // database user's password

// define the URL of the public directory e.g. http://www.domain.com/root/dir/owa/public/ 
// Don't forget the slash at the end.
define('OWA_PUBLIC_URL', ''); 

// Log all php errors to OWA's error log file. Only do this to debug.
define('OWA_LOG_PHP_ERRORS', false);
 
?>