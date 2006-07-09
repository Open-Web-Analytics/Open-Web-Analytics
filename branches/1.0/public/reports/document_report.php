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
 * Document Report
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
$body->set_template('document.tpl');// This is the inner template

$top_referers = & new owa_template($report->params); 
$top_referers->set_template('top_referers.tpl');// This is the inner template

// Fetch Metrics

switch ($report->params['period']) {

	case "this_year":
		$core_metrics_data = $report->metrics->get(array(
			'api_call' 		=> 'document_core_metrics',
			'request_params'	=>	$report->params,
			'period'			=> $report->period,
			'result_format'		=> 'assoc_array',
			'constraints'		=> array(
				'site_id'	=> $report->params['site_id'],
				'document_id' => $report->params['document_id']
				),
			'group_by'			=> 'month'
		
		));
		
	break;
	
	default:
		$core_metrics_data = $report->metrics->get(array(
		'api_call' 		=> 'document_core_metrics',
		'request_params'	=>	$report->params,
		'period'			=> $report->params['period'],
		'result_format'		=> 'assoc_array',
		'constraints'		=> array(
			'site_id'	=> $report->params['site_id'],
			'document_id' => $report->params['document_id']
			),
		'group_by'			=> 'day'
	
	));
	break;
}

$summary_stats_data = $report->metrics->get(array(
	'api_call' 		=> 'count_document_metrics',
	'request_params'	=>	$report->params,
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'document_id' => $report->params['document_id']
		)

));

$document_details = $report->metrics->get(array(
	'api_call' 		=> 'document_details',
	'request_params'	=>	$report->params,
	'result_format'		=> 'assoc_array',
	'document_id' => $report->params['document_id']
));

$document_referers = $report->metrics->get(array(
	'api_call' 			=> 'top_referers',
	'request_params'	=>	$report->params,
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id'],
		'sessions.first_page_id'	=> $report->params['document_id']
		),
	'limit'				=> 30
));

//print_r($document_referers);

// Assign Data to templates

$body->set('headline', 'Document');
$body->set('period_label', $report->period_label);
$body->set('data', $core_metrics_data);
$body->set('summary', $summary_stats_data);
$body->set('detail', $document_details);

$top_referers->set('data', $document_referers);
$body->set('top_referers', $top_referers);

// Global Template Assignments
$body->set('params', $report->params);
$report->tpl->set('report_name', basename(__FILE__));
$report->tpl->set('content', $body);

//Output Report
echo $report->tpl->fetch();

?>