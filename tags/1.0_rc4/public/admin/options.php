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

//include_once('../set_env.php');
require_once(OWA_BASE_DIR.'/owa_php.php');
require_once(OWA_BASE_DIR.'/owa_template.php');
require_once(OWA_BASE_DIR.'/owa_site.php');
require_once(OWA_BASE_DIR.'/owa_news.php');
/**
 * OWA Options Admin interface
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

// Create Template Objects
$page = & new owa_template;
$body = & new owa_template; 

$body_tpl = 'options.tpl';// This is the inner template
$body->set('page_title', 'OWA Options');

switch ($_GET['owa_page']) {
	
	case "manage_sites":
		$body_tpl = 'options_manage_sites.tpl';
		$site = new owa_site;
		$sites = $site->getAllSites();
		$body->set('sites', $sites);
		break;
	
}

switch ($_POST['action']) {
	
	case "add_site":
		
		$site = new owa_site;
		$site->name = $_POST['name'];
		$site->description = $_POST['description'];
		$site->site_family = $_POST['site_family'];
		$site_id = $site->addNewSite();
		
		if ($site_id != false):
			$status_msg = "Site added Successfully";
			$body_tpl = 'options_new_site_success.tpl';
			$page_h1 = 'Your new site is ready to be tracked';
			$body->set('site_id', $site_id);
			$tag = $owa->requestTag($site_id);
			$body->set('tag', $tag);
		else:
			$page_h1 = 'Error';
			$body_tpl = 'error.tpl';
			$status_msg = "Site could not be added. Perhaps a site by that name already exists.";
		endif;
		break;

	case "update_config":
						
		$owa->save_config($_POST);
		break;
		
	case "reset_config":
		
		$owa->reset_config();	
		break;
		
}

switch ($_GET['action']) {
	
	case "get_tag":
		$status_msg = "";
			$body_tpl = 'options_new_site_success.tpl';
			$page_h1 = 'The tracking tag for your site is below.';
			
			$body->set('site_id', $_GET['site_id']);
			$tag = $owa->requestTag($site_id);
			$body->set('tag', $tag);
		
		break;
}

//Fetch latest OWA news
$rss = new owa_news;
$news = $rss->Get($rss->config['owa_rss_url']);

// Global Template assignments
$page->set_template($owa->config['report_wrapper']);// This is the outer template
$page->set('news', $news);
$body->set_template($body_tpl);// This is the inner template
$body->set('config', $owa->config);
$body->set('status_msg', $status_msg);
$body->set('page_h1', $page_h1);
$page->set('content', $body);

// Render Page
echo $page->fetch();

?>