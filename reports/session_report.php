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

include dirname(__FILE__).'/../wa_env.php';
require_once(WA_BASE_DIR.'/wa_report.php');

/**
 * Session Report
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */

$report = new wa_report;

if (!empty($_POST['period'])):
	$report->set_period($_POST['period']);
else:
	$report->set_period('this_month');
endif;

$session_id = $_GET['wa_s'];
	
// Setup the templates
	
$report->tpl->set_template('wordpress.tpl'); // this is the outer template

$body = & new Template; 

$body->set_template('session.tpl');// This is the inner template

$visits = & new Template; 

$visits->set_template('visit.tpl');// This is a sub template

// Fetch metrics

$session_data = $report->metrics->get(array(
	'api_call' 			=> 'session_detail',
	'result_format'		=> 'assoc_array',
	'limit'				=> '50',
	'constraints'		=> array(
		
		'is_browser' 	=> 1,
		'is_robot' 		=> 0,
		'session_id' 	=> $session_id
		)
));

$result = $report->metrics->get(array(
	'api_call' 			=> 'latest_visits',
	'period'			=> 'all_time',
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		
		'is_browser' 	=> 1,
		'is_robot' 		=> 0,
		'session_id' 	=> $session_id
		
		),
	'limit' 			=> 1
));

// Assign data to templates

$body->set('headline', 'Visit (Session) Detail');
$body->set('period_label', $report->period_label);
$body->set('config', $report->config);
$body->set('session_id', $session_id);
$body->set('session_data', $session_data);
//$body->set('visit', $result[0]);
$visits->set('visits', $result);
$body->set('visit_data', $visits);
$report->tpl->set('content', $body);

// Make Report
echo $report->tpl->fetch();

?>
