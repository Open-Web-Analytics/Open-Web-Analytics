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

// Assign Data to templates

$body->set('headline', 'Traffic Sources');
$body->set('period_label', $report->period_label);
$body->set('keywords', $top_keywords);
$body->set('anchors', $top_anchors);
$body->set('domains', $top_hosts);
$body->set('referers', $top_referers);
$body->set('se_hosts', $top_search_engines);
// Global Template Assignments
$body->set('params', $report->params);
$report->tpl->set('report_name', basename(__FILE__));
$report->tpl->set('content', $body);

//Output Report
echo $report->tpl->fetch();

?>