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
 * DOM Stream Fact Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_domstream extends owa_entity {
	
	function __construct() {
		
		$this->setTableName('domstream');
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['visitor_id'] = new owa_dbColumn;
		$this->properties['visitor_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['visitor_id']->setForeignKey('owa_visitor');
		$this->properties['session_id'] = new owa_dbColumn;
		$this->properties['session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['session_id']->setForeignKey('owa_session');
		$this->properties['document_id'] = new owa_dbColumn;
		$this->properties['document_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['document_id']->setForeignKey('owa_document');
		$this->properties['events'] = new owa_dbColumn;
		$this->properties['events']->setDataType(OWA_DTD_TEXT);
		$this->properties['duration'] = new owa_dbColumn;
		$this->properties['duration']->setDataType(OWA_DTD_INT);
		$this->properties['timestamp'] = new owa_dbColumn;
		$this->properties['timestamp']->setDataType(OWA_DTD_INT);
		$this->properties['yyymmdd'] = new owa_dbColumn;
		$this->properties['yyymmdd']->setDataType(OWA_DTD_INT);
	}
	
	function owa_domstream() {
		
		return owa_domstream::__construct();
	}
}

?>