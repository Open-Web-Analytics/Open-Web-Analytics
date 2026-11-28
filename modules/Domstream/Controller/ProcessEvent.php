<?php
namespace OWA\Module\Domstream\Controller;

/**
 * Stores a `domstream` tracking event: one chunk of a recording.
 *
 * Routed here by name (tracking.domstream, Module::addTrackingEventProcessor),
 * after logEvent()'s edge checks -- excluded IP, unregistered site --
 * which every tracking event passes. Not dispatched under
 * tracking.*, so Base's event-table handler never sees a chunk, and the spam
 * score is asked here instead: a chunk whose score reaches the threshold is
 * not stored (Classes\Spam\Score::admit()).
 *
 * IDEMPOTENT. The chunk's id is derived from its site, recording and seq, so a
 * chunk delivered twice finds itself already stored and is handled.
 */
class ProcessEvent extends \OWA\Core\Controller {

    /** Run by its handler through performAction(), never by a request. */
    const IN_PROCESS_ONLY = true;

    /** @var object */
    private $event;

    public function __construct( $params ) {

        $this->event = ( isset( $params['event'] ) && is_object( $params['event'] ) )
            ? $params['event']
            : null;

        parent::__construct( $params );
    }

    public function action() {

        if ( ! $this->event ) {

            \OWA\Core\CoreAPI::debug( 'domstream: no event to process.' );

            return;
        }

        if ( ! \OWA\Module\Base\Classes\Spam\Score::admit( $this->event ) ) {

            return;
        }

        $rows = \OWA\Module\Domstream\Classes\Chunk::fromEvent( $this->event );

        if ( ! $rows ) {

            \OWA\Core\CoreAPI::notice( 'domstream: chunk refused -- '
                . \OWA\Module\Domstream\Classes\Chunk::refusal() );

            return;
        }

        $this->set( 'stored', self::store( $rows ) );
    }

    /**
     * Write the chunk and its payload together, or neither.
     *
     * @param  array{chunk: array<string, int|string|null>, payload: array{id: int, yyyymmdd: int, payload: string}} $rows from Chunk::fromEvent()
     * @return bool  true when stored now or already stored
     */
    public static function store( array $rows ) {

        /*
         * Looked for within its own yyyymmdd's range only. That date is the
         * edge's, so a chunk sent again has a later one, and the range reaches
         * FACT_LOWER_BOUND_SLACK_DAYS back from it (Db::factDateRange()).
         */
        if ( \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' )->existsWithin( 'id',
                $rows['chunk']['id'], \OWA\Core\Db::factDateConstraint( $rows['chunk']['yyyymmdd'] ) ) ) {

            \OWA\Core\CoreAPI::debug( 'domstream: chunk already stored.' );

            return true;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->beginTransaction();

        $chunk = \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' );
        $chunk->setProperties( $rows['chunk'] );

        $payload = \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_payload' );
        $payload->setProperties( $rows['payload'] );

        if ( $chunk->create() !== true || $payload->create() !== true ) {

            $db->rollbackTransaction();

            \OWA\Core\CoreAPI::error( 'domstream: writing a chunk failed; rolled back.' );

            return false;
        }

        if ( $db->endTransaction() === false ) {

            $db->rollbackTransaction();

            \OWA\Core\CoreAPI::error( 'domstream: the commit was refused; nothing was written.' );

            return false;
        }

        return true;
    }
}
