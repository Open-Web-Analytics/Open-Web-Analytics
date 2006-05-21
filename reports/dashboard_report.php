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

include dirname(__FILE__).'/../owa_env.php';
require_once(OWA_BASE_DIR.'/owa_report.php');

$report = new owa_report;

// Set the reporting period

if (!empty($_POST['period'])):
	$report->set_period($_POST['period']);
else:
	$report->set_period('today');
endif;
		
// Setup the templates

$body = & new Template; 
$body->set_template('index.tpl');// This is the inner template

$visit = & new Template; 
$visit->set_template('visit.tpl');// This is the inner template

$top_pages = & new Template;
$top_pages->set_template('top_pages.tpl');

// Fetch metrics

switch ($report->period) {

	case "this_year":
		$dash_result = $report->metrics->get(array(
			'api_call' 		=> 'dash_core',
			'period'			=> $report->period,
			'result_format'		=> 'assoc_array',
			'constraints'		=> array(
				
				'is_browser' => 1,
				'is_robot' 	=> 0),
			'group_by'			=> 'month'
		
		));
		
	break;
	
	default:
		$dash_result = $report->metrics->get(array(
		'api_call' 		=> 'dash_core',
		'period'			=> $report->period,
		'result_format'		=> 'assoc_array',
		'constraints'		=> array(
			
			'is_browser' => 1,
			'is_robot' 	=> 0),
		'group_by'			=> 'day'
	
	));
	break;
}

$dash_counts = $report->metrics->get(array(
	'api_call' 		=> 'dash_counts',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		
		'is_browser' => 1,
		'is_robot' 	=> 0
		
		)

));

$latest_visits = $report->metrics->get(array(
	'api_call' 		=> 'latest_visits',
	'period'			=> 'last_24_hours',
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		
		'is_browser' => 1,
		'is_robot' 	=> 0
		
		),
	'limit'			=> '35'

));

$top_pages_data = $report->metrics->get(array(
	'api_call' 		=> 'top_documents',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		
		'is_browser' => 1,
		'is_robot' 	=> 0
		
		),
	'limit'			=> '10'
));

$top_referers = $report->metrics->get(array(
	'api_call' 		=> 'top_referers',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'limit'			=> '10'
));

$top_visitors = $report->metrics->get(array(
	'api_call' 			=> 'top_visitors',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'limit'				=> '10'
));

$from_feed = $report->metrics->get(array(
	'api_call' 			=> 'from_feed',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array'
));


// Assign Data to templates

$body->set('headline', 'Analytics Dashboard');
$body->set('period_label', $report->get_period_label($report->period));
$body->set('top_visitors', $top_visitors);
$body->set('from_feed', $from_feed);
$body->set('config', $report->config);
$body->set('rows', $dash_result);
$body->set('period', $report->period);
$body->set('dash_counts', $dash_counts[0]);
$visit->set('visits', $latest_visits);
$body->set('visit_data', $visit);
$top_pages->set('top_pages', $top_pages_data);
$body->set('top_pages_table', $top_pages);
$body->set('top_referers', $top_referers);

$report->tpl->set('content', $body);

// Render Report

echo $report->tpl->fetch();

?>