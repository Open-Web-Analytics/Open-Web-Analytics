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
 * Session Report
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

$body->set_template('session.tpl');// This is the inner template

$visits = & new owa_template($report->params); 

$visits->set_template('visit.tpl');// This is a sub template

// Fetch metrics

$session_data = $report->metrics->get(array(
	'api_call' 			=> 'session_detail',
	'result_format'		=> 'assoc_array',
	'limit'				=> '50',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' 	=> 1,
		'is_robot' 		=> 0,
		'session_id' 	=> $report->params['session_id']
		)
));

$result = $report->metrics->get(array(
	'api_call' 			=> 'latest_visits',
	'period'			=> 'all_time',
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		'site_id'	=> $report->params['site_id'],
		'is_browser' 	=> 1,
		'is_robot' 		=> 0,
		'session_id' 	=> $report->params['session_id']
		
		),
	'limit' 			=> 1
));

// Assign data to templates

$body->set('headline', 'Visit (Session) Detail');
$body->set('period_label', $report->period_label);
$body->set('config', $report->config);
$body->set('session_id', $report->params['session_id']);
$body->set('session_data', $session_data);
//$body->set('visit', $result[0]);
$visits->set('visits', $result);
$body->set('visit_data', $visits);
$report->tpl->set('content', $body);

// Make Report
echo $report->tpl->fetch();

?>
