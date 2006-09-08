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
require_once(OWA_BASE_DIR.'/owa_auth.php');

/**
 * Traffic Report
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

$auth = &owa_auth::get_instance();
$auth->authenticateUser('viewer');

$report = new owa_report;
	
// Setup the templates

$body = & new owa_template($report->params); 
$body->set_template('traffic.tpl');// This is the inner template

// Fetch Metrics

$top_keywords = $report->metrics->get(array(
	'request_params'	=> $report->params,
	'api_call' 		=> 'top_keywords',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id']
		),
	'limit'			=> 30

));

$top_anchors = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 		=> 'top_anchors',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id']	
		),
	'limit'			=> 30

));

$top_hosts = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'top_hosts',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id']	
		),
	'limit'				=> 30

));

$top_referers = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'top_referers',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id'],
		'is_searchengine' => 0
		),
	'limit'				=> 30

));

$top_search_engines = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'top_refering_hosts',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id'],
		'is_searchengine' => 1	
		),
	'limit'				=> 30

));


$session_count = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'session_count',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id']
		)
));

$from_se = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'from_search_engine',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id']
		)
));

$from_sites = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'sessions_from_sites',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id']
		)
));

$from_direct = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'sessions_from_direct',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id']
		)
));

$from_feeds = $report->metrics->get(array(
	'request_params'	=> $report->params,	
	'api_call' 			=> 'from_feed',
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id']
		)
));


// Assign Data to templates

$body->set('headline', 'Traffic Sources');
$body->set('period_label', $report->period_label);
$body->set('keywords', $top_keywords);
$body->set('anchors', $top_anchors);
$body->set('domains', $top_hosts);
$body->set('referers', $top_referers);
$body->set('se_hosts', $top_search_engines);
$body->set('sessions', $session_count);
$body->set('from_feeds', $from_feeds);
$body->set('from_sites', $from_sites);
$body->set('from_direct', $from_direct);
$body->set('from_se', $from_se);
// Global Template Assignments
$body->set('params', $report->params);
$report->tpl->set('report_name', basename(__FILE__));
$report->tpl->set('content', $body);

//Output Report
echo $report->tpl->fetch();

?>