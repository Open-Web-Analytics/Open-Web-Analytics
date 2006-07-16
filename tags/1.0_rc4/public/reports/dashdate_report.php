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

$report = new owa_report;
		
// Setup the templates

$body = & new owa_template; 
$body->set_template('date.tpl');// This is the inner template

$visit = & new owa_template; 
$visit->set_template('visit.tpl');// This is the inner template

$top_pages = & new owa_template;
$top_pages->set_template('top_pages.tpl');

$top_referers = & new owa_template;
$top_referers->set_template('top_referers.tpl');

$top_visitors = & new owa_template;
$top_visitors->set_template('top_visitors.tpl');

$summary_stats = & new owa_template;
$summary_stats->set_template('summary_stats.tpl');

$periods_menu = & new owa_template;
$periods_menu->set_template('periods_menu.tpl');

$core_metrics = & new owa_template;
$core_metrics->set_template('core_metrics.tpl');


// Fetch metrics

if ($report->params['period'] == 'day'):

	$core_metrics_data = $report->metrics->get(array(
			'request_params'	=>	$report->params,
			'api_call' 			=>	'dash_core',
			'period'			=> 	$report->period,
			'result_format'		=> 	'assoc_array',
			'constraints'		=> 	array(
				'site_id'		=> 	$report->params['site_id'],
				'is_browser' 	=> 	1,
				'is_robot' 		=> 	0),
			'group_by'			=> 	'day'
		
		));

else:

		$core_metrics_data = $report->metrics->get(array(
			'request_params'	=>	$report->params,
			'api_call' 			=> 	'dash_core',
			'period'			=> 	$report->period,
			'result_format'		=> 	'assoc_array',
			'constraints'		=> 	array(
				'site_id'	=> $report->params['site_id'],
				'is_browser' => 1,
				'is_robot' 	=> 0),
			'group_by'			=> 'month'
		
		));
	
endif;

$summary_stats_data = $report->metrics->get(array(
	'request_params'	=>	$report->params,
	'api_call' 			=> 'dash_counts',
	'period'			=> 	$report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0)

));

$latest_visits = $report->metrics->get(array(
	'request_params'	=>	$report->params,
	'api_call' 			=> 'latest_visits',
	'period'			=> 	$report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id'],
		'is_browser' 	=> 1,
		'is_robot' 		=> 0),
	'limit'			=> '35'

));

$top_pages_data = $report->metrics->get(array(
	'request_params'	=>	$report->params,
	'api_call' 			=> 'top_documents',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0),
	'limit'			=> '10'
));

$top_referers_data = $report->metrics->get(array(
	'request_params'	=>	$report->params,
	'api_call' 			=> 'top_referers',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0),
	'limit'			=> '10'
));

$top_visitors_data = $report->metrics->get(array(
	'request_params'	=>	$report->params,
	'api_call' 			=> 'top_visitors',
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0),
	'limit'				=> '10'
));

$from_feed = $report->metrics->get(array(
	'request_params'	=>	$report->params,
	'api_call' 			=> 'from_feed',
	'period'			=> $report->period,
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0
),
	'result_format'		=> 'assoc_array'
));

// Time Period Label


// Assign Data to templates

$body->set('headline', 'Analytics Dashboard for ');
$body->set('params', $report->params);
//$periods_menu->set('period', $report->period);
//$body->set('periods_menu', $periods_menu);
$top_visitors->set('data', $top_visitors_data);
$body->set('top_visitors_table', $top_visitors);
$body->set('config', $report->config);
$core_metrics->set('data', $core_metrics_data);
$core_metrics->set('period', $report->period);
$body->set('core_metrics_table', $core_metrics);
$body->set('period', $report->period);
$summary_stats->set('data', $summary_stats_data);
$summary_stats->set('from_feed', $from_feed);
$body->set('summary_stats_table', $summary_stats);
$visit->set('visits', $latest_visits);
$body->set('visit_data', $visit);
$top_pages->set('top_pages', $top_pages_data);
$body->set('top_pages_table', $top_pages);
$top_referers->set('data', $top_referers_data);
$body->set('top_referers_table', $top_referers);



$body->set('date_label', $report->date_label);
$report->tpl->set('content', $body);
$report->tpl->set('report_name', basename(__FILE__));

// Render Report

echo $report->tpl->fetch();

?>