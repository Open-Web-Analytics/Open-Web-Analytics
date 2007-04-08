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
	
		var $id; // BIGINT,
		var $visitor_id; // BIGINT,
		var $session_id; // BIGINT,
		var $document_id; // BIGINT,
		var $ua_id; // VARCHAR(255),
		var $site_id; // VARCHAR(255),
		var $host_id; // BIGINT,
		var $os_id; // VARCHAR(255),
		var $feed_reader_guid; // VARCHAR(255),
		var $subscription_id; // BIGINT,
		var $timestamp; // bigint,
		var $month; // INT,
		var $day; // tinyint(2),
		var $dayofweek; // varchar(10),
		var $dayofyear; // INT,
		var $weekofyear; // INT,
		var $year; //  INT,
		var $hour; //  tinyint(2),
		var $minute; //   tinyint(2),
		var $second; // tinyint(2),
		var $msec; // int,
		var $last_req; // bigint,
		var $feed_format; // VARCHAR(255),
	
	function owa_feed_request() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>