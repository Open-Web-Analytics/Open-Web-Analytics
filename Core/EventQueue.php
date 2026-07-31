<?php
namespace OWA\Core;


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

class EventQueue  {

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

    // Mark a queued item as permanently broken (retries exhausted). No-op for
    // queue types that do not persist per-item retry state (e.g. the file
    // queue, which drains whole files and hands failures to the db queue).
    function markAsBroken( $id, $error_msg = '' ) {

        return false;
    }

    // Whether the given event has exhausted its retry budget and should stop
    // being retried. Base implementation never exhausts; the db queue overrides
    // this with the count/age caps from settings.
    function hasExhaustedRetries( $event ) {

        return false;
    }

    function prepareMessage( $msg ) {

        return serialize( $msg );
    }

    function decodeMessage ( $msg ) {

        return unserialize( $msg, array( 'allowed_classes' => self::allowedEventClasses() ) );
    }

    /**
     * The only classes a queued message is allowed to reconstruct.
     *
     * A queue blob is written by prepareMessage() from an object this instance
     * built, so nothing an HTTP caller sends can name a class here -- their
     * input lands in the event's PROPERTIES, and a string that looks like a
     * serialized object stays a string through the round trip.
     *
     * The allowlist exists for the case where the blob is no longer trustworthy:
     * anyone who can write to owa_queue_item (a SQL-injection foothold, stolen
     * database credentials, a restored-from-elsewhere table) would otherwise
     * have a straight path from "can write a row" to "can instantiate arbitrary
     * classes on the next queue run". This turns that into a decode failure.
     *
     * Resolved through the support-class factory rather than hardcoded, because
     * a module may substitute its own event class -- pinning
     * Base\Classes\Event would silently break such an install by decoding its
     * events into __PHP_Incomplete_Class.
     *
     * @return string[]
     */
    protected static function allowedEventClasses() {

        static $classes = null;

        if ( $classes === null ) {

            $classes = array( 'OWA\Module\Base\Classes\Event' );

            try {
                $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

                if ( is_object( $event ) ) {
                    $classes[] = get_class( $event );
                }

            } catch ( \Throwable $e ) {
                // Fall back to the base class. A decode that then fails is
                // visible as an unprocessable queue item, not a silent
                // widening of what may be instantiated.
            }

            $classes = array_values( array_unique( $classes ) );
        }

        return $classes;
    }
    
    function pruneArchive ( $interval ) {
        
        return false;
    }
}

?>