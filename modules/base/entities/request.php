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
	/*

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
	
	*/
	function owa_request() {
		
		return owa_request::__construct();		
	}
	
	function __construct() {
	
		$this->setTableName('request');
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['visitor_id'] = new owa_dbColumn;
		$this->properties['visitor_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['session_id'] = new owa_dbColumn;
		$this->properties['session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['inbound_visitor_id'] = new owa_dbColumn;
		$this->properties['inbound_visitor_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['inbound_session_id'] = new owa_dbColumn;
		$this->properties['inbound_session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['feed_subscription_id'] = new owa_dbColumn;
		$this->properties['feed_subscription_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['user_name'] = new owa_dbColumn;
		$this->properties['user_name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['user_email'] = new owa_dbColumn;
		$this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);
		$ts =  new owa_dbColumn;
		$ts->setName('timestamp');
		$ts->setDataType(OWA_DTD_BIGINT);
		$ts->setIndex();
		$this->setProperty($ts);
		$this->properties['last_req'] = new owa_dbColumn;
		$this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
		$this->properties['year'] = new owa_dbColumn;
		$this->properties['year']->setDataType(OWA_DTD_INT);
		$this->properties['month'] = new owa_dbColumn;
		$this->properties['month']->setDataType(OWA_DTD_INT);
		$this->properties['day'] = new owa_dbColumn;
		$this->properties['day']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['dayofweek'] = new owa_dbColumn;
		$this->properties['dayofweek']->setDataType(OWA_DTD_VARCHAR10);
		$this->properties['dayofyear'] = new owa_dbColumn;
		$this->properties['dayofyear']->setDataType(OWA_DTD_INT);
		$this->properties['weekofyear'] = new owa_dbColumn;
		$this->properties['weekofyear']->setDataType(OWA_DTD_INT);
		$this->properties['hour'] = new owa_dbColumn;
		$this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['minute'] = new owa_dbColumn;
		$this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['second'] = new owa_dbColumn;
		$this->properties['second']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['msec'] = new owa_dbColumn;
		$this->properties['msec']->setDataType(OWA_DTD_INT);
		$this->properties['referer_id'] = new owa_dbColumn;
		$this->properties['referer_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['document_id'] = new owa_dbColumn;
		$this->properties['document_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site'] = new owa_dbColumn;
		$this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site_id'] = new owa_dbColumn;
		$this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['ip_address'] = new owa_dbColumn;
		$this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['host_id'] = new owa_dbColumn;
		$this->properties['host_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['os'] = new owa_dbColumn;
		$this->properties['os']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['os_id'] = new owa_dbColumn;
		$this->properties['os_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['ua_id'] = new owa_dbColumn;
		$this->properties['ua_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['is_new_visitor'] = new owa_dbColumn;
		$this->properties['is_new_visitor']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_repeat_visitor'] = new owa_dbColumn;
		$this->properties['is_repeat_visitor']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_comment'] = new owa_dbColumn;
		$this->properties['is_comment']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_entry_page'] = new owa_dbColumn;
		$this->properties['is_entry_page']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_browser'] = new owa_dbColumn;
		$this->properties['is_browser']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_robot'] = new owa_dbColumn;
		$this->properties['is_robot']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_feedreader'] = new owa_dbColumn;
		$this->properties['is_feedreader']->setDataType(OWA_DTD_TINYINT);
	}
	
	
	
}



?>