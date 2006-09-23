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
		$this->controller = new owa;
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
	
	function logComment() {
		
		return $this->controller->logEvent('new_comment');
		
	}
	
	/**
	 * Installation Controller
	 *
	 * @param string $type
	 * @param array $params
	 * @return boolean
	 */
	function install($type, $params = '') {
		
		$this->config['fetch_config_from_db'] = false;
	    $installer = &owa_installer::get_instance($params);	   
	    $install_check = $installer->plugins[$type]->check_for_schema();
	    
	    if ($install_check == false):
		    //Install owa schema
	    	$status = $installer->plugins[$type]->install();
	    	$default_site = $installer->plugins[$type]->addDefaultSite();
	    else:
	    	// owa already installed
	    	$status = false;
	    endif;
	    
	    return $status;
		
	}
	


}

?>