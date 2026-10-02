<?php
namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One message received from an IntakeQueue.
 */
final class IntakeMessage {

    /** @var mixed what the queue needs to ack, release or dead-letter it; opaque to a consumer */
    public $receipt;

    /** @var array|null the envelope, or null when the message did not decode to one */
    public $envelope;

    /** @var int how many times it has been received, this time included */
    public $receive_count;

    /** @var string the message as stored, for a dead letter of one that did not decode */
    public $raw;

    public function __construct( $receipt, $envelope, $receive_count, $raw = '' ) {

        $this->receipt       = $receipt;
        $this->envelope      = is_array( $envelope ) ? $envelope : null;
        $this->receive_count = max( 1, (int) $receive_count );
        $this->raw           = (string) $raw;
    }
}

?>
