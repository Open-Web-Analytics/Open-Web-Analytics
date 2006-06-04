<?

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
require_once(OWA_BASE_DIR.'/owa_php.php');
require_once(OWA_BASE_DIR.'/owa_template.php');

/**
 * OWA Installation Script
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

$owa = new owa_php;
$page = & new owa_template;
$body = & new owa_template; 

//Default page settings
$body_tpl = 'install.tpl';
$page->set('page_title', 'Installation');
$body->set('page_h1', 'Welcome to the OWA Installation Guide');

/////////// handler Overrides ///////////

switch ($_POST['action']) {
	
	// Base Schema Installation
	case "install":
		
		$install_check = $owa->install('base');
	
		if ($install_check == true):
			$body->set('install_status', 'The installation was a success.');
		else:
			$body->set('install_status', 'The installation failed. See error log for details.');
		endif;
		$body->set('page_h1', 'Installation Complete ');
		$body_tpl = 'install_sucess.tpl';
		
		break;
	
	
}

// Global Template assignments
$page->set_template('default_wrap.tpl');// This is the outer template
$body->set_template($body_tpl);// This is the inner template
$body->set('config', $owa->config);
$page->set('content', $body);

// Render Page
echo $page->fetch();

?>