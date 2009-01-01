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

require_once(OWA_INCLUDE_DIR.'template_class.php');
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');

/**
 * OWA Wrapper for template class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_template extends Template {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	var $theme_template_dir;
	
	var $module_local_template_dir;
	
	var $module_template_dir;
	
	var $e;
	
	var $period;
	
	/**
	 * Params passed by calling caller
	 *
	 * @var array
	 */
	var $caller_params;
	
	function owa_template($module = null, $caller_params = array()) {
		
		$this->caller_params = $caller_params;
			
		$c = &owa_coreAPI::configSingleton();
		$this->config = $c->fetch('base');
		
		$this->e = &owa_coreAPI::errorSingleton();
		
		// set template dirs
		if(!empty($caller_params['module'])):
			$this->_setTemplateDir($module);
		else:
			$this->_setTemplateDir('base');
		endif;
		
		$this->time_now = owa_lib::time_now();
		
		return;
	}
	
	function _setTemplateDir($module) {
	
		// set module template dir
		$this->module_template_dir = OWA_DIR.'modules'.DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR;
		
		// set module local template override dir
		$this->module_local_template_dir = $this->module_template_dir.'local'.DIRECTORY_SEPARATOR;
		
		// set theme template dir
		$this->theme_template_dir = OWA_THEMES_DIR.$this->config['theme'].DIRECTORY_SEPARATOR;
		
		return;
	}
	
	/**
     * Set the template file
     *
     * @param string $file
     */
	function set_template($file = null) {
	
		if ($file == null):
			$this->e->error('No template file was specified.');
			return false;
		else:
			// check module's local modification template Directory
			if (file_exists($this->module_local_template_dir.$file)):
				$this->file = $this->module_local_template_dir.$file;
				
			// check theme's template Directory
			elseif(file_exists($this->theme_template_dir.$file)):
				$this->file = $this->theme_template_dir.$file;
				
			// check module's template directory
			elseif(file_exists($this->module_template_dir.$file)):
				$this->file = $this->module_template_dir.$file;
			
			// throw error
			else:
				$this->e->err(sprintf('%s was not found in any template directory.', $file));
				return false;
			endif;
        
        	return true;
        endif;
    }
	
	/**
	 * Truncate string
	 *
	 * @param string $str
	 * @param integer $length
	 * @param string $trailing
	 * @return string
	 */
	function truncate ($str, $length=10, $trailing='...')  {
	 
    	// take off chars for the trailing 
    	$length-=strlen($trailing); 
    	if (strlen($str) > $length):
        	// string exceeded length, truncate and add trailing dots 
         	return substr($str,0,$length).$trailing; 
		else:  
        	// string was already short enough, return the string 
        	$res = $str;  
      	endif;
   
      return $res; 
	}
	
	function get_month_label($month) {
		
		return owa_lib::get_month_label($month);
	}
	
	/**
	 * Chooses the right icon based on browser type
	 *
	 * @param unknown_type $browser_type
	 * @return unknown
	 */
	function choose_browser_icon($browser_type) {
		
		switch (strtolower($browser_type)) {
			
			case "ie":
				$file = 'msie.png';
				$name = 'Microsoft Internet Explorer';
				break;
			case "internet explorer":
				$file = 'msie.png';
				$name = 'Microsoft Internet Explorer';
				break;
			case "firefox":
				$file = 'firefox.png';
				$name = 'Firefox';
				break;
			case "safari":
				$file = 'safari.png';
				$name = 'Safari';
				break;
			case "opera":
				$file = 'opera.png';
				$name = 'Opera';
				break;
			case "netscape":
				$file = 'netscape.png';
				$name = 'Netscape';
				break;
			case "mozilla":
				$file = 'mozilla.png';
				$name = 'Mozilla';
				break;
			case "konqueror":
				$file = 'kon.png';
				$name = 'Konqueror';
				break;
			case "camino":
				$file = 'camino.png';
				$name = 'Camino';
				break; 
			case "aol":
				$file = 'aol.png';
				$name = 'AOL';
				break; 
			case "default browser":
				$file = 'default_browser.png';
				$name = 'Unknown Browser';
				break; 
			default:
				$file = 'default_browser.png';
			
		}
			
		return sprintf('<img alt="%s" align="baseline" src="%s">', $name, $this->makeImageLink($file));
		
	}
	
	
	function makeLinkQueryString($query_params) {
		
		$new_query_params = array();
		
		//Load params passed by caller
		if (!empty($this->caller_params)):
			foreach ($this->caller_params as $name => $value) {
				if (!empty($value)):
					$new_query_params[$name] = $value;	
				endif;
			}
		endif;

		// Load overrides
		if (!empty($query_params)):
			foreach ($query_params as $name => $value) {
				if (!empty($value)):
					$new_query_params[$name] = $value;	
				endif;
			}
		endif;
		
		// Construct GET request
		if (!empty($new_query_params)):
			foreach ($new_query_params as $name => $value) {
				if (!empty($value)):
					$get .= $name . "=" . $value . "&";	
				endif;
			}
		endif;
		
		return $get;
		
	}
	
	/**
	 * Makes navigation links by checking whether or not the view 
	 * that is rendering the template is not the view being refered to in the link.
	 * 
	 * @param array navigation array
	 */
	function makeNavigation($nav, $id = '', $class = '', $li_template = '<LI class="%s"><a href="%s">%s</a></LI>', $li_class = '') {
		
		$ul = sprintf('<UL id="%s" class="%s">', $id, $class);
		
		if (!empty($nav)):
		
			$navigation = $ul;
			
			foreach($nav as $k => $v) {
										
				$navigation .= sprintf($li_template, $li_class,	$this->makeLink(array('do' => $v['ref']), true), $v['anchortext']);
				
			}
			
			$navigation .= '</UL>';
			
			return $navigation;
		else:
			return false;
		endif;
		
	}
	
		
	function makeTwoLevelNav($links) {
	
		$navigation = '<UL id="report_top_level_nav_ul">';

		foreach($links as $k => $v) {
		
			if (!empty($v['subgroup'])):
				$sub_nav = $this->makeNavigation($v['subgroup']);	
				
				$navigation .= sprintf('<LI class="drawer"><H2 class="nav_header"><a href="%s">%s</a></H2>%s</LI>', 
												$this->makeLink(array('do' => $v['ref']), true), 
												$v['anchortext'], $sub_nav);
			else:
			
				$navigation .= sprintf('<LI class="drawer"><H2 class="nav_header"><a href="%s">%s</a></H2></LI>', 
												$this->makeLink(array('do' => $v['ref']), true), 
												$v['anchortext']);
				
			endif;	
			
		}
		
		$navigation .= '</UL>';
			
		return $navigation;
	
	}
	
	function daysAgo($time) {
		
		$now = mktime(23, 59, 59, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']);
		
		$days = round(($now - $time) / (3600*24));
		
		switch ($days) {
			
			case 1:
				return $days . " day ago";
		
			default:
				return $days . " days ago";
		}
		
	}
	
	function getAuthStatus() {
		
		$auth = &owa_auth::get_instance();
		
		return $auth->auth_status;
	}
	
	function makeWikiLink($page) {
		
		return sprintf($this->config['owa_wiki_link_template'], $page);
	}
	
	/**
	 * Returns Namespace value to template
	 *
	 * @return string
	 */
	function getNs() {
		
		return $this->config['ns'];
	}
	
	function graphLink($params, $add_state = false) {
		
		return $this->makeLink($params, $add_state, $this->config['action_url']);
	}
	
	
	/**
	 * Makes Links, adds state to links optionaly.
	 *
	 * @param array $params
	 * @param boolean $add_state
	 * @return string
	 */
	function makeLink($params = array(), $add_state = false, $url = '', $xml = false) {
		//print_r($this->get('params'));
		$all_params = array();
		//print_r($this->caller_params['link_state']);
		//Loads link state passed by caller
		if ($add_state == true):
			if (!empty($this->caller_params['link_state'])):
				$all_params = array_merge($all_params, $this->caller_params['link_state']);
			endif;
			
			// add in period properties if available
			$period = $this->get('timePeriod');
			
			if (!empty($period)):
				$all_params = array_merge($all_params, $period->getPeriodProperties());
				//print_r($all_params);
			endif;
			
		endif;
		
		
		// Load overrides
		if (!empty($params)):
			$params = array_filter($params);
			//print_r($params);
			$all_params = array_merge($all_params, $params);
		endif;
		
		//print_r($all_params);
		//print_r($this->get('timePeriod')->getPeriodProperties());
		
		$get = '';
		
		if (!empty($all_params)):
		
			$count = count($all_params);
			
			$i = 0;
			
			foreach ($all_params as $n => $v) {
				
				$get .= $this->config['ns'].$n.'='.$v;
				
				$i++;
				
				if ($i < $count):
					$get .= "&";
				endif;
			}
		endif;
		
		if (empty($url)):
			$url = $this->config['main_url'];
		endif;
		
		$link = sprintf($this->config['link_template'], $url, $get);
		
		if ($xml == true):
			$link = $this->escapeForXml($link);
		endif;
		
		return $link;
		
	}
	
	function escapeForXml($string) {
	
		$string = str_replace(array('&', '"', "'", '<', '>' ), array('&amp;' , '&quot;', '&apos;' , '&lt;' , '&gt;'), $string);
		// removes non-ascii chars
		$string = owa_lib::escapeNonAsciiChars($string);
		return $string;
	}
	
	function makeAbsoluteLink($params = array(), $add_state = false, $url = '', $xml = false) {
		
		if (empty($url)):
			$url = $this->config['main_absolute_url'];
		endif;
		
		return $this->makeLink($params, $add_state, $url, $xml);
		
	}
	
	function makeImageLink($name, $absolute = false) {
		
		if ($absolute == true):
			$url = $this->config['images_absolute_url'];
		else:
			$url = $this->config['images_url'];
		endif;
		
		return $url.$name;
		
	}
	
	function includeTemplate($file) {
	
		$this->set_template($file);
		include($this->file);
		return;
	
	}
	
	function setTemplate($file) {
		
		$this->set_template($file);
		return $this->file;
		
	}
	/*

	function ofc($url, $use_swfobject = true, $id, $base = '') {
	
		if (empty($width)):
			$width = '100%';
		endif;
		
		$base = $this->config['public_url'].'includes/ofc-1.9/';
		//
		// I think we may use swfobject for all browsers,
		// not JUST for IE...
		//
		//$ie = strstr(getenv('HTTP_USER_AGENT'), 'MSIE');
		
		//
		// escape the & and stuff:
		//
		$url = urlencode($url);
		
		//
		// output buffer
		//
		$out = array();
		
		//
		// check for http or https:
		//
		if (isset ($_SERVER['HTTPS']))
		{
			if (strtoupper ($_SERVER['HTTPS']) == 'ON')
			{
				$protocol = 'https';
			}
			else
			{
				$protocol = 'http';
			}
		}
		else
		{
			$protocol = 'http';
		}
		
		//
		// if there are more than one charts on the
		// page, give each a different ID
		//
		global $open_flash_chart_seqno;
		$obj_id = 'chart';
		
		$div_name = 'ofc_chart_object_'.$id;
		
		//$out[] = '<script type="text/javascript" src="'. $base .'js/ofc.js"></script>';
		
		if( !isset( $open_flash_chart_seqno ) )
		{
			$open_flash_chart_seqno = 1;
			$out[] = '<script type="text/javascript" src="'. $base .'swfobject.js"></script>';
		}
		else
		{
			$open_flash_chart_seqno++;
			$obj_id .= '_'. $open_flash_chart_seqno;
			$div_name .= '_'. $open_flash_chart_seqno;
		}
		
		if( $use_swfobject )
		{
		// Using library for auto-enabling Flash object on IE, disabled-Javascript proof  
		$out[] = '<div id="'. $div_name .'" class="owa_ofcChart"></div>';
		$out[] = '<script type="text/javascript">';
		$out[] = 'var so = new SWFObject("'. $base .'actionscript/open-flash-chart.swf", "'. $obj_id .'", "'. $width . '", "' . $height . '", "9", "#FFFFFF");';
		//$out[] = 'so.addVariable("width", "' . $width . '");';
		//$out[] = 'so.addVariable("height", "' . $height . '");';
		$out[] = 'so.addVariable("data", "'. $url . '");';
		$out[] = 'so.addParam("allowScriptAccess", "sameDomain");';
		$out[] = 'so.write("'. $div_name .'");';
		$out[] = '</script>';
		$out[] = '<noscript>';
		}
	
		$out[] = '<object classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" codebase="' . $protocol . '://fpdownload.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=8,0,0,0" ';
		$out[] = 'width="' . $width . '" height="' . $height . '" id="ie_'. $obj_id .'" align="middle">';
		$out[] = '<param name="allowScriptAccess" value="sameDomain" />';
		$out[] = '<param name="movie" value="'. $base .'open-flash-chart.swf?width='. $width .'&height='. $height . '&data='. $url .'" />';
		$out[] = '<param name="quality" value="high" />';
		$out[] = '<param name="bgcolor" value="#FFFFFF" />';
		$out[] = '<embed src="'. $base .'actionscript/open-flash-chart.swf?data=' . $url .'" quality="high" bgcolor="#FFFFFF" width="'. $width .'" height="'. $height .'" name="'. $obj_id .'" align="middle" allowScriptAccess="sameDomain" ';
		$out[] = 'type="application/x-shockwave-flash" pluginspage="' . $protocol . '://www.macromedia.com/go/getflashplayer" id="'. $obj_id .'"/>';
		$out[] = '</object>';
	
		if ( $use_swfobject ) {
		$out[] = '</noscript>';
		}
		
		return implode("\n",$out);
	}
	
*/
	
	function getWidget($do, $params = array(), $wrapper = true, $add_state = true) {
		
		$final_params = array();
		
		if (empty($params)):
			$params = array();
		endif;
		
		$params['do'] = $do;
	
		if ($wrapper === true):
			$params['initial_view'] = true;
			$params['wrapper'] = true;
		elseif ($wrapper === 'inpage'):
			$params['initial_view'] = true;
			$params['wrapper'] = 'inpage';
		else:
			$params['wrapper'] = false;
		endif;

		// add state params into request params
		if ($add_state === true):
			$final_params = array_merge($final_params, $this->caller_params['link_state']);
		endif;
		
		// apply overides made via the template
		$final_params = array_merge($final_params, array_filter($params));
		
		return owa_coreAPI::performAction($do, $final_params);
	}
	
	function getInpageWidget($do, $params = array()) {
	
		return owa_template::getWidget($do, $params, 'inpage');
	
	}
	
	function getSparkline($metric, $metric_col, $period = '', $height = 25, $width = 250, $map = array(), $add_state = true) {
	
		$map['metric'] = $metric;
		$map['metric_col'] = $metric_col;
		$map['period'] = $period;
		$map['height'] = $height;
		$map['width'] = $width;
		return owa_template::getWidget('base.widgetSparkline', $map, false, $add_state);
	
	}
		
	function makeJson($array) {
		
		$json = '{';
		
		foreach ($array as $k => $v) {
			
			if (is_object($v)) {
				if (method_exists($v, 'toString')) {
					$v = $v->toString();
				} else {
					$v = '';
				}
				
			}
			
			$json .= sprintf('%s: "%s", ', $k, $v);
			
		}
		
		
		$json = substr($json, 0, -2);
		
		$json .= '}';
		
		return $json;
	
	}
	
	function headerActions() {
	
		return;
	}
	
	function footerActions() {
	
		return;
	}
	
	function makePagination($pagination, $map = array(), $add_state = true, $template = '') {
		
		$pages = '';
		//print_r($pagination);
		if ($pagination['max_page_num'] > 1) {
	
			$pages = '<div class="owa_pagination"><UL>';
			
			for ($i = 1; $i <= $pagination['max_page_num'];$i++) {
				
				if ($pagination['page_num'] != $i) {
					$new_map = array();
					$new_map = $map;
					$new_map['page'] = $i;
					$link = sprintf('<LI class="owa_reportPaginationControl"><a href="%s">%s</a></LI>', 
														$this->makeLink($new_map, $add_state), 
														$i);
				
				} else {
					
					$link = sprintf('<LI class="owa_reportPaginationControl">%s</LI>', $i);
				}
														
				$pages .= $link;
			}
			
			$pages .= '</UL></div>';
					
			
		
		}
		
		return $pages;
	}
	
	function get($name) {
	
		if (array_key_exists($name, $this->vars)) {
			return $this->vars[$name];
		} else {
			return false;
		}
		
	}
	
	function displayChart($id, $data, $width = '100%', $height = '200px') {
		
		if (!empty($data)) {
		
			$t = new owa_template;
			$t->set('dom_id', $id.'Chart');
			$t->set('data', $data);
			$t->set('width', $width);
			$t->set('height', $height);
			$t->set_template('chart_dom.tpl');
			return $t->fetch();
		} else {
		
			return false;
		}
	}

	function displaySparkline($id, $data, $width = '125px', $height = '35px') {
		
		if (!empty($data)) {
		
			$data_string = implode(',', $data);
			
			$t = new owa_template;
			$t->set('dom_id', $id.'Sparkline');
			$t->set('data', $data_string);
			$t->set('width', $width);
			$t->set('height', $height);
			$t->set_template('sparkline_dom.tpl');
			return $t->fetch();
		
		} else {
		
			return false;
		}
	}

	function makeTable($labels, $data, $table_class = '', $table_id = '', $is_sortable = true) {
	
		$t = new owa_template;
		
		if (!empty($table_id)) {
			$id = rand();
		}
		
		$t->set('table_id', $id.'Table');
		$t->set('data', $data);
		$t->set('labels', $labels);
		$t->set('class', $table_class);
		if ($is_sortable === true) {
			$t->set('sort_table_class', 'tablesorter');
		}
		
		$t->set_template('generic_table.tpl');
		
		return $t->fetch();	
	
	}	
}


?>