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

require_once('owa_caller.php');

/**
 * Wordpress Caller class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_wp extends owa_caller {
	
	/**
	 * Constructor
	 *
	 * @return owa_wp
	 */
	function owa_wp($config = null) {
		
		$this->owa_caller($config);
		$this->e = &owa_error::get_instance();
		return;
	}
	
	function add_link_tracking($link) {
	
		if (!empty($_GET[$this->config['feed_subscription_id']])):
			return $link."&amp;"."from=feed"."&amp;".$this->config['ns'].$this->config['feed_subscription_id']."=".$_GET[$this->config['feed_subscription_id']];
		else:
			return $link."&amp;"."from=feed";
		endif;
		
		return;
	
	}
	
	function add_feed_tracking($binfo) {
		
		$guid = crc32(posix_getpid().microtime());
		
		return $binfo."&".$this->config['ns'].$this->config['feed_subscription_param']."=".$guid;
	}
	
	function add_tag() {
		
		if (empty($_COOKIE[$this->config['ns'].$this->config['visitor_param']]) && empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$bug  = "<script language=\"JavaScript\" type=\"text/javascript\">";
			$bug .= "document.write('<img src=\"".OWA_BASE_URL."?owa_action=".$this->config['first_hit_param']."\">');</script>";
			$bug .= "<noscript><img src=\"".OWA_BASE_URL."?owa_action=".$this->config['first_hit_param']."\"></noscript>";		
			echo $bug;
		endif;
		
		return;
	}
	
	function init_action() {
		
		if (isset($_GET['owa_action'])):
			$this->e->debug('Received special OWA request. OWA action = '.$_GET['owa_action']);
		endif;
			
		switch ($_GET['owa_action']) {
			
			case "first_hit":
				$this->first_request_handler();	
				exit;
				break;		
			case "graph":
				$this->graph_request_handler();
				exit;
				break;
				
		}
		
		return;
		
	}
	
	function process_comment() {
		
		$owa = new owa;
		$owa->process_comment();
		return;
		
	}

}

?>