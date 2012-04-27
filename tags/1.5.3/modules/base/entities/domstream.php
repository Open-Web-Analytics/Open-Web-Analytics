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

require_once( OWA_BASE_CLASS_DIR . 'factTable.php');

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

class owa_domstream extends owa_factTable {
	
	function __construct() {
		
		$this->setTableName('domstream');
		
		// set common fact table columns
		$parent_columns = parent::__construct();
		
		foreach ($parent_columns as $pcolumn) {
				
			$this->setProperty($pcolumn);
		}
		
		// move to abstract
		//$this->properties['id'] = new owa_dbColumn;
		//$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		//$this->properties['id']->setPrimaryKey();
		
		// move to abstract
		//$visitor_id = new owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
		//$visitor_id->setForeignKey('base.visitor');
		//$this->setProperty($visitor_id);
		
		// move to abstract
		//$session_id = new owa_dbColumn('session_id', OWA_DTD_BIGINT);
		//$session_id->setForeignKey('base.session');
		//$this->setProperty($session_id);
		
		$document_id = new owa_dbColumn('document_id', OWA_DTD_BIGINT);
		$document_id->setForeignKey('base.document');
		$this->setProperty($document_id);
		
		// move to abstract
		//$site_id = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
		//$site_id->setForeignKey('base.site', 'site_id');
		//$this->setProperty($site_id);
	
		$domstream_guid = new owa_dbColumn('domstream_guid', OWA_DTD_BIGINT);
		$this->setProperty($domstream_guid);
		
		$this->properties['events'] = new owa_dbColumn;
		$this->properties['events']->setDataType(OWA_DTD_BLOB);
		$this->properties['duration'] = new owa_dbColumn;
		$this->properties['duration']->setDataType(OWA_DTD_INT);
		
		// move to abstract
		//$this->properties['timestamp'] = new owa_dbColumn;
		//$this->properties['timestamp']->setDataType(OWA_DTD_INT);
		
		// move to abstract
		//$this->properties['yyyymmdd'] = new owa_dbColumn;
		//$this->properties['yyyymmdd']->setDataType(OWA_DTD_INT);
		
		// needed?
		$this->properties['page_url'] = new owa_dbColumn;
		$this->properties['page_url']->setDataType(OWA_DTD_VARCHAR255);
	}
}

?>