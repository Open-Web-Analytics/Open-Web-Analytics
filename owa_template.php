<?

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

require_once(OWA_INCLUDE_DIR.'/template_class.php');
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_CLASS_DIR.'settings.php');
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
	
	/**
	 * Params passed by calling caller
	 *
	 * @var array
	 */
	var $caller_params;
	
	function owa_template($module = null, $caller_params = null) {
		
		$this->caller_params = $caller_params;
			
		$c = &owa_coreAPI::configSingleton();
		$this->config = $c->fetch('base');
		// set template dir
		
		if(!empty($caller_params['module'])):
			$this->_setTemplateDir($module);
		else:
			$this->_setTemplateDir('base');
		endif;
		
		$this->time_now = owa_lib::time_now();
		
		return;
	}
	
	function _setTemplateDir($module) {
		
		$this->template_dir = OWA_BASE_DIR . '/modules/' . $module . '/templates/';
		
		return;
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
				break;
			case "internet explorer":
				$file = 'msie.png';
				break;
			case "firefox":
				$file = 'firefox.png';
				break;
			case "safari":
				$file = 'safari.png';
				break;
			case "opera":
				$file = 'opera.png';
				break;
			case "netscape":
				$file = 'netscape.png';
				break;
			case "mozilla":
				$file = 'mozilla.png';
				break;
			case "konqueror":
				$file = 'kon.png';
				break;
			case "camino":
				$file = 'camino.png';
				break; 
			
			
		}
		if (!empty($file)):
			return $icon = "<img align=\"baseline\" src=\"".$this->config['images_url']."/".$file."\">";
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
	
	function makeNavigation($nav) {
		
		$navigation = '<UL class="nav_links">';
		
		foreach($nav as $k => $v) {
			
			$navigation .= sprintf("<LI><a href=\"%s\">%s</a></LI>", 
									$this->makeLink(array('do' => $v['ref']), true), 
									$v['anchortext']);
			
		}
		
		$navigation .= '</UL>';
		
		return $navigation;
		
		
	}
	
	function daysAgo($time) {
		
		$now = mktime(23, 59, 59, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']);
		
		$days = ($now - $time) / (3600*24);
		
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
	
	/*
	 * Convienence method for making links to other reports
	 * 
	 * @var array $params
	 * @return string The link
	 */
	function reportLink($params) {
		
		$params['view'] = 'base.report';
		return $this->makeLink($params, true);
		
	}
	
	/**
	 * Makes Links, adds state to links optionaly.
	 *
	 * @param array $params
	 * @param boolean $add_state
	 * @return string
	 */
	function makeLink($params = array(), $add_state = false, $url = '') {
		
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
			foreach ($all_params as $n => $v) {
				
				$get .= $this->config['ns'].$n.'='.$v.'&';
			}
		endif;
		
		if (empty($url)):
			$url = $this->config['main_url'];
		endif;
		
		return sprintf($this->config['link_template'], $url, $get);
		
	}
	
	function makeAbsoluteLink($params, $url = '') {
		
		$get = '';
		
		if (!empty($params)):
			foreach ($params as $n => $v) {
				
				$get .= $this->config['ns'].$n.'='.$v.'&';
			}
		endif;
		
		if (empty($url)):
			$url = $this->config['main_absolute_url'];
		endif;
		
		return owa_coreAPI::makeAbsoluteLink($params, $url);
		//return sprintf($this->config['link_template'], $this->config['main_absolute_url'], $get);
		
	}
	
	
	
}


?>