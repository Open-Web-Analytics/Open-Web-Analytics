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
 * Site Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_site extends owa_entity {
	/*

	var $id = array('data_type' => OWA_DTD_SERIAL, 'auto_increment' => true);
	var $site_id = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $domain = array('data_type' => OWA_DTD_VARCHAR255, 'is_not_hull' => true); // VARCHAR(255),
	var $name = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $description = array('data_type' => OWA_DTD_TEXT); // TEXT,
	var $site_family = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255)
	
	*/
	function owa_site() {
		
		return owa_site::__construct();
	}
	
	function __construct() {
		
		$this->setTableName('site');
		$this->setCachable();
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_SERIAL);
		//$this->properties['id']->setPrimaryKey();
		$this->properties['id']->setAutoIncrement();
		$this->properties['site_id'] = new owa_dbColumn;
		$this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['domain'] = new owa_dbColumn;
		$this->properties['domain']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['name'] = new owa_dbColumn;
		$this->properties['name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['description'] = new owa_dbColumn;
		$this->properties['description']->setDataType(OWA_DTD_TEXT);
		$this->properties['site_family'] = new owa_dbColumn;
		$this->properties['site_family']->setDataType(OWA_DTD_VARCHAR255);
	}
	
	
	
}



?>