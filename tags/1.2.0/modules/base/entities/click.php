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
	
	var $id = array('data_type' => OWA_DTD_BIGINT, 'is_primary_key' => true); // BIGINT,
	var $last_impression_id = array('data_type' => OWA_DTD_BIGINT); //BIGINT,
	var $visitor_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $session_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $document_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $target_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $target_url = array('data_type' => OWA_DTD_BIGINT); // VARCHAR(255),
	var $timestamp = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $year = array('data_type' => OWA_DTD_INT); // INT,
	var $month = array('data_type' => OWA_DTD_INT); // INT,
	var $day = array('data_type' => OWA_DTD_INT); // INT,
	var $dayofyear = array('data_type' => OWA_DTD_INT); // INT,
	var $weekofyear = array('data_type' => OWA_DTD_INT); // INT,
	var $hour = array('data_type' => OWA_DTD_TINYINT2); // TINYINT(2),
	var $minute = array('data_type' => OWA_DTD_TINYINT2); // TINYINT(2),
	var $second = array('data_type' => OWA_DTD_INT); // INT,
	var $msec = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $click_x = array('data_type' => OWA_DTD_INT); // INT,
	var $click_y = array('data_type' => OWA_DTD_INT); // INT,
	var $page_width = array('data_type' => OWA_DTD_INT); // INT,
	var $page_height = array('data_type' => OWA_DTD_INT); // INT,
	var $position = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $approx_position = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $dom_element_x = array('data_type' => OWA_DTD_INT); // INT,
	var $dom_element_y = array('data_type' => OWA_DTD_INT); // INT,
	var $dom_element_name = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $dom_element_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $dom_element_value = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $dom_element_tag = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $dom_element_text = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $tag_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $placement_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $campaign_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $ad_group_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $ad_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $site_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $ua_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $ip_address = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $host = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $host_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	
	function owa_click() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>