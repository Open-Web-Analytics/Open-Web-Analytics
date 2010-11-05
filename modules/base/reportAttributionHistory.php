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

require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Attribution History Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_reportAttributionHistoryController extends owa_reportController {
	
	function action() {
			
		$this->setSubview('base.reportDimension');
		$this->setTitle('Attribution History');
		
		$this->set('dimensions', 'latestAttributions');
		$this->set('sort', 'visits-');
		$this->set('resultsPerPage', 25);
		/*
$this->set('dimensionLink', array(
				'linkColumn' => 'ad', 
				'template' => array('do' => 'base.reportAdDetail', 'ad' => '%s'), 
				'valueColumns' => 'ad'));
			
*/	
		$this->set('trendChartMetric', 'visits');
		$this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.visits.formatted_value *> visits for all ads.');
		$this->set('gridFormatters', array('latestAttributions' =>
				"function(value) {
					var new_value = '';
					if (value) {
						value = JSON.parse(value);
					}
					for (var i=0; i < value.length; i++) {
						for (prop in value[i]) {
							if (prop === 'ts') {
								value[i][prop] = new Date(value[i][prop]*1000);
							}
							new_value += OWA.util.sprintf('<B>%s</B>: %s ',prop,value[i][prop]);
						}
						new_value += '<BR>';
					}			
					return new_value;
				}
				"
		));	
		$this->set('gridFormatters', array('latestAttributions' =>
				"function(value) {
					if (value) {
						table = jQuery('#attributionCell').jqote(JSON.parse(value), '*');
						return table;
					}
				}
				"
		));	
	}
}


?>