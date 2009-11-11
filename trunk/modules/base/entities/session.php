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
 * Session Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_session extends owa_entity {
	/*

	var $id = array('data_type' => OWA_DTD_BIGINT, 'is_primary_key' => true); // BIGINT,
	var $visitor_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $timestamp = array('data_type' => OWA_DTD_BIGINT, 'index' => true); // bigint,
	var $user_name = array('data_type' => OWA_DTD_VARCHAR255); // **** DROP ****
	var $user_email = array('data_type' => OWA_DTD_VARCHAR255); // **** DROP ****
	var $year = array('data_type' => OWA_DTD_INT); // INT,
	var $month = array('data_type' => OWA_DTD_INT);  // INT,
	var $day = array('data_type' => OWA_DTD_TINYINT2);  // TINYINT(2),
	var $dayofweek = array('data_type' => OWA_DTD_VARCHAR10); // varchar(10),
	var $dayofyear = array('data_type' => OWA_DTD_INT); // INT,
	var $weekofyear = array('data_type' => OWA_DTD_INT);  // INT,
	var $hour = array('data_type' => OWA_DTD_TINYINT2);  // TINYINT(2),
	var $minute = array('data_type' => OWA_DTD_TINYINT2); // TINYINT(2),
	var $last_req = array('data_type' => OWA_DTD_BIGINT);  // BIGINT,
	var $num_pageviews = array('data_type' => OWA_DTD_INT);  // INT,
	var $num_comments = array('data_type' => OWA_DTD_INT);  // INT,
	var $is_repeat_visitor = array('data_type' => OWA_DTD_TINYINT);  // TINYINT(1),
	var $is_new_visitor = array('data_type' => OWA_DTD_TINYINT); // TINYINT(1),
	var $prior_session_lastreq = array('data_type' => OWA_DTD_BIGINT); //  BIGINT,
	var $prior_session_id = array('data_type' => OWA_DTD_BIGINT);  //  BIGINT,
	var $time_sinse_priorsession = array('data_type' => OWA_DTD_INT); //  INT,
	var $prior_session_year = array('data_type' => OWA_DTD_TINYINT4);  //  INT(4),
	var $prior_session_month = array('data_type' => OWA_DTD_VARCHAR255);  //  varchar(255),
	var $prior_session_day = array('data_type' => OWA_DTD_TINYINT2);  //  TINYINT(2),
	var $prior_session_dayofweek = array('data_type' => OWA_DTD_INT); //  int,
	var $prior_session_hour = array('data_type' => OWA_DTD_TINYINT2);  //  TINYINT(2),
	var $prior_session_minute = array('data_type' => OWA_DTD_TINYINT2);  //  TINYINT(2),
	var $os = array('data_type' => OWA_DTD_VARCHAR255); // ****** DROP ******
	var $os_id = array('data_type' => OWA_DTD_VARCHAR255); //  varchar(255),
	var $ua_id = array('data_type' => OWA_DTD_VARCHAR255); //  varchar(255),
	var $first_page_id = array('data_type' => OWA_DTD_BIGINT); //  BIGINT,
	var $last_page_id = array('data_type' => OWA_DTD_BIGINT); //  BIGINT,
	var $referer_id = array('data_type' => OWA_DTD_BIGINT); //  BIGINT,
	var $ip_address = array('data_type' => OWA_DTD_VARCHAR255); // ****** DROP ******
	var $host = array('data_type' => OWA_DTD_VARCHAR255); // ****** DROP ******
	var $host_id = array('data_type' => OWA_DTD_VARCHAR255); //  varchar(255),
	var $source = array('data_type' => OWA_DTD_VARCHAR255); // 
	var $city = array('data_type' => OWA_DTD_VARCHAR255); // ****** DROP ******
	var $country = array('data_type' => OWA_DTD_VARCHAR255); // ****** DROP ******
	var $site = array('data_type' => OWA_DTD_VARCHAR255); // ****** DROP ******
	var $site_id = array('data_type' => OWA_DTD_VARCHAR255); //  varchar(255),
	var $is_robot = array('data_type' => OWA_DTD_TINYINT); //  tinyint(1),
	var $is_browser = array('data_type' => OWA_DTD_TINYINT); //  tinyint(1),
	var $is_feedreader = array('data_type' => OWA_DTD_TINYINT); //  tinyint(1),
	
	*/
	function owa_session() {
		
		return owa_session::__construct();			
	}
	
	function __construct() {
	
		$this->setTableName('session');
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['visitor_id'] = new owa_dbColumn;
		$this->properties['visitor_id']->setDataType(OWA_DTD_BIGINT);
		$ts =  new owa_dbColumn;
		$ts->setName('timestamp');
		$ts->setDataType(OWA_DTD_BIGINT);
		$ts->setIndex();
		$this->setProperty($ts);
		$this->properties['user_name'] = new owa_dbColumn;
		$this->properties['user_name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['user_email'] = new owa_dbColumn;
		$this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);
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
		$this->properties['last_req'] = new owa_dbColumn;
		$this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
		$this->properties['num_pageviews'] = new owa_dbColumn;
		$this->properties['num_pageviews']->setDataType(OWA_DTD_INT);
		$this->properties['num_comments'] = new owa_dbColumn;
		$this->properties['num_comments']->setDataType(OWA_DTD_INT);
		$this->properties['is_repeat_visitor'] = new owa_dbColumn;
		$this->properties['is_repeat_visitor']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_new_visitor'] = new owa_dbColumn;
		$this->properties['is_new_visitor']->setDataType(OWA_DTD_TINYINT);
		$this->properties['prior_session_lastreq'] = new owa_dbColumn;
		$this->properties['prior_session_lastreq']->setDataType(OWA_DTD_BIGINT);
		$this->properties['prior_session_id'] = new owa_dbColumn;
		$this->properties['prior_session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['time_sinse_priorsession'] = new owa_dbColumn;
		$this->properties['time_sinse_priorsession']->setDataType(OWA_DTD_INT);
		$this->properties['prior_session_year'] = new owa_dbColumn;
		$this->properties['prior_session_year']->setDataType(OWA_DTD_TINYINT4);
		$this->properties['prior_session_month'] = new owa_dbColumn;
		$this->properties['prior_session_month']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['prior_session_day'] = new owa_dbColumn;
		$this->properties['prior_session_day']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['prior_session_dayofweek'] = new owa_dbColumn;
		$this->properties['prior_session_dayofweek']->setDataType(OWA_DTD_INT);
		$this->properties['prior_session_hour'] = new owa_dbColumn;
		$this->properties['prior_session_hour']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['prior_session_minute'] = new owa_dbColumn;
		$this->properties['prior_session_minute']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['os'] = new owa_dbColumn;
		$this->properties['os']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['os_id'] = new owa_dbColumn;
		$this->properties['os_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['ua_id'] = new owa_dbColumn;
		$this->properties['ua_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['first_page_id'] = new owa_dbColumn;
		$this->properties['first_page_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['last_page_id'] = new owa_dbColumn;
		$this->properties['last_page_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['referer_id'] = new owa_dbColumn;
		$this->properties['referer_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['ip_address'] = new owa_dbColumn;
		$this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['host'] = new owa_dbColumn;
		$this->properties['host']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['host_id'] = new owa_dbColumn;
		$this->properties['host_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['source'] = new owa_dbColumn;
		$this->properties['source']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['city'] = new owa_dbColumn;
		$this->properties['city']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['country'] = new owa_dbColumn;
		$this->properties['country']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site'] = new owa_dbColumn;
		$this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site_id'] = new owa_dbColumn;
		$this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['is_robot'] = new owa_dbColumn;
		$this->properties['is_robot']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_browser'] = new owa_dbColumn;
		$this->properties['is_browser']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_feedreader'] = new owa_dbColumn;
		$this->properties['is_feedreader']->setDataType(OWA_DTD_TINYINT);
	}
	
	
	
}



?>