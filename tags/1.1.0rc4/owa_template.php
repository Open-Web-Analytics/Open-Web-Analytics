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
				$this->e->error(sprintf('%s was not found in any template directory.', $file));
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
			
		}
		if (!empty($file)):
			
			return sprintf('<img alt="%s" align="baseline" src="%s">', $name, $this->makeImageLink($file));
		else:
			return $browser_type;
		endif;
		
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
	function makeNavigation($nav) {
		
		if (!empty($nav)):
			$navigation = '<UL>';
			
			foreach($nav as $k => $v) {
				
				if($v['ref'] == $this->caller_params['view'] || $v['ref'] == $this->caller_params['subview']):
				
					$navigation .= sprintf('<LI ><a class="here" href="%s">%s</a></LI>', 
											$this->makeLink(array('do' => $v['ref']), true), 
											$v['anchortext']);
					
				else:
											
					$navigation .= sprintf("<LI><a href=\"%s\">%s</a></LI>", 
											$this->makeLink(array('do' => $v['ref']), true), 
											$v['anchortext']);
				
				endif;
			}
			
			$navigation .= '</UL>';
			
			return $navigation;
		else:
			return false;
		endif;
		
	}
	
	function makeTwoLevelNav($top, $sub) {
		if (!empty($top)):
		$navigation = '<UL id="globalnav"><li class="spacer">&nbsp &nbsp</LI>';
			
		foreach($top as $k => $v) {
			
			if($v['ref'] == $this->caller_params['view'] || $v['ref'] == $this->caller_params['subview'] || $v['ref'] == $this->caller_params['nav_tab']):
				
				$sub_nav = $this->makeNavigation($sub);
				
				if (empty($sub_nav)):
					$sub_nav = '<UL><li class="spacer">&nbsp &nbsp</LI></UL>';
				endif;
				
				$navigation .= sprintf('<LI><a class="here" href="%s">%s</a>%s</LI>', 
											$this->makeLink(array('do' => $v['ref']), true), 
											$v['anchortext'], $sub_nav);
					
			else:
				
				$navigation .= sprintf("<LI><a href=\"%s\">%s</a></LI>", 
											$this->makeLink(array('do' => $v['ref']), true), 
											$v['anchortext']);
											
			endif;
			
			
		}
		
		$navigation .= '</UL>';
			
			return $navigation;
		else:
			return false;
		endif;
		
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
		
		//Loads link state passed by caller
		if ($add_state == true):
			if (!empty($this->caller_params['link_state'])):
				foreach ($this->caller_params['link_state'] as $name => $value) {
					if (!empty($value)):
						$all_params[$name] = $value;	
					endif;
				}
			endif;
		endif;
		
		// Load overrides
		if (!empty($params)):
			foreach ($params as $name => $value) {
				if (!empty($value)):
					$all_params[$name] = $value;	
				endif;
			}
		endif;
		
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
	
		return str_replace(array('&', '"', "'", '<', '>' ), array('&amp;' , '&quot;', '&apos;' , '&lt;' , '&gt;'), $string);

	
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
	
	
}


?>