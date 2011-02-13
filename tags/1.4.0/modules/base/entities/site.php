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
	
	function __construct() {
		
		$this->setTableName('site');
		$this->setCachable();
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
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
		$this->properties['settings'] = new owa_dbColumn;
		$this->properties['settings']->setDataType(OWA_DTD_TEXT);
	}
	
	function generateSiteId($domain) {
		
		return md5($domain);
	}
	
	function settingsGetFilter($value) {
		if ($value) {
			return unserialize($value);
		}
	}
	
	function settingsSetFilter($value) {
		owa_coreAPI::debug('hello rom setFilter');
		$value = serialize($value);
		owa_coreAPI::debug($value);
		return $value;
	}

	
}

?>