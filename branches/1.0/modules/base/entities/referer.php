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
 * Referer Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_referer extends owa_entity {
	
	var $id; // BIGINT,
	var $url; // varchar(255),
	var $site_name; // varchar(255),
	var $site; // VARCHAR(255),
	var $query_terms; // varchar(255),
	var $refering_anchortext; // varchar(255),
	var $page_title; // varchar(255),
	var $snippet; // TEXT,
	var $is_searchengine; // tinyint(1),

	
	function owa_referer() {
		
		$this->owa_entity();
		
		return;
			
	}
	
	
	
}



?>