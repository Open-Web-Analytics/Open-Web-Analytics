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

        $event = self::decodeBlob( $msg );

        if ( ! self::isUsableEvent( $event ) ) {

            \OWA\Core\CoreAPI::notice(
                'Queue message did not decode to a usable event and will be skipped.'
            );

            return false;
        }

        return $event;
    }

    /**
     * Whether a decoded blob is something the queue can actually drive.
     *
     * unserialize() with an allowed_classes list does not fail on a name outside
     * the list -- it hands back __PHP_Incomplete_Class, which throws on the first
     * method call. Callers used to invoke a method straight away, so one
     * undecodable message aborted the whole drain, and on the db queue the item
     * was never removed, so it threw again on every subsequent run: the queue
     * stopped permanently and grew from then on.
     *
     * Checked rather than trusted, so a bad message is one skipped item.
     */
    /**
     * unserialize() a queue blob without letting its diagnostics escape.
     *
     * A malformed blob is an expected input here, not an exceptional one: a
     * queue file is a log that a crash can leave half-written, and a stored row
     * may predate a class rename. unserialize() reports that with a PHP notice,
     * which callers of a routine drain should not have to see -- the outcome
     * they need is the return value, and isUsableEvent() gives it to them.
     *
     * A scoped error handler rather than '@': since PHP 8 the error-control
     * operator does not stop a custom handler (PHPUnit's, say) from being
     * invoked, so '@' silences the output but not the report. Installed for this
     * one call and restored immediately, including on the throw path -- the same
     * idiom tests/bootstrap_owa.php documents for its database probe.
     */
    protected static function decodeBlob( $blob ) {

        set_error_handler( static function () { return true; } );

        try {

            return unserialize(
                $blob, array( 'allowed_classes' => self::allowedEventClasses() )
            );

        } finally {

            restore_error_handler();
        }
    }

    protected static function isUsableEvent( $event ) {

        return is_object( $event )
            && ! ( $event instanceof \__PHP_Incomplete_Class )
            && method_exists( $event, 'wasReceived' );
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

            // Payloads queued BEFORE the PSR-4 relocation name the pre-namespace
            // class ('owa_event'). allowed_classes matches the name as written in
            // the blob, not what that name resolves to, so without the legacy
            // aliases such a message decodes to __PHP_Incomplete_Class -- and the
            // first method call on it throws, aborting the entire drain.
            //
            // Taken from the compat map rather than written out here, so a class
            // that gains an alias later needs no edit in this file. The aliases
            // are the same names class_alias() already resolves to the classes
            // above, so this widens nothing: it admits the old spelling of a
            // class that was admissible anyway.
            if ( function_exists( 'owa_compat_class_map' ) ) {

                $allowed = array_flip( $classes );

                foreach ( owa_compat_class_map() as $legacy => $fqcn ) {

                    if ( isset( $allowed[ $fqcn ] ) ) {

                        $classes[] = $legacy;
                    }
                }
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