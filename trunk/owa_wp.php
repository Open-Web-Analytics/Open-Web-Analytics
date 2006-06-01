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
	
	function init_action() {
			
		switch ($_GET['owa_action']) {
			
			case "first_hit":
				$this->first_request_handler();	
				exit;
				break;		
			case "graph":
				$this->graph_request_handler();
				exit;
				break;
			case "update":
				$this->update_request_handler();
				
		}
		
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
	
	
	function process_comment() {
		
		$owa = new owa;
		$owa->process_comment();
		return;
		
	}
	
	function update_request_handler() {
		
		require_once('owa_update.php');

		$u = new owa_update;
		
		$version = $u->check_schema_version();
		
		print "Schema version is ".$version['value']."\n";
		
		if ($version['value'] == '1.0'):
			print "starting updated to 1.0.1";
			$u->to_1_0_1();
		endif;
		
		print "upgrade complete";
		
		return;
	}

}

?>