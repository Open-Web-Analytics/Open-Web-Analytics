<?php
namespace OWA\Module\Domstream\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Stores a `domstream` tracking event: one chunk of a recording.
 *
 * Routed here by name (tracking.domstream, Module::addTrackingEventProcessor),
 * after logEvent()'s edge checks -- robot, excluded IP, unregistered site, the
 * named-user gate -- which every tracking event passes. Not dispatched under
 * tracking.*, so Base's event-table handler never sees a chunk.
 *
 * IDEMPOTENT. The chunk's id is derived from its site, recording and seq, so a
 * chunk delivered twice finds itself already stored and is handled.
 */
class ProcessEvent extends \OWA\Core\Controller {

    /** @var object */
    private $event;

    function __construct( $params ) {

        $this->event = ( isset( $params['event'] ) && is_object( $params['event'] ) )
            ? $params['event']
            : null;

        parent::__construct( $params );
    }

    function action() {

        if ( ! $this->event ) {

            \OWA\Core\CoreAPI::debug( 'domstream: no event to process.' );

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
     * @param  array $rows from Chunk::fromEvent()
     * @return bool  true when stored now or already stored
     */
    public static function store( array $rows ) {

        $chunk = \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' );
        $chunk->load( $rows['chunk']['id'], 'id',
            \OWA\Core\Db::factDateConstraint( $rows['chunk']['yyyymmdd'] ) );

        if ( $chunk->wasPersisted() ) {

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

        $db->endTransaction();

        return true;
    }
}

?>
