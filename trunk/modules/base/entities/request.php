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
 * page Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_request extends owa_factTable {
	
	function __construct() {
	
		$this->setTableName('request');
		$this->setSummaryLevel(0);
		
		// set common fact table columns
		$parent_columns = parent::__construct();
		
		foreach ($parent_columns as $pcolumn) {
				
			$this->setProperty($pcolumn);
		}
		
		// properties
		
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
		
		// needed?
		$inbound_visitor_id = new owa_dbColumn('inbound_visitor_id', OWA_DTD_BIGINT);
		$inbound_visitor_id->setForeignKey('base.visitor');
		$this->setProperty($inbound_visitor_id);
		
		// needed?
		$inbound_session_id = new owa_dbColumn('inbound_session_id', OWA_DTD_BIGINT);
		//$inbound_session_id->setForeignKey('base.session');
		$this->setProperty($inbound_session_id);
		
		// needed anymore?
		$this->properties['feed_subscription_id'] = new owa_dbColumn;
		$this->properties['feed_subscription_id']->setDataType(OWA_DTD_BIGINT);
		
		// move to abstract
		//$this->properties['user_name'] = new owa_dbColumn;
		//$this->properties['user_name']->setDataType(OWA_DTD_VARCHAR255);
		
		//drop
		$this->properties['user_email'] = new owa_dbColumn;
		$this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);
		
		// move to abstract
		//$ts =  new owa_dbColumn;
		//$ts->setName('timestamp');
		//$ts->setDataType(OWA_DTD_BIGINT);
		//$ts->setIndex();
		//$this->setProperty($ts);
		
		// move to abstract
		//$yyyymmdd =  new owa_dbColumn;
		//$yyyymmdd->setName('yyyymmdd');
		//$yyyymmdd->setDataType(OWA_DTD_INT);
		//$yyyymmdd->setIndex();
		//$this->setProperty($yyyymmdd);
		
		//$this->properties['last_req'] = new owa_dbColumn;
		//$this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
		/*
		$this->properties['year'] = new owa_dbColumn;
		$this->properties['year']->setDataType(OWA_DTD_INT);
		$this->properties['month'] = new owa_dbColumn;
		$this->properties['month']->setDataType(OWA_DTD_INT);
		$this->properties['day'] = new owa_dbColumn;
		$this->properties['day']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['dayofweek'] = new owa_dbColumn;
		$this->properties['dayofweek']->setDataType(OWA_DTD_VARCHAR10);
		$this->properties['dayofyear'] = new owa_dbColumn;
		$this->properties['dayofyear']->setDataType(OWA_DTD_INT);
		$this->properties['weekofyear'] = new owa_dbColumn;
		$this->properties['weekofyear']->setDataType(OWA_DTD_INT);
		*/
		// drop these at some point
		$this->properties['hour'] = new owa_dbColumn;
		$this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['minute'] = new owa_dbColumn;
		$this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['second'] = new owa_dbColumn;
		$this->properties['second']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['msec'] = new owa_dbColumn;
		$this->properties['msec']->setDataType(OWA_DTD_INT);
		
		// wrong data type
		// move to abstract
		//$referer_id = new owa_dbColumn('referer_id', OWA_DTD_VARCHAR255);
		//$referer_id->setForeignKey('base.referer');
		//$this->setProperty($referer_id);
		
		// wrong data type
		$document_id = new owa_dbColumn('document_id', OWA_DTD_VARCHAR255);
		$document_id->setForeignKey('base.document');
		$this->setProperty($document_id);
		
		// move to abstract
		//$site_id = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
		//$site_id->setForeignKey('base.site', 'site_id');
		//$this->setProperty($site_id);
		
		// drop
		$this->properties['site'] = new owa_dbColumn;
		$this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
	
		// move to abstract
		//$this->properties['ip_address'] = new owa_dbColumn;
		//$this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
		
		// move to abstract
		// wrong data type -- migrate
		//$host_id = new owa_dbColumn('host_id', OWA_DTD_VARCHAR255);
		//$host_id->setForeignKey('base.host');
		//$this->setProperty($host_id);
		
		// move to abstract
		// wrong data type
		//$os_id = new owa_dbColumn('os_id', OWA_DTD_VARCHAR255);
		//$os_id->setForeignKey('base.os');
		//$this->setProperty($os_id);
		
		//drop
		$this->properties['os'] = new owa_dbColumn;
		$this->properties['os']->setDataType(OWA_DTD_VARCHAR255);
		
		// move to abstract
		// wrong data type
		//$ua_id = new owa_dbColumn('ua_id', OWA_DTD_VARCHAR255);
		//$ua_id->setForeignKey('base.ua');
		//$this->setProperty($ua_id);
		
		//prior page
		$prior_document_id = new owa_dbColumn('prior_document_id', OWA_DTD_BIGINT);
		$prior_document_id->setForeignKey('base.document');
		$this->setProperty($prior_document_id);
		
		// move to abstract
		//$nps = new owa_dbColumn('num_prior_sessions', OWA_DTD_INT);
		//$this->setProperty($nps);
		
		// move to abstract
		//$this->properties['is_new_visitor'] = new owa_dbColumn;
		//$this->properties['is_new_visitor']->setDataType(OWA_DTD_TINYINT);
		
		// move to abstract
		//$this->properties['is_repeat_visitor'] = new owa_dbColumn;
		//$this->properties['is_repeat_visitor']->setDataType(OWA_DTD_TINYINT);
		
		// drop
		$this->properties['is_comment'] = new owa_dbColumn;
		$this->properties['is_comment']->setDataType(OWA_DTD_TINYINT);
		
		$this->properties['is_entry_page'] = new owa_dbColumn;
		$this->properties['is_entry_page']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_browser'] = new owa_dbColumn;
		$this->properties['is_browser']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_robot'] = new owa_dbColumn;
		$this->properties['is_robot']->setDataType(OWA_DTD_TINYINT);
		$this->properties['is_feedreader'] = new owa_dbColumn;
		$this->properties['is_feedreader']->setDataType(OWA_DTD_TINYINT);
		
		//move to abstract
		//$location_id = new owa_dbColumn('location_id', OWA_DTD_BIGINT);
		//$location_id->setForeignKey('base.location_dim');
		//$this->setProperty($location_id);
		
		//move to abstract
		//$language = new owa_dbColumn('language', OWA_DTD_VARCHAR255);
		//$this->setProperty($language);
	}
}

?>