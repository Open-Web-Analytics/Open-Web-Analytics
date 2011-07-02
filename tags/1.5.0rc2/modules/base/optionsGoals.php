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

require_once(OWA_DIR.'owa_view.php');
require_once(OWA_DIR.'owa_adminController.php');

/**
 * Goals Roster Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_optionsGoalsController extends owa_adminController {
	
	function __construct($params) {
	
		parent::__construct($params);
		$this->type = 'options';
		$this->setRequiredCapability('edit_settings');
	}
	
	function action() {
		
		$siteId = $this->get('siteId');
		$gm = owa_coreAPI::supportClassFactory('base', 'goalManager', $siteId);
		$goals = $gm->getAllGoals();
		$goal_groups = $gm->getAllGoalGroupLabels();
		$this->set('goals', $goals);
		$this->set('goal_groups', $goal_groups);
		$this->setView('base.options');
		$this->setSubView('base.optionsGoals');
		$this->set('siteId', $siteId);
	}
}

/**
 * Goals Roster View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_optionsGoalsView extends owa_view {
		
	function render($data) {
		
		// load template
		$this->body->set_template( 'options_goals.tpl' );
		// fetch admin links from all modules
		$this->body->set( 'headline', 'Conversion Goals');
		$this->body->set( 'goals', $this->get( 'goals' ) );
		$this->body->set( 'goal_groups', $this->get( 'goal_groups' ) );
		$this->body->set( 'siteId', $this->get( 'siteId' ) );
	}
}

?>