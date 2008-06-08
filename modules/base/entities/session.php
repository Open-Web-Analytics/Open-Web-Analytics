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
	
	function owa_session() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>