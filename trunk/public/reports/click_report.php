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

$auth = &owa_auth::get_instance();
$auth->authenticateUser('viewer');

$report = new owa_report;
	
// Setup the templates

$body = & new owa_template($report->params); 
$body->set_template('clicks.tpl');// This is the inner template

// Fetch Metrics

$document_details = $report->metrics->get(array(
	'api_call' 		=> 'document_details',
	'request_params'	=>	$report->params,
	'result_format'		=> 'assoc_array',
	'document_id' => $report->params['document_id']
));

$clicks = $report->metrics->get(array(
	'api_call' 			=> 'top_clicks',
	'request_params'	=> $report->params,
	'period'			=> $report->params['period'],
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'		=> $report->params['site_id'],
		'document_id'		=> $report->params['document_id'],
		),
	'limit'				=> 500
));

//print_r($clicks);

// Assign Data to templates

$body->set('headline', 'Click Report');
$body->set('period_label', $report->period_label);
$body->set('clicks', $clicks);
$body->set('detail', $document_details);

// Global Template Assignments
$body->set('params', $report->params);
$report->tpl->set('report_name', basename(__FILE__));
$report->tpl->set('content', $body);

//Output Report
echo $report->tpl->fetch();

?>