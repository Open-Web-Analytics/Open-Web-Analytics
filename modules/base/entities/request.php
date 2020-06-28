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
 * Page Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.0.0
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
                
        // needed?
        $inbound_visitor_id = new owa_dbColumn('inbound_visitor_id', 'OWA_DTD_BIGINT');
        //$inbound_visitor_id->setForeignKey('base.visitor');
        $this->setProperty($inbound_visitor_id);
        
        // needed?
        $inbound_session_id = new owa_dbColumn('inbound_session_id', 'OWA_DTD_BIGINT');
        //$inbound_session_id->setForeignKey('base.session');
        $this->setProperty($inbound_session_id);
        
        // needed anymore?
        $this->properties['feed_subscription_id'] = new owa_dbColumn;
        $this->properties['feed_subscription_id']->setDataType('OWA_DTD_BIGINT');
                
        //drop
        $this->properties['user_email'] = new owa_dbColumn;
        $this->properties['user_email']->setDataType('OWA_DTD_VARCHAR255');
        
        // drop these at some point
        $this->properties['hour'] = new owa_dbColumn;
        $this->properties['hour']->setDataType('OWA_DTD_TINYINT2');
        $this->properties['minute'] = new owa_dbColumn;
        $this->properties['minute']->setDataType('OWA_DTD_TINYINT2');
        $this->properties['second'] = new owa_dbColumn;
        $this->properties['second']->setDataType('OWA_DTD_TINYINT2');
        $this->properties['msec'] = new owa_dbColumn;
        $this->properties['msec']->setDataType('OWA_DTD_INT');
        
        // wrong data type
        $document_id = new owa_dbColumn('document_id', 'OWA_DTD_VARCHAR255');
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);
        
        // drop
        $this->properties['site'] = new owa_dbColumn;
        $this->properties['site']->setDataType('OWA_DTD_VARCHAR255');
    
        //drop
        $this->properties['os'] = new owa_dbColumn;
        $this->properties['os']->setDataType('OWA_DTD_VARCHAR255');
        
        //prior page
        $prior_document_id = new owa_dbColumn('prior_document_id', 'OWA_DTD_BIGINT');
        $prior_document_id->setForeignKey('base.document');
        $this->setProperty($prior_document_id);
        
        // drop
        $this->properties['is_comment'] = new owa_dbColumn;
        $this->properties['is_comment']->setDataType('OWA_DTD_TINYINT');
        
        $this->properties['is_entry_page'] = new owa_dbColumn;
        $this->properties['is_entry_page']->setDataType('OWA_DTD_TINYINT');
        $this->properties['is_browser'] = new owa_dbColumn;
        $this->properties['is_browser']->setDataType('OWA_DTD_TINYINT');
        $this->properties['is_robot'] = new owa_dbColumn;
        $this->properties['is_robot']->setDataType('OWA_DTD_TINYINT');
        $this->properties['is_feedreader'] = new owa_dbColumn;
        $this->properties['is_feedreader']->setDataType('OWA_DTD_TINYINT');
        
    }
}

?>