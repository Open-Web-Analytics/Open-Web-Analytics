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

require_once (OWA_BASE_CLASSES_DIR . 'owa_base.php');
require_once (OWA_BASE_CLASSES_DIR . 'owa_lib.php');

/**
 * Graph Generator
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_graph extends owa_base {

	/**
	 * Current Time
	 *
	 * @var array
	 */
	var $time_now;
	
	/**
	 * Graph Data
	 *
	 * @var array
	 */
	var $data = array();
	
	/**
	 * Graph Parameters
	 *
	 * @var array
	 */
	var $params = array();
	
	/**
	 * Graph Height
	 *
	 * @var integer
	 */
	var $height = 200;
	
	/**
	 * Graph Width
	 *
	 * @var integer
	 */
	var $width = 400;
	
	var $size = .3;
	
	/**
	 * Image Format
	 *
	 * @var string
	 */
	var $image_format = "jpeg";
	
	/**
	 * Constructor
	 *
	 * @return owa_graph
	 * @access public
	 */
	function owa_graph() {
		
		// Set current time
		$this->owa_base();
		$this->time_now = owa_lib::time_now();
		
		return;
	}
	
	/**
	 * Get Display Label for Reporting Period
	 *
	 * @param string $period
	 * @return string $label
	 * @access public
	 */
	function get_period_label($period) {
		
		return owa_lib::get_period_label($period);
	}
	
	/**
	 * makes linear date scale for x axis
	 *
	 * @param array $variable
	 * @param string $label
	 * @param string $delim
	 * @return array
	 */
	function make_date_label($variable, $label, $delim = '/') {
	
		$date = array();
		foreach ($variable as $key => $value) {
					
					$date[$key] = $label[$key].$delim.$value;
					
				}
		
		return $date;
	}
	
	function get_month_label($month) {
		
		return owa_lib::get_month_label($month);
	}
	
	

}

?>