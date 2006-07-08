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

require_once(OWA_BASE_DIR.'/owa_report.php');

/**
 * Visitors Report
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

$report = new owa_report;	
	
// Setup the templates

$body = & new owa_template($report->params); 

switch ($_GET['owa_page']) {
	
	case "visitor_list":
		
		$visitors_list = $report->metrics->get(array(
			'request_params'	=> $report->params,
			'api_call' 			=> 'visitor_list',
			'result_format'		=> 'assoc_array',
			'period'			=> $report->params['period'],
			'constraints'		=> array(
				'site_id'		=> $report->params['site_id'],
				'visitors.first_session_year'		=> $report->params['year2'],
				'visitors.first_session_month'		=> $report->params['month2']
				),
			'limit' 			=> $report->params['limit']
		));
		$body->set_template('visitors_list.tpl');// This is the inner template
		$body->set('headline', 'Visitors for \''.$report->period_label.'\' who first visited in '.$report->date_label_2);
		$body->set('visitors_list', $visitors_list);
		break;
		
	default:

		// Fetch Metrics
		
		$visitors_age = $report->metrics->get(array(
			'request_params'	=> $report->params,
			'api_call' 			=> 'visitors_age',
			'period'			=> $report->params['period'],
			'result_format'		=> 'assoc_array',
			'constraints'		=> array(
				'site_id'		=> $report->params['site_id']
				),
			'limit' 			=> $report->params['limit']
		));
		
		// Template Assingments
		$body->set_template('visitors.tpl');// This is the inner template
		$body->set('headline', 'Visitors');
		$body->set('visitors_age', $visitors_age);
}


// Assign Data to templates

$body->set('period_label', $report->period_label);
$body->set('date_label', $report->date_label);
$body->set('params', $report->params);
$report->tpl->set('content', $body);

//Set Report File name
$report->tpl->set('report_name', basename(__FILE__));

//Output Report
echo $report->tpl->fetch();



?>