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
	
	function owa_template($caller_params = null) {
		
		$this->caller_params = $caller_params;
		$this->config = &owa_settings::get_settings();
		$this->template_dir = $this->config['templates_dir'];
		
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
			
			
		}
		if (!empty($file)):
			return $icon = "<img src=\"".$this->config['images_url']."/".$file."\">";
		else:
			return $browser_type;
		endif;
		
		return;
	}
	
	/**
	 * Generates a link between reports
	 *
	 * @param array $query_params
	 * @return string
	 */
	function make_report_link($report, $query_params = null, $make_query_string = true) {
		
		if ($make_query_string == true):
			$get = $this->makeLinkQueryString($query_params);
		else:
			$get = '';
		endif;
		
		//Return URL
		return sprintf($this->config['inter_report_link_template'],
				$this->config['reporting_url'],
				$report,
				$get);
	}
	
	/**
	 * Generates a link between admin screens
	 *
	 * @param array $query_params
	 * @return string
	 */
	function make_admin_link($admin_page, $query_params = null, $make_query_string = true) {
		
		if ($make_query_string == true):
			$get = $this->makeLinkQueryString($query_params);
		else:
			$get = '';
		endif;
		
		//Return URL
		return sprintf($this->config['inter_admin_link_template'],
				$this->config['admin_url'],
				$admin_page,
				$get);
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
	
	function makeGraphLink($graph, $query_params = null) {
		
		$get = $this->makeLinkQueryString($query_params);
		
		//Return URL
		return sprintf($this->config['graph_link_template'],
				$this->config['action_url'],
				$graph,
				$get);
		
		
	}
	
}

?>