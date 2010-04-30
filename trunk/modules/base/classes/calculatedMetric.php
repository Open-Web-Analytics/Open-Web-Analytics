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

/**
 * Abstract Calculated Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_calculatedMetric extends owa_metric {
	
	var $is_calculated = true;
	var $child_metrics = array();
	var $formula;
	
	function setChildMetric($name) {
		
		$this->child_metrics[] = $name;
	}
	
	function getChildMetrics() {
		
		return $this->child_metrics;
	}	
	
	function setFormula($string) {
		
		$this->formula = $string;
	}
	
	function getFormula() {
	
		return $this->formula;
	}	
}

?>