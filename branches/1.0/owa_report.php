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

require_once 'owa_env.php';
require_once 'owa_template.php';
require_once 'owa_settings_class.php';
require_once 'owa_api.php';
require_once 'owa_lib.php';
require_once 'owa_site.php';

/**
 * Web Analytics Report  
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_report {
	
	/**
	 * Template
	 *
	 * @var object
	 */
	var $tpl;
	
	/**
	 * Metrics
	 *
	 * @var array
	 */
	var $metrics;
	
	/**
	 * Reporting Period
	 *
	 * @var string
	 */
	var $period;
	
	/**
	 * Display Label for Reporting Period
	 *
	 * @var string
	 */
	var $period_label;
	
	/**
	 * Display Label for Reporting Date
	 *
	 * @var string
	 */
	var $date_label;
	
	/**
	 * Display Label for 2nd Reporting Date
	 *
	 * @var string
	 */
	var $date_label_2;
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Report generation params
	 *
	 * @var array
	 */
	var $params = array();
	
	/**
	 * User Display Preferences
	 *
	 * @var array
	 */
	var $prefs = array();
	
	/**
	 * Constructor
	 *
	 * @access 	public
	 * @return 	owa_report
	 */
	function owa_report() {
		
		$this->config = &owa_settings::get_settings();
		
		// Gets full set of params from URL
		$this->_setParams(owa_lib::getRestparams());

		// Get default and user override display preferences.
		$this->prefs = $this->getPrefs();	
		
		$this->tpl = & new owa_template($this->params);
		$this->tpl->set_template($this->config['report_wrapper']);
		$this->metrics = owa_api::get_instance('metric');
		
		// Get Reporting Periods
		$this->tpl->set('reporting_periods', owa_lib::reporting_periods());
		$this->tpl->set('date_reporting_periods', owa_lib::date_reporting_periods());
		$this->tpl->set('months', owa_lib::months());
		$this->tpl->set('days', owa_lib::days());
		$this->tpl->set('years', owa_lib::years());
		
		$this->set_period($this->params['period']);
		$this->date_label = $this->setDateLabel($this->params);
		$this->date_label_2 = $this->setDateLabel($this->params);
	
		$this->tpl->set('params', $this->params);
		$this->tpl->set('sites', $this->getSitesList());
		$this->tpl->set('date_label', $this->date_label);
		$this->tpl->set('page_type', 'report');
		$this->tpl->set('period_filter', true);
		return;
	}
	
	/**
	 * Gets the default report display preferences and then
	 * applies user overrides from cookie.
	 * 
	 */
	function getPrefs() {
		
		$this->params['limit'] = 50;
		
		return;
	}
	
	
	/**
	 * Set report period
	 *
	 * @access public
	 * @param string $period
	 */
	function set_period($period) {
		
		if (empty($period)):
			$period = 'today';
		endif;
		$this->period = $period;
		$this->params['period'] = $period;
		$this->period_label = $this->get_period_label($period);
		
		return;
	}

	/**
	 * Lookup report period label
	 *
	 * @param string $period
	 * @access private
	 * @return string $label
	 */
	function get_period_label($period) {
	
		return owa_lib::get_period_label($period);
	}
	
	
	
	function setDateLabel($params) {

		return owa_lib::getDateLabel($params);
		
	}
	
	/**
	 * Applies calling params
	 *
	 * @access 	private
	 * @param 	array $properties
	 */
	function _setParams($params = null) {
	
		if(!empty($params)):
			foreach ($params as $key => $value) {
				if(!empty($value)):
					$this->params[$key] = $value;
				endif;
			}
		endif;
		
		return;	
	}
	
	function getSitesList() {
		
		$sites = new owa_site;
		return $sites->getAllSites();
		
	}
	
}

?>
