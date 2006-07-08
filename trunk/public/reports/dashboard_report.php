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
require_once(OWA_BASE_DIR.'/owa_news.php');

$report = new owa_report;

// Set the reporting period
/*
if (!empty($report->params['period'])):
	$report->set_period($report->params['period']);
else:
	$report->set_period('today');
endif;
	*/	
// Setup the templates

$body = & new owa_template($report->params); 
$body->set_template('index.tpl');// This is the inner template

$visit = & new owa_template($report->params); 
$visit->set_template('visit.tpl');// This is the inner template

$top_pages = & new owa_template($report->params);
$top_pages->set_template('top_pages.tpl');

$top_referers = & new owa_template($report->params);
$top_referers->set_template('top_referers.tpl');

$top_visitors = & new owa_template($report->params);
$top_visitors->set_template('top_visitors.tpl');

$summary_stats = & new owa_template($report->params);
$summary_stats->set_template('summary_stats.tpl');

$periods_menu = & new owa_template($report->params);
$periods_menu->set_template('periods_menu.tpl');

$core_metrics = & new owa_template($report->params);
$core_metrics->set_template('core_metrics.tpl');


// Fetch metrics

switch ($report->period) {

	case "this_year":
		$core_metrics_data = $report->metrics->get(array(
			'api_call' 		=> 'dash_core',
			'request_params'	=>	$report->params,
			'period'			=> $report->period,
			'result_format'		=> 'assoc_array',
			'constraints'		=> array(
				'site_id'	=> $report->params['site_id'],
				'is_browser' => 1,
				'is_robot' 	=> 0),
			'group_by'			=> 'month'
		
		));
		
	break;
	
	default:
		$core_metrics_data = $report->metrics->get(array(
		'api_call' 		=> 'dash_core',
		'request_params'	=>	$report->params,
		'period'			=> $report->period,
		'result_format'		=> 'assoc_array',
		'constraints'		=> array(
			'site_id'	=> $report->params['site_id'],
			'is_browser' => 1,
			'is_robot' 	=> 0),
		'group_by'			=> 'day'
	
	));
	break;
}

$summary_stats_data = $report->metrics->get(array(
	'api_call' 		=> 'dash_counts',
	'request_params'	=>	$report->params,
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0
		
		)

));

$latest_visits = $report->metrics->get(array(
	'api_call' 		=> 'latest_visits',
	'request_params'	=>	$report->params,
	'period'			=> 'last_24_hours',
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0
		
		),
	'limit'			=> '35'

));

$top_pages_data = $report->metrics->get(array(
	'api_call' 		=> 'top_documents',
	'request_params'	=>	$report->params,
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' => 1,
		'is_robot' 	=> 0
		
		),
	'limit'			=> '10'
));

$top_referers_data = $report->metrics->get(array(
	'api_call' 			=> 'top_referers',
	'request_params'	=>	$report->params,
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'limit'				=> '10',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'])
));

$top_visitors_data = $report->metrics->get(array(
	'api_call' 			=> 'top_visitors',
	'request_params'	=>	$report->params,
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'limit'				=> '10',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'])
));

$from_feed = $report->metrics->get(array(
	'api_call' 			=> 'from_feed',
	'request_params'	=>	$report->params,
	'period'			=> $report->period,
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'])
));

// Fetch Sites List
$sites = $report->getSitesList();

//Fetch latest OWA news
$rss = new owa_news;
$news = $rss->Get($rss->config['owa_rss_url']);

// Assign Data to templates

$report->tpl->set('news', $news);
$body->set('headline', 'Analytics Dashboard');
$body->set('period_label', $report->get_period_label($report->period));
$periods_menu->set('period', $report->period);
$body->set('periods_menu', $periods_menu);
$top_visitors->set('data', $top_visitors_data);
$body->set('top_visitors_table', $top_visitors);
$body->set('config', $report->config);

$body->set('params', $report->params);
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
$top_pages->set('params', $report->params);
$body->set('top_pages_table', $top_pages);
$top_referers->set('data', $top_referers_data);
$body->set('top_referers_table', $top_referers);

// Global Assignments
$report->tpl->set('report_name', basename(__FILE__));
$body->set('date_label', $report->date_label);
$report->tpl->set('content', $body);

// Render Report

echo $report->tpl->fetch();

?>