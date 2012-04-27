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

require_once(OWA_BASE_CLASS_DIR.'widget.php');
require_once(OWA_BASE_DIR.'/owa_news.php');

/**
 * OWA News Widget Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_widgetOwaNewsController extends owa_widgetController {

	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
		
		$this->set('title', 'OWA News');
		
		//$data['params'] = $this->params;
		
		//Fetch latest OWA news
		$rss = new owa_news;
		//print_r($this->config);
		$news = $rss->Get($this->config['owa_rss_url']);
		$this->set('news', $news);
		$this->setView('base.widgetOwaNews');
	}
	
}

class owa_widgetOwaNewsView extends owa_view {

	function render($data) {

		$this->t->set_template('wrapper_blank.tpl');		
		$this->body->set_template('news.tpl');
		$this->body->set('news', $data['news']);
	}

}

?>