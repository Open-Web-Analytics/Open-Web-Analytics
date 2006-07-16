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
 * Content Report
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
$body->set_template('content.tpl');// This is the inner template
$entry_pages = & new owa_template($report->params);
$entry_pages->set_template('top_pages.tpl');
$exit_pages = & new owa_template($report->params);
$exit_pages->set_template('top_pages.tpl');
// Fetch Metrics

$entry_documents = $report->metrics->get(array(
	'api_call' 			=> 'top_entry_documents',
	'request_params'	=>	$report->params,
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array('site_id'	=> $report->params['site_id']),
	'order'				=> 'DESC',
	'limit'				=> 20
));

$exit_documents = $report->metrics->get(array(
	'api_call' 			=> 'top_exit_documents',
	'request_params'	=>	$report->params,
	'period'			=> $report->params['period'],
	'constraints'		=> array('site_id'	=> $report->params['site_id']),
	'result_format'		=> 'assoc_array',
	'order'				=> 'DESC',
	'limit'				=> 20
));

// Assign Data to templates

$body->set('headline', 'Content');
$body->set('period_label', $report->period_label);
$entry_pages->set('top_pages', $entry_documents);
$exit_pages->set('top_pages', $exit_documents);
$entry_pages->set('params', $report->params);
$exit_pages->set('params', $report->params);
$body->set('entry_pages', $entry_pages);
$body->set('exit_pages', $exit_pages);
// Global Template Assignments
$report->tpl->set('report_name', basename(__FILE__));
$body->set('params', $report->params);
$report->tpl->set('content', $body);

//Output Report
echo $report->tpl->fetch();

?>