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
 * Click Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_click extends owa_entity {
	
	var $id; // BIGINT,
	var $last_impression_id; //BIGINT,
	var $visitor_id; // BIGINT,
	var $session_id; // BIGINT,
	var $document_id; // BIGINT,
	var $target_id; // BIGINT,
	var $target_url; // VARCHAR(255),
	var $timestamp; // BIGINT,
	var $year; // INT,
	var $month; // INT,
	var $day; // INT,
	var $dayofyear; // INT,
	var $weekofyear; // INT,
	var $hour; // TINYINT(2),
	var $minute; // TINYINT(2),
	var $second; // INT,
	var $msec; // VARCHAR(255),
	var $click_x; // INT,
	var $click_y; // INT,
	var $page_width; // INT,
	var $page_height; // INT,
	var $position; // BIGINT,
	var $approx_position; // BIGINT,
	var $dom_element_x; // INT,
	var $dom_element_y; // INT,
	var $dom_element_name; // VARCHAR(255),
	var $dom_element_id; // VARCHAR(255),
	var $dom_element_value; // VARCHAR(255),
	var $dom_element_tag; // VARCHAR(255),
	var $dom_element_text; // VARCHAR(255),
	var $tag_id; // BIGINT,
	var $placement_id; // BIGINT,
	var $campaign_id; // BIGINT,
	var $ad_group_id; // BIGINT,
	var $ad_id; // BIGINT,
	var $site_id; // VARCHAR(255),
	var $ua_id; // BIGINT,
	var $ip_address; // VARCHAR(255),
	var $host; // VARCHAR(255),
	var $host_id; // VARCHAR(255),
	
	function owa_click() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>