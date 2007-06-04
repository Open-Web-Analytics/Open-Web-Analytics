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

class owa_visitor extends owa_entity {
	
	var $id; // BIGINT,
	var $user_name; // VARCHAR(255),
	var $user_email; //  varchar(255),
	var $first_session_id; // BIGINT,
	var $first_session_year; // INT,
	var $first_session_month; // varchar(255),
	var $first_session_day; // INT,
	var $first_session_dayofyear; // INT,
	var $first_session_timestamp; // BIGINT,
	var $last_session_id; // BIGINT,
	var $last_session_year; // INT,
	var $last_session_month; // varchar(255),
	var $last_session_day; // INT,
	var $last_session_dayofyear; // INT,
	
	function owa_session() {
		
			$this->owa_entity();
			
		return;
			
	}
	
	
	
}



?>