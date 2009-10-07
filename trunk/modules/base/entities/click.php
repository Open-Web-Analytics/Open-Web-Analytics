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
		
	function owa_click() {
		
		return owa_click::__construct();
	}
	
	function __construct() {
		
		$this->setTableName('click');
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['last_impression_id'] = new owa_dbColumn;
		$this->properties['last_impression_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['visitor_id'] = new owa_dbColumn;
		$this->properties['visitor_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['session_id'] = new owa_dbColumn;
		$this->properties['session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['document_id'] = new owa_dbColumn;
		$this->properties['document_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['target_id'] = new owa_dbColumn;
		$this->properties['target_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['target_url'] = new owa_dbColumn;
		$this->properties['target_url']->setDataType(OWA_DTD_BIGINT);
		$this->properties['timestamp'] = new owa_dbColumn;
		$this->properties['timestamp']->setDataType(OWA_DTD_INT);
		$this->properties['year'] = new owa_dbColumn;
		$this->properties['year']->setDataType(OWA_DTD_INT);
		$this->properties['month'] = new owa_dbColumn;
		$this->properties['month']->setDataType(OWA_DTD_INT);
		$this->properties['day'] = new owa_dbColumn;
		$this->properties['day']->setDataType(OWA_DTD_INT);
		$this->properties['dayofyear'] = new owa_dbColumn;
		$this->properties['dayofyear']->setDataType(OWA_DTD_INT);
		$this->properties['weekofyear'] = new owa_dbColumn;
		$this->properties['weekofyear']->setDataType(OWA_DTD_INT);
		$this->properties['hour'] = new owa_dbColumn;
		$this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['minute'] = new owa_dbColumn;
		$this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['second'] = new owa_dbColumn;
		$this->properties['second']->setDataType(OWA_DTD_INT);
		$this->properties['msec'] = new owa_dbColumn;
		$this->properties['msec']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['click_x'] = new owa_dbColumn;
		$this->properties['click_x']->setDataType(OWA_DTD_INT);
		$this->properties['click_y'] = new owa_dbColumn;
		$this->properties['click_y']->setDataType(OWA_DTD_INT);
		$this->properties['page_width'] = new owa_dbColumn;
		$this->properties['page_width']->setDataType(OWA_DTD_INT);
		$this->properties['page_height'] = new owa_dbColumn;
		$this->properties['page_height']->setDataType(OWA_DTD_INT);
		$this->properties['position'] = new owa_dbColumn;
		$this->properties['position']->setDataType(OWA_DTD_INT);
		$this->properties['approx_position'] = new owa_dbColumn;
		$this->properties['approx_position']->setDataType(OWA_DTD_BIGINT);
		$this->properties['dom_element_x'] = new owa_dbColumn;
		$this->properties['dom_element_x']->setDataType(OWA_DTD_INT);
		$this->properties['dom_element_y'] = new owa_dbColumn;
		$this->properties['dom_element_y']->setDataType(OWA_DTD_INT);
		$this->properties['dom_element_name'] = new owa_dbColumn;
		$this->properties['dom_element_name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['dom_element_id'] = new owa_dbColumn;
		$this->properties['dom_element_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['dom_element_value'] = new owa_dbColumn;
		$this->properties['dom_element_value']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['dom_element_tag'] = new owa_dbColumn;
		$this->properties['dom_element_tag']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['dom_element_text'] = new owa_dbColumn;
		$this->properties['dom_element_text']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['tag_id'] = new owa_dbColumn;
		$this->properties['tag_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['placement_id'] = new owa_dbColumn;
		$this->properties['placement_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['campaign_id'] = new owa_dbColumn;
		$this->properties['campaign_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['ad_group_id'] = new owa_dbColumn;
		$this->properties['ad_group_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['ad_id'] = new owa_dbColumn;
		$this->properties['ad_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['site_id'] = new owa_dbColumn;
		$this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['ua_id'] = new owa_dbColumn;
		$this->properties['ua_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['ip_address'] = new owa_dbColumn;
		$this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['host'] = new owa_dbColumn;
		$this->properties['host']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['host_id'] = new owa_dbColumn;
		$this->properties['host_id']->setDataType(OWA_DTD_VARCHAR255);
	}
	
	
	
}



?>