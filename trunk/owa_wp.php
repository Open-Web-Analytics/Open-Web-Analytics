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

require_once(OWA_BASE_CLASSES_DIR.'owa_caller.php');

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
		
		// needed because some of worpresses templates output prior to plugins being called
		// which breaks OWA redirects.
		ob_start();
		
		$this->owa_caller($config);
		
		return;
	}
	

	function add_link_tracking($link) {
	
		if (!empty($_GET[$this->config['feed_subscription_id']])):
			return $link."&amp;".$this->config['ns'].$this->config['source_param']."=feed"."&amp;".$this->config['ns'].$this->config['feed_subscription_id']."=".$_GET[$this->config['feed_subscription_id']];
		else:
			return $link."&amp;".$this->config['ns'].$this->config['source_param']."=feed";
		endif;
	
	}
	
	/**
	 * Wordpress filter method. Adds tracking to feed links.
	 * 
	 * @var string the feed link
	 * @return string link string with special tracking id
	 */
	function add_feed_tracking($binfo) {
		
		$guid = crc32(posix_getpid().microtime());
		
		return $binfo."&".$this->config['ns'].$this->config['feed_subscription_param']."=".$guid;
	}
	
	/**
	 * Convienence function for logging comments.
	 * 
	 * @return boolean 
	 */
	function logComment() {
		
		return $this->logEvent('base.processEvent',array('event' => 'base.new_comment'));
		
	}
	
	function handleSpecialActionRequest() {
		
		if(isset($_GET['owa_specialAction'])):
			$this->e->debug("special action received");
			echo $this->handleRequestFromUrl();
			exit;
		elseif(isset($_GET['owa_logAction'])):
			$this->e->debug("log action received");
			echo $this->logEventFromUrl($_GET);
			exit;
		else:
			return;
		endif;

	}
	

	

}

?>