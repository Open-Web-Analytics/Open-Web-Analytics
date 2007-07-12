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

include_once('set_env.php');
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_php.php');

/**
 * Install Page Wrapper Script
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Initialize owa admin
$config['do_not_fetch_config_from_db'] = true;
$config['main_url'] = 'install.php';
$owa = new owa_php($config);

// Santize input
$params = owa_lib::inputFilter($_REQUEST);

// run controller or view and echo page content

if (empty($params['view'])):
	$params['view'] = 'base.install';
endif;

echo $owa->handleRequest($params);

// unload owa

?>