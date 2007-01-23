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
	
	var $id; // BIGINT,
	var $visitor_id; // BIGINT,
	var $timestamp; // bigint,
	var $year; // INT,
	var $month; // INT,
	var $day; // TINYINT(2),
	var $dayofweek; // varchar(10),
	var $dayofyear; // INT,
	var $weekofyear; // INT,
	var $hour; // TINYINT(2),
	var $minute; // TINYINT(2),
	var $last_req; // BIGINT,
	var $num_pageviews; // INT,
	var $num_comments; // INT,
	var $is_repeat_visitor; // TINYINT(1),
	var $is_new_visitor; // TINYINT(1),
	var $prior_session_lastreq; //  BIGINT,
	var $prior_session_id; //  BIGINT,
	var $time_sinse_priorsession; //  INT,
	var $prior_session_year; //  INT(4),
	var $prior_session_month; //  varchar(255),
	var $prior_session_day; //  TINYINT(2),
	var $prior_session_dayofweek; //  int,
	var $prior_session_hour; //  TINYINT(2),
	var $prior_session_minute; //  TINYINT(2),
	var $os_id; //  varchar(255),
	var $ua_id; //  varchar(255),
	var $first_page_id; //  BIGINT,
	var $last_page_id; //  BIGINT,
	var $referer_id; //  BIGINT,
	var $host_id; //  varchar(255),
	var $site_id; //  varchar(255),
	var $is_robot; //  tinyint(1),
	var $is_browser; //  tinyint(1),
	var $is_feedreader; //  tinyint(1),
	
	function owa_session() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>