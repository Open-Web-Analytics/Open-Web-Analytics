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

require_once 'owa_env.php';
require_once (OWA_PEARLOG_DIR . '/Log.php');

/**
 * Utility Functions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_lib {

	/**
	 * Convert Associative Array to String
	 *
	 * @param string $inner_glue
	 * @param string $outer_glue
	 * @param array $array
	 * @return string 
	 */
	function implode_assoc($inner_glue, $outer_glue, $array) {
	   $output = array();
	   foreach( $array as $key => $item ) {
			  $output[] = $key . $inner_glue . $item;
		}
		
		return implode($outer_glue, $output);
	}
			
	/**
	 * Deconstruct Associative Array 
	 *
	 * For example this takes array([1] => array(a => dog, b => cat), [2] => array(a => sheep, b => goat))
	 * and tunrs it into array([a] => array(dog, sheep), [b] => array(cat, goat)) 
	 * 
	 * @param array $a_array
	 * @return array $data_arrays
	 * @access public
	 */
	function deconstruct_assoc($a_array) {
		
		$data_arrays = array();
	
		if(!empty($a_array[1])) :
		
			foreach ($a_array as $key => $value) {
				foreach ($value as $k => $v) {
					$data_arrays[$k][] = $v;
			
				}
			}
		else:
		
			foreach ($a_array as $key => $value) {
				$data_arrays[$key][] = $value;
			}
		endif;
		
		return $data_arrays;
	}
	
	
	function decon_assoc($a_array) {
		
		$data_arrays = array();
	
		foreach ($a_array as $key => $value) {
			//foreach ($value as $k => $v) {
				$data_arrays[$key][] = $value;
		
			//}
		}
		
		return $data_arrays;
	}

	/**
	 * Array of Current Time
	 *
	 * @return array
	 * @access public
	 */
	function time_now() {
		
		$timestamp = time();
		
		return array(
			
				'year' 				=> date("Y", $timestamp),
				'month' 			=> date("n", $timestamp),
				'day' 				=> date("d", $timestamp),
				'dayofweek' 		=> date("D", $timestamp),
				'dayofyear' 		=> date("z", $timestamp),
				'weekofyear'		=> date("W", $timestamp),
				'hour'				=> date("G", $timestamp),
				'minute' 			=> date("i", $timestamp),
				'second' 			=> date("s", $timestamp),
				'timestamp'			=> $timestamp
			);
	}
		
	/**
	 * Stub of debug Handler
	 *
	 * @return string
	 * @access 	public
	 * @static 
	 */
	function &get_debugmsgs() {
		
		static $msgs;
		return $msgs;
	}
		
	/**
	 * Error Handler
	 *
	 * @param string $msg
	 * @access public
	 */
	function errorHandler($msg) {
		
		$conf = array('mode' => 0755, 'timeFormat' => '%X %x');
		$error_logger = &Log::singleton('file', $this->config['error_log_file'], 'ident', $conf);
		$this->error_logger->_lineFormat = '[%3$s]';
		
		return;
	}
	
	function get_month_label($month) {
		
		switch ($month) {
			
			case '1':
				$label = 'January';
				break;
			case '2':
				$label = 'February';
				break;
			case '3':
				$label = 'March';
				break;
			case '4':
				$label = 'April';
				break;
			case '5':
				$label = 'May';
				break;
			case '6':
				$label = 'June';
				break;
			case '7':
				$label = 'July';
				break;
			case '8':
				$label = 'August';
				break;
			case '9':
				$label = 'September';
				break;
			case '10':
				$label = 'October';
				break;
			case '11':
				$label = 'November';
				break;
			case '12':
				$label = 'December';
				break;
			default:
				$label = 'Unknown Month';
				break;
		}
		
		return $label;
	}
	
	function get_period_label($period) {
	
		switch ($period) {
		
			case "today";
				$label = "Today";
				break;
			case "yesterday";
				$label = "Yesterday";
				break;
			case "this_month";
				$label = "This Month";
				break;
			case "this_week";
				$label = "This Week";
				break;
			case "this_year";
				$label = "This Year";
				break;
			case "last_seven_days";
				$label = "The Last Seven Days";
				break;
			case "last_thirty_days";
				$label = "The Last Thirty Days";
				break;
			default:
				$label = 'Unknown Label';
		}
		
		return $label;
	}
	
	function get_current_url() {
		
		$url = 'http';	
		
		if($_SERVER['HTTPS']=='on'):
			$url.= 's';
		endif;
		
		$url .= '://'.$_SERVER['SERVER_NAME'];
		
		if($_SERVER['SERVER_PORT'] != 80):
			$url .= ':'.$_SERVER['SERVER_PORT'];
		endif;
		
		$url .= $_SERVER['REQUEST_URI'];
		
		return $url;
	}
	
	/**
	 * Builds date param array from GET
	 *
	 * @return array
	 */
	function getRestparams() {
		
		$config = &owa_settings::get_settings();
		
		$params = array();
		
		$params['month'] = $_GET['month'];
		$params['owa_action'] = $_GET['owa_action'];
		$params['year'] = $_GET['year'];
		$params['day'] = $_GET['day'];
		$params['dayofyear'] = $_GET['dayofyear'];
		$params['weekofyear'] = $_GET['weekofyear'];
		$params['hour'] = $_GET['hour'];
		$params['minute'] = $_GET['minute'];
		$params['limit'] = $_GET['limit'];
		$params['offset'] = $_GET['offset'];
		$params['sortby'] = $_GET['sortby'];
		$params['period'] = $_GET['period'];
		$params['site_id'] = $_GET['site_id'];
		$params['type'] = $_GET['type'];
		$params['api_call'] = $_GET['name'];
		$params['session_id'] = $_GET[$config['ns'].$config['session_param']];
		$params['visitor_id'] = $_GET[$config['ns'].$config['visitor_param']];
		$params['document_id'] = $_GET[$config['ns'].$config['document_param']];
		$params['referer_id'] = $_GET[$config['ns'].$config['referer_param']];
		$params['source'] = $_GET[$config['ns'].$config['source_param']];
	
		return $params;
		
	}
}

?>
