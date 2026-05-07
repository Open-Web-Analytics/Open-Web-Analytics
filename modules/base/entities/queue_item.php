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
 * Queued Event Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_queue_item extends owa_entity {

    function __construct() {

        $this->setTableName('queue_item');
        //$this->setCachable();

        // properties
        $id = new owa_dbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty($id);
        $event_type = new owa_dbColumn( 'event_type', OWA_DTD_VARCHAR255 );
        $this->setProperty($event_type);
        $priority = new owa_dbColumn( 'priority', OWA_DTD_INT );
        $this->setProperty($priority);
        $status = new owa_dbColumn( 'status', OWA_DTD_VARCHAR255 );
        $this->setProperty($status);
        $event = new owa_dbColumn( 'event', OWA_DTD_BLOB );
        $this->setProperty($event);
        $insertion_datestamp = new owa_dbColumn( 'insertion_datestamp', OWA_DTD_TIMESTAMP );
        $this->setProperty($insertion_datestamp);
        $insertion_timestamp = new owa_dbColumn( 'insertion_timestamp', OWA_DTD_INT );
        $this->setProperty($insertion_timestamp);
        $handled_timestamp = new owa_dbColumn( 'handled_timestamp', OWA_DTD_INT );
        $this->setProperty($handled_timestamp);
        $last_attempt_timestamp = new owa_dbColumn( 'last_attempt_timestamp', OWA_DTD_INT );
        $this->setProperty($last_attempt_timestamp);
        $not_before_timestamp = new owa_dbColumn( 'not_before_timestamp', OWA_DTD_INT );
        $this->setProperty($not_before_timestamp);
        $failed_attempt_count = new owa_dbColumn( 'failed_attempt_count', OWA_DTD_INT );
        $this->setProperty($failed_attempt_count);
        $is_assigned = new owa_dbColumn( 'is_assigned', OWA_DTD_BOOLEAN );
        $this->setProperty($is_assigned);
        $last_error_msg = new owa_dbColumn( 'last_error_msg', OWA_DTD_VARCHAR255 );
        $this->setProperty($last_error_msg);
        $handled_by = new owa_dbColumn( 'handled_by', OWA_DTD_VARCHAR255 );
        $this->setProperty($handled_by);
        $handler_duration = new owa_dbColumn( 'handler_duration', OWA_DTD_INT );
        $this->setProperty($handler_duration);
    }
}

?>