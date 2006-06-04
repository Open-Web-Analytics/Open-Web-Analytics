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

$owa = new owa_php;
$page = & new owa_template;
$body = & new owa_template; 

print_r($_POST);

//Default page settings
$body_tpl = 'install.tpl';
$page->set('page_title', 'Installation');
$body->set('page_h1', 'Welcome to the OWA Installation Guide');

/////////// handler Over-rides

// Base Installation
if($_POST['action'] == 'install'):

	$install_check = $owa->install('base');
	
	if ($install_check == true):
		$body->set('install_status', 'The installation was a success.');
	else:
		$body->set('install_status', 'The installation failed. See error log for details.');
	endif;
	$body->set('page_h1', 'Installation Complete ');
	$body_tpl = 'install_sucess.tpl';

endif;

// Global Template assignments
$page->set_template('default_wrap.tpl');// This is the inner template
$body->set_template($body_tpl);// This is the inner template
$body->set('config', $owa->config);
$page->set('content', $body);

// Render Page
echo $page->fetch();

?>