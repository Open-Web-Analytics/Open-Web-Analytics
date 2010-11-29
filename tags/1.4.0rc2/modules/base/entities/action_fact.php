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
 * Action Event Fact Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_action_fact extends owa_entity {
	
	function __construct() {
		
		$this->setTableName('action_fact');
		
		$id = new owa_dbColumn('id', OWA_DTD_BIGINT);
		$id->setPrimaryKey();
		$this->setProperty($id);
		
		$visitor_id = new owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
		$visitor_id->setForeignKey('base.visitor');
		$this->setProperty($visitor_id);
		
		$session_id = new owa_dbColumn('session_id', OWA_DTD_BIGINT);
		$session_id->setForeignKey('base.session');
		$this->setProperty($session_id);
		
		$document_id = new owa_dbColumn('document_id', OWA_DTD_BIGINT);
		$document_id->setForeignKey('base.document');
		$this->setProperty($document_id);
		
		$site_id = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
		$site_id->setForeignKey('base.site', 'site_id');
		$this->setProperty($site_id);
		
		// wrong data type
		$ua_id = new owa_dbColumn('ua_id', OWA_DTD_BIGINT);
		$ua_id->setForeignKey('base.ua');
		$this->setProperty($ua_id);
		
		$host_id = new owa_dbColumn('host_id', OWA_DTD_BIGINT);
		$host_id->setForeignKey('base.host');
		$this->setProperty($host_id);
		
		// wrong data type
		$os_id = new owa_dbColumn('os_id', OWA_DTD_BIGINT);
		$os_id->setForeignKey('base.os');
		$this->setProperty($os_id);
		
		$timestamp = new owa_dbColumn('timestamp', OWA_DTD_INT);
		$this->setProperty($timestamp);
		
		$yyyymmdd = new owa_dbColumn('yyyymmdd', OWA_DTD_INT);
		$this->setProperty($yyyymmdd);
		
		$action_name = new owa_dbColumn('action_name', OWA_DTD_VARCHAR255);
		$this->setProperty($action_name);
		
		$action_label = new owa_dbColumn('action_label', OWA_DTD_VARCHAR255);
		$this->setProperty($action_label);
		
		$action_group = new owa_dbColumn('action_group', OWA_DTD_VARCHAR255);
		$this->setProperty($action_group);
		
		$numeric_value = new owa_dbColumn('numeric_value', OWA_DTD_INT);
		$this->setProperty($numeric_value);
	}
}

?>