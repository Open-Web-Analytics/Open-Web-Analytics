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
 * Visitor Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_impression extends owa_entity {
	
	var $id = array('data_type' => OWA_DTD_BIGINT, 'is_primary_key' => true); // BIGINT,
	var $visitor_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $session_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $tag_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $placement_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $campaign_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $ad_group_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $ad_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $site_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $last_impression_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $last_impression_timestamp = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $timestamp = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $year = array('data_type' => OWA_DTD_INT); // INT,
	var $month = array('data_type' => OWA_DTD_INT); // INT,
	var $day = array('data_type' => OWA_DTD_INT); // INT,
	var $dayofyear = array('data_type' => OWA_DTD_INT); // INT,
	var $weekofyear = array('data_type' => OWA_DTD_INT); // INT,
	var $hour = array('data_type' => OWA_DTD_TINYINT2); // tinyINT,
	var $minute = array('data_type' => OWA_DTD_TINYINT2); // tinyINT,
	var $msec = array('data_type' => OWA_DTD_BIGINT); // INT,
	var $url = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $ua_id = array('data_type' => OWA_DTD_BIGINT); // BIGINT
	var $ip_address = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $host = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $host_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	
	function owa_impression() {
		
			$this->owa_entity();
			
		return;
			
	}
	
	
	
}



?>