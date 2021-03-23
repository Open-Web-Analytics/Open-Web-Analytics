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
 * Abstract Event Queue
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.0.0
 */

class owa_eventQueue  {

    var $queue_name;
    
    function __construct( $map = array() ) {
        
        if ( ! isset( $map['queue_name'] ) ) {
            $this->queue_name = 'somequeue';
        } else {
            $this->queue_name = $map['queue_name'];    
        }
    }
    
    // deprecated
    function addToQueue( $event ) {
        
        return $this->sendMessage( $event );
    }
    
    function processQueue() {
        
        return false;
    }
    
    function connect() {
        
        return true;
    }
    
    function disconnect() {
        
        return true;
    }
    
    function sendMessage( $event) {
        
        return false;
    }
    
    function receiveMessage() {
        
        return false;
    }
    
    function deleteMessage( $id ) {
        
        return true;
    }
    
    function prepareMessage( $msg ) {
        
        return serialize( $msg );
    }
    
    function decodeMessage ( $msg ) {
        
        return unserialize( $msg );
    }
    
    function pruneArchive ( $interval ) {
        
        return false;
    }
}

?>