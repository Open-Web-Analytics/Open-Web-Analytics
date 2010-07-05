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

class owa_exit extends owa_entity {
		
	function __construct() {
		
		$this->setTableName('exit');
		$this->setCachable();
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['url'] = new owa_dbColumn;
		$this->properties['url']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site_name'] = new owa_dbColumn;
		$this->properties['site_name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['site'] = new owa_dbColumn;
		$this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['anchortext'] = new owa_dbColumn;
		$this->properties['anchortext']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['page_title'] = new owa_dbColumn;
		$this->properties['page_title']->setDataType(OWA_DTD_VARCHAR255);
		
	}
	
	
	
}



?>