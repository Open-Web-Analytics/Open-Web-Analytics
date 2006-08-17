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

require_once('owa_env.php');

if(!class_exists('snoopy')):
	require_once(OWA_INCLUDE_DIR.'/Snoopy.class.php');
endif;
/**
 * Wrapper for Snoopy http request class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_http extends Snoopy {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Error handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * The length of text contained in the snippet
	 *
	 * @var string
	 */
	var $snip_len = 100;
	
	/**
	 * The string that is added to the beginning and
	 * end of snippet text.
	 *
	 * @var string
	 */
	var $snip_str = '...';
	
	/**
	 * Anchor information for a particular link
	 *
	 * @var array
	 */
	var $anchor_info;
	
	function owa_http() {
		
		$this->config = &owa_settings::get_settings();
		$this->e = &owa_error::get_instance();
		$this->agent = $this->config['owa_user_agent'];
		
		return;
	}
	
	/**
	 * Searches a fetched html document for the anchor of a specific url
	 *
	 * @param string $link
	 */
	function extract_anchor($link) {
		
		$escaped_link = str_replace(array("/", "?"), array("\/", "\?"), $link);
		$pattern = '/<a[^>]*href=\"'.$escaped_link.'\"[^>]*>(.*?)<\/a>/';
		
		$search = preg_match($pattern, $this->results, $matches);
		
		//print $pattern;
		
		$this->anchor_info =  array('anchor_tag' => $matches[0], 'anchor_text' => strip_tags($matches[0]));
		
		return;
	}
	
	/**
	 * Creates a text snippet of the portion of page where the 
	 * specific link is found.
	 * 
	 * Takes fully qualified URL for the link to search for.
	 *
	 * @param string $link
	 * @return string
	 */
	function extract_anchor_snippet($link){
		
		// Search the page for a specific anchor
		$this->extract_anchor($link);
	
		if(!empty($this->anchor_info['anchor_tag'])):
			
			// strip html from doc
			$nohtml = strip_tags(owa_lib::inputFilter($this->results));
			
			// calc len of the anchor text
			$atext_len = strlen($this->anchor_info['anchor_text']);
			
			// find position within document of the anchor text
			$start = strpos($nohtml, $this->anchor_info['anchor_text']);
			
			// Create first segment of snippet
			$part1 = substr($nohtml, $start-$this->snip_len, $this->snip_len);
			
			// Create second segment of snippet
			$part2 = substr($nohtml, $start+$atext_len, $this->snip_len);
			
			// Put humpty dumpy back together again and create actual snippet
			$snippet =  $this->snip_str.$part1.' <span class=\"snippet_anchor\">'.strip_tags($this->anchor_info['anchor_tag']).'</span> '.$part2.$this->snip_str;
		
		else:
		
			$snippet = '';
			
		endif;
		
		return $snippet;
		
	}
	
	function extract_title() {
		
		preg_match('~(</head>|<body>|(<title>\s*(.*?)\s*</title>))~i', $this->results, $m);
		
       	return $m[3];
	}
	
}


?>