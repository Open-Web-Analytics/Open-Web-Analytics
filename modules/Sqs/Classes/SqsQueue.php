<?php
namespace OWA\Module\Sqs\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The tracking intake on AWS SQS (PLAN 2.30.4a), mirroring the file queue
 * (Base\Classes\FileEventQueue): a main queue with a dead-letter queue, a
 * receive limit the queue enforces (here, the redrive policy itself), and
 * one-time replay through the contract.
 *
 *   send        SendMessage; a delay past 900 s is cut to 900, SQS's longest
 *   receive     ReceiveMessage, ten at a time, until $max or nothing comes
 *   ack         DeleteMessage
 *   release     ChangeMessageVisibility, at most 12 hours
 *   deadLetter  SendMessage to the dead-letter queue, then DeleteMessage
 *
 * One difference from the file queue: RETENTION. SQS keeps a message at most
 * fourteen days, and both queues are set to that; the file queue keeps one
 * until it is ingested. schedule-status warns long before it matters.
 *
 * A queue's URL holds the account id, so it is looked up once and kept in a
 * small file under the cache directory: a beacon costs one request, not two.
 */
class SqsQueue implements \OWA\Core\IntakeQueue {

    /** SQS's longest retention, for both queues. */
    const RETENTION = 1209600;

    /** SQS's longest delay on SendMessage. */
    const MAX_DELAY = 900;

    /** SQS's longest visibility timeout. */
    const MAX_VISIBILITY = 43200;

    /** How long a received message is hidden by default: longer than a drain runs. */
    const VISIBILITY = 300;

    /** @var string */
    private $name;

    /** @var int|null */
    private $max_receives;

    /** @var bool */
    private $is_dead_letter;

    /** @var SqsQueue|null */
    private $dlq;

    /** @var string|null */
    private $url;

    /** @var string|null */
    private $last_error;

    function __construct( $map = array() ) {

        $this->is_dead_letter = ! empty( $map['is_dead_letter'] );
        $this->name           = (string) ( $map['name'] ?? Sqs::queueName() );
        $this->max_receives   = $this->is_dead_letter ? null
            : max( 1, (int) ( $map['max_receives'] ?? \OWA\Module\Base\Classes\TrackerIngest::MAX_RECEIVES ) );
    }

    /** @return string */
    public function name() {

        return $this->name;
    }

    /** @return string|null what the last call that failed said */
    public function lastError() {

        return $this->last_error;
    }

    // ---------------------------------------------------------------------
    // The contract
    // ---------------------------------------------------------------------

    /**
     * Create the dead-letter queue, then the main queue with its redrive
     * policy pointing there. CreateQueue returns the queue that exists when
     * the attributes match; when they do not -- a limit changed -- they are
     * set on it instead.
     */
    public function provision() {

        $dlq = $this->deadLetterQueue();

        if ( $dlq && ! $dlq->provision() ) {

            $this->last_error = $dlq->lastError();

            return false;
        }

        $attributes = array(
            'MessageRetentionPeriod' => (string) self::RETENTION,
            'VisibilityTimeout'      => (string) self::VISIBILITY,
        );

        if ( $dlq ) {

            $arn = $this->call( function ( $c ) use ( $dlq ) {
                return $c->getQueueAttributes( array( 'QueueUrl' => $dlq->url( false ), 'AttributeNames' => array( 'QueueArn' ) ) );
            }, false );

            if ( ! $arn ) {

                return false;
            }

            $attributes['RedrivePolicy'] = json_encode( array(
                'deadLetterTargetArn' => $arn['Attributes']['QueueArn'],
                'maxReceiveCount'     => $this->max_receives,
            ) );
        }

        $created = $this->call( function ( $c ) use ( $attributes ) {

            try {

                return $c->createQueue( array( 'QueueName' => $this->name, 'Attributes' => $attributes ) );

            } catch ( \Aws\Sqs\Exception\SqsException $e ) {

                if ( $e->getAwsErrorCode() !== 'QueueAlreadyExists' && $e->getAwsErrorCode() !== 'QueueNameExists' ) {

                    throw $e;
                }

                $url = $c->getQueueUrl( array( 'QueueName' => $this->name ) )['QueueUrl'];
                $c->setQueueAttributes( array( 'QueueUrl' => $url, 'Attributes' => $attributes ) );

                return array( 'QueueUrl' => $url );
            }
        }, false );

        if ( ! $created ) {

            return false;
        }

        $this->remember( $created['QueueUrl'] );

        return true;
    }

    public function send( array $envelope, $delay = 0, $replayed = false ) {

        $body = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        if ( $body === false ) {

            return false;
        }

        $attributes = $replayed ? array( 'owa_replayed' => '1' ) : array();

        return $this->sendBody( $body, (int) $delay, $attributes );
    }

    public function receive( $max, $visibility ) {

        $max        = max( 1, (int) $max );
        $visibility = min( self::MAX_VISIBILITY, max( 0, (int) $visibility ) );
        $messages   = array();

        while ( count( $messages ) < $max ) {

            $got = $this->call( function ( $c ) use ( $max, $messages, $visibility ) {
                return $c->receiveMessage( array(
                    'QueueUrl'              => $this->url(),
                    'MaxNumberOfMessages'   => min( 10, $max - count( $messages ) ),
                    'VisibilityTimeout'     => $visibility,
                    'WaitTimeSeconds'       => 0,
                    'AttributeNames'        => array( 'ApproximateReceiveCount' ),
                    'MessageAttributeNames' => array( 'All' ),
                ) );
            } );

            $batch = $got ? (array) ( $got['Messages'] ?? array() ) : array();

            if ( ! $batch ) {

                break;
            }

            foreach ( $batch as $m ) {

                $messages[] = self::message( $m );
            }
        }

        return $messages;
    }

    public function ack( \OWA\Core\IntakeMessage $message ) {

        return (bool) $this->call( fn ( $c ) => $c->deleteMessage(
            array( 'QueueUrl' => $this->url(), 'ReceiptHandle' => $message->receipt ) ) );
    }

    public function release( \OWA\Core\IntakeMessage $message, $delay ) {

        return (bool) $this->call( fn ( $c ) => $c->changeMessageVisibility( array(
            'QueueUrl'          => $this->url(),
            'ReceiptHandle'     => $message->receipt,
            'VisibilityTimeout' => min( self::MAX_VISIBILITY, max( 0, (int) $delay ) ),
        ) ) );
    }

    public function deadLetter( \OWA\Core\IntakeMessage $message, $reason ) {

        $dlq = $this->deadLetterQueue();

        if ( ! $dlq ) {

            return false;
        }

        $attributes = array( 'owa_reason' => (string) $reason, 'owa_dead_at' => (string) time() );

        if ( $message->replayed ) {

            $attributes['owa_replayed'] = '1';
        }

        // The body as it was: an envelope, or a message that never decoded.
        $body = $message->envelope !== null
            ? json_encode( $message->envelope, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE )
            : $message->raw;

        if ( ! $dlq->sendBody( (string) $body, 0, $attributes ) ) {

            $this->last_error = $dlq->lastError();

            return false;
        }

        return $this->ack( $message );
    }

    public function deadLetterQueue() {

        if ( $this->is_dead_letter ) {

            return null;
        }

        if ( ! $this->dlq ) {

            $this->dlq = new self( array( 'name' => Sqs::deadLetterName( $this->name ), 'is_dead_letter' => true ) );
        }

        return $this->dlq;
    }

    /** One GetQueueAttributes call: nothing visible. Approximate, as SQS says. */
    public function isProbablyEmpty() {

        $a = $this->attributes( array( 'ApproximateNumberOfMessages' ) );

        return $a !== null && (int) ( $a['ApproximateNumberOfMessages'] ?? 0 ) === 0;
    }

    /** Messages waiting, visible or not; the oldest's age is CloudWatch's to tell, not the API's. */
    public function stats() {

        $a = $this->attributes( array( 'ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible',
                                       'ApproximateNumberOfMessagesDelayed' ) );

        return array(
            'messages'   => $a === null ? null : array_sum( array_map( 'intval', $a ) ),
            'oldest_age' => null,
        );
    }

    // ---------------------------------------------------------------------
    // The rest
    // ---------------------------------------------------------------------

    /**
     * The queue's URL: from this object, else the file it was kept in, else
     * GetQueueUrl. A queue that does not exist yet is provisioned, when
     * $provision allows, as the file queue makes its directories on first use.
     *
     * @param  bool $provision
     * @return string|null
     */
    public function url( $provision = true ) {

        if ( $this->url ) {

            return $this->url;
        }

        $file = $this->urlFile();

        if ( $file && is_file( $file ) ) {

            $url = trim( (string) @file_get_contents( $file ) );

            if ( $url !== '' ) {

                return $this->url = $url;
            }
        }

        try {

            $url = Sqs::client()->getQueueUrl( array( 'QueueName' => $this->name ) )['QueueUrl'];

        } catch ( \Aws\Sqs\Exception\SqsException $e ) {

            $missing = in_array( $e->getAwsErrorCode(),
                array( 'AWS.SimpleQueueService.NonExistentQueue', 'QueueDoesNotExist' ), true );

            if ( $missing && $provision && $this->provision() ) {

                return $this->url;
            }

            $this->last_error = $e->getAwsErrorMessage() ?: $e->getMessage();

            return null;
        }

        $this->remember( $url );

        return $this->url;
    }

    /** Keep the URL, for this process and the next. */
    private function remember( $url ) {

        $this->url = (string) $url;

        $file = $this->urlFile();

        if ( $file && ( is_dir( dirname( $file ) ) || @mkdir( dirname( $file ), 0755, true ) ) ) {

            @file_put_contents( $file . '.tmp', $this->url, LOCK_EX ) && @rename( $file . '.tmp', $file );
        }
    }

    /** @return string|null */
    private function urlFile() {

        $dir = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'cache_dir' );

        return $dir !== '' ? rtrim( $dir, '/' ) . '/sqs/' . $this->name . '.url' : null;
    }

    /** @return array|null */
    private function attributes( array $names ) {

        $got = $this->call( fn ( $c ) => $c->getQueueAttributes(
            array( 'QueueUrl' => $this->url(), 'AttributeNames' => $names ) ) );

        return $got ? (array) ( $got['Attributes'] ?? array() ) : null;
    }

    /** SendMessage with a body as it stands. */
    private function sendBody( $body, $delay, array $attributes ) {

        $args = array(
            'QueueUrl'     => $this->url(),
            'MessageBody'  => (string) $body,
            'DelaySeconds' => min( self::MAX_DELAY, max( 0, (int) $delay ) ),
        );

        foreach ( $attributes as $k => $v ) {

            $args['MessageAttributes'][ $k ] = array( 'DataType' => 'String', 'StringValue' => (string) $v );
        }

        return (bool) $this->call( fn ( $c ) => $c->sendMessage( $args ) );
    }

    /** A received SQS message as the contract's. */
    private static function message( array $m ) {

        $attr = function ( $name ) use ( $m ) {
            return $m['MessageAttributes'][ $name ]['StringValue'] ?? null;
        };

        $envelope = json_decode( (string) ( $m['Body'] ?? '' ), true );

        return new \OWA\Core\IntakeMessage(
            (string) $m['ReceiptHandle'],
            is_array( $envelope ) ? $envelope : null,
            (int) ( $m['Attributes']['ApproximateReceiveCount'] ?? 1 ),
            (string) ( $m['Body'] ?? '' ),
            $attr( 'owa_replayed' ) === '1',
            $attr( 'owa_reason' ),
            $attr( 'owa_dead_at' ) );
    }

    /**
     * One SDK call, its failure recorded rather than thrown: a beacon that
     * cannot be queued must not take log.php down with it.
     *
     * @param  callable $fn
     * @param  bool     $needs_url  false for provision()'s own calls, which make the queue
     * @return \Aws\Result|array|null
     */
    private function call( callable $fn, $needs_url = true ) {

        try {

            if ( $needs_url && $this->url === null && ! $this->url() ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'SQS %s: %s', $this->name, $this->last_error ?: 'no queue URL' ) );

                return null;
            }

            return $fn( Sqs::client() );

        } catch ( \Aws\Exception\AwsException $e ) {

            $this->last_error = $e->getAwsErrorMessage() ?: $e->getMessage();

        } catch ( \Throwable $t ) {

            $this->last_error = $t->getMessage();
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'SQS %s: %s', $this->name, $this->last_error ) );

        return null;
    }
}

?>
