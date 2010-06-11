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

require_once(OWA_BASE_DIR.'/owa_view.php');

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
	
	function __construct() {
		
		$this->default_subview = 'base.optionsGeneral';
		
		return parent::__construct();
	}
	
	function render($data) {
		
		//page title
		$this->t->set('page_title', 'OWA Options');
		
		// load body template
		$this->body->set_template('options.tpl');
		
		// fetch admin links from all modules
		// need api call here.
		$this->body->set('headline', 'OWA Configuration Options');
		
		// get admin panels
		$api = &owa_coreAPI::singleton();
		$panels = $api->getAdminPanels();
		//print_r($panels);
		$this->body->set('panels', $panels);
		
		// Assign config data
		$this->body->set('config', $this->config);
		$this->setJs("jquery", "base/js/includes/jquery/jquery-1.2.6.min.js", '1.2.6');
		$this->setJs("sprinf", "base/js/includes/jquery/jquery.sprintf.js", '', array('jquery'));
		$this->setJs("jquery-ui", "base/js/includes/jquery/jquery-ui-personalized-1.5.2.min.js", '1.5.2', array('jquery'));
		$this->setJs("tablesorter", "base/js/includes/jquery/tablesorter/jquery.tablesorter.js", '', array("jquery"));
		$this->setJs("owa", "base/js/owa.js");
		$this->setCss('base/js/includes/jquery/tablesorter/themes/blue/style.css');
		
	}
}

?>