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
require_once(WA_BASE_DIR.'/owa_report.php');

/**
 * Visitor Report
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

if (!empty($_POST['period'])):
	$report->set_period($_POST['period']);
else:
	$report->set_period('this_month');
endif;

if (!empty($_POST['limit'])):
	$limit = $_POST['limit'];
else:
	$limit = 50;
	endif;

$visitor_id = $_GET[$report->config['ns'].$report->config['visitor_param']];
	
	
// Setup the templates
	
//$report->tpl->set_template('wordpress.tpl'); // this is the outer template

$body = & new Template; 

$body->set_template('visitor.tpl');// This is the inner template

$visits = & new Template; 

$visits->set_template('visit.tpl');// This is a sub template


// Fetch Metrics

$result = $report->metrics->get(array(
	'api_call' 			=> 'latest_visits',
	'period'			=> 'all_time',
	'result_format'		=> 'assoc_array',
	'constraints'		=> array(
		
		'is_browser' 	=> 1,
		'is_robot' 		=> 0,
		'visitor_id' 	=> $visitor_id
		
		),
	'limit' 			=> $limit
));

// Assign Data to templates

$body->set('headline', 'Visitor Detail');
$body->set('period_label', $report->period_label);
$body->set('config', $report->config);
$visits->set('visits', $result);
$body->set('visitor_id', $visitor_id);
$body->set('visits_data', $visits);
$report->tpl->set('content', $body);

//Output Report

echo $report->tpl->fetch();



?>