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

/**
 * page Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_request extends owa_entity {
	
	var $id = array('data_type' => OWA_DTD_BIGINT, 'is_primary_key' => true);
	var $visitor_id = array('data_type' => OWA_DTD_BIGINT); 
	var $session_id = array('data_type' => OWA_DTD_BIGINT);
	var $inbound_visitor_id = array('data_type' => OWA_DTD_BIGINT);
	var $inbound_session_id = array('data_type' => OWA_DTD_BIGINT);
	var $feed_subscription_id = array('data_type' => OWA_DTD_BIGINT);
	var $user_name = array('data_type' => OWA_DTD_VARCHAR255);
	var $user_email = array('data_type' => OWA_DTD_VARCHAR255);
	var $timestamp = array('data_type' => OWA_DTD_BIGINT, 'index' => true);
	var $last_req = array('data_type' => OWA_DTD_BIGINT);
	var $year = array('data_type' => OWA_DTD_INT);
	var $month = array('data_type' => OWA_DTD_INT);
	var $day = array('data_type' => OWA_DTD_TINYINT2);
	var $dayofweek = array('data_type' => OWA_DTD_VARCHAR10);
	var $dayofyear = array('data_type' => OWA_DTD_INT);
	var $weekofyear = array('data_type' => OWA_DTD_INT);
	var $hour = array('data_type' => OWA_DTD_TINYINT2);
	var $minute = array('data_type' => OWA_DTD_TINYINT2);
	var $second = array('data_type' => OWA_DTD_TINYINT2);
	var $msec = array('data_type' => OWA_DTD_INT);
	var $referer_id = array('data_type' => OWA_DTD_VARCHAR255);
	var $document_id = array('data_type' => OWA_DTD_VARCHAR255);
	var $site = array('data_type' => OWA_DTD_VARCHAR255);
	var $site_id = array('data_type' => OWA_DTD_VARCHAR255);
	var $ip_address = array('data_type' => OWA_DTD_VARCHAR255);
	var $host_id = array('data_type' => OWA_DTD_VARCHAR255);
	var $os = array('data_type' => OWA_DTD_VARCHAR255);
	var $os_id = array('data_type' => OWA_DTD_VARCHAR255);
	var $ua_id = array('data_type' => OWA_DTD_VARCHAR255);
	var $is_new_visitor = array('data_type' => OWA_DTD_TINYINT);
	var $is_repeat_visitor = array('data_type' => OWA_DTD_TINYINT);
	var $is_comment = array('data_type' => OWA_DTD_TINYINT);
	var $is_entry_page = array('data_type' => OWA_DTD_TINYINT);
	var $is_browser = array('data_type' => OWA_DTD_TINYINT);
	var $is_robot = array('data_type' => OWA_DTD_TINYINT);
	var $is_feedreader = array('data_type' => OWA_DTD_TINYINT);
	
	function owa_request() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>