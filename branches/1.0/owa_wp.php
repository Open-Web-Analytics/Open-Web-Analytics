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

require_once(OWA_BASE_CLASS_DIR.'client.php');

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
class owa_wp extends owa_client {
	
	/**
	 * Constructor
	 *
	 * @return owa_wp
	 */
	
	function __construct($config = null) {
		
		ob_start();
		
		return parent::__construct($config);
	
	}
	

	function add_link_tracking($link) {
		
		// check for presence of '?' which is not present under URL rewrite conditions
	
		if ($this->config['track_feed_links'] == true):
		
			if (strpos($link, "?") === false):
				// add the '?' if not found
				$link .= '?';
			endif;
			
			// setup link template
			$link_template = "%s&amp;%s=%s&amp;%s=%s";
				
			return sprintf($link_template,
						   $link,
						   $this->config['ns'].'medium',
						   'feed',
						   $this->config['ns'].$this->config['feed_subscription_param'],
						   $_GET[$this->config['ns'].$this->config['feed_subscription_param']]);
		else:
			return;
		endif;
	}
	
	/**
	 * Wordpress filter method. Adds tracking to feed links.
	 * 
	 * @var string the feed link
	 * @return string link string with special tracking id
	 */
	function add_feed_tracking($binfo) {
		
		if ($this->config['track_feed_links'] == true):
			$guid = crc32(getmypid().microtime());
		
			return $binfo."&amp;".$this->config['ns'].$this->config['feed_subscription_param']."=".$guid;
		else:
			return;
		endif;
	}
}

?>