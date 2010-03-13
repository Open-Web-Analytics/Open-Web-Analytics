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
 * Feed Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_feed_request extends owa_entity {
	/*

		var $id = array('data_type' => OWA_DTD_BIGINT, 'is_primary_key' => true); // BIGINT,
		var $visitor_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
		var $session_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
		var $document_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
		var $ua_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
		var $site_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
		var $site = array('data_type' => OWA_DTD_VARCHAR255); // **** DROP ****
		var $host_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
		var $host = array('data_type' => OWA_DTD_VARCHAR255); // **** DROP ****
		var $os_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
		var $feed_reader_guid = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
		var $subscription_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
		var $timestamp = array('data_type' => OWA_DTD_BIGINT); // bigint,
		var $month = array('data_type' => OWA_DTD_INT); // INT,
		var $day = array('data_type' => OWA_DTD_TINYINT2); // tinyint(2),
		var $dayofweek = array('data_type' => OWA_DTD_VARCHAR10); // varchar(10),
		var $dayofyear = array('data_type' => OWA_DTD_INT); // INT,
		var $weekofyear = array('data_type' => OWA_DTD_INT); // INT,
		var $year = array('data_type' => OWA_DTD_INT); //  INT,
		var $hour = array('data_type' => OWA_DTD_TINYINT2); //  tinyint(2),
		var $minute = array('data_type' => OWA_DTD_TINYINT2); //   tinyint(2),
		var $second = array('data_type' => OWA_DTD_TINYINT2); // tinyint(2),
		var $msec = array('data_type' => OWA_DTD_INT); // int,
		var $last_req = array('data_type' => OWA_DTD_BIGINT); // bigint,
		var $feed_format = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
		var $ip_address = array('data_type' => OWA_DTD_VARCHAR255); // **** DROP ****
		var $os = array('data_type' => OWA_DTD_VARCHAR255); // **** DROP ****
	
*/
	function owa_feed_request() {
		
		return owa_feed_request::__construct();
	}
	
	function __construct() {
	
		$this->setTableName('feed_request');
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['visitor_id'] = new owa_dbColumn;
		$this->properties['visitor_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['session_id'] = new owa_dbColumn;
		$this->properties['session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['document_id'] = new owa_dbColumn;
		$this->properties['document_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['ua_id'] = new owa_dbColumn;
		$this->properties['ua_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site_id'] = new owa_dbColumn;
		$this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
		//drop
		$this->properties['site'] = new owa_dbColumn;
		$this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['host_id'] = new owa_dbColumn;
		$this->properties['host_id']->setDataType(OWA_DTD_BIGINT);
		//drop
		$this->properties['host'] = new owa_dbColumn;
		$this->properties['host']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['os_id'] = new owa_dbColumn;
		$this->properties['os_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['feed_reader_guid'] = new owa_dbColumn;
		$this->properties['feed_reader_guid']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['subscription_id'] = new owa_dbColumn;
		$this->properties['subscription_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['timestamp'] = new owa_dbColumn;
		$this->properties['timestamp']->setDataType(OWA_DTD_BIGINT);
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
		$this->properties['year'] = new owa_dbColumn;
		$this->properties['year']->setDataType(OWA_DTD_INT);
		$this->properties['hour'] = new owa_dbColumn;
		$this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['minute'] = new owa_dbColumn;
		$this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['second'] = new owa_dbColumn;
		$this->properties['second']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['msec'] = new owa_dbColumn;
		$this->properties['msec']->setDataType(OWA_DTD_INT);
		$this->properties['last_req'] = new owa_dbColumn;
		$this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
		$this->properties['feed_format'] = new owa_dbColumn;
		$this->properties['feed_format']->setDataType(OWA_DTD_VARCHAR255);
		//drop
		$this->properties['ip_address'] = new owa_dbColumn;
		$this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
		//drop
		$this->properties['os'] = new owa_dbColumn;
		$this->properties['os']->setDataType(OWA_DTD_VARCHAR255);
		
	}
	
	
	
}



?>