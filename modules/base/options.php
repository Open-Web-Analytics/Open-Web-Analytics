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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_news.php');
require_once(OWA_BASE_DIR.'/owa_coreAPI.php');

/**
 * Options View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_optionsView extends owa_view {
	
	function owa_OptionsView() {
		
		$this->owa_view();
		$this->priviledge_level = 'admin';
		$this->default_subview = 'base.optionsGeneral';
		
		return;
	}
	
	function construct($data) {
		
		
		
		//page title
		$this->t->set('page_title', 'OWA Options');
		
		// load body template
		$this->body->set_template('options.tpl');
		
		// fetch admin links from all modules
		// need api call here.
		$this->body->set('headline', 'OWA Configuration Options');
		
		//Fetch latest OWA news
		$rss = new owa_news;
		//print_r($this->config);
		$news = $rss->Get($this->config['owa_rss_url']);
		$this->body->set('news', $news);
		
		// get admin panels
		$api = &owa_coreAPI::singleton();
		$panels = $api->getAdminPanels();
		//print_r($panels);
		$this->body->set('panels', $panels);
		
		// Assign config data
		$this->body->set('config', $this->config);
		
		return;
	}
	
	
}


?>