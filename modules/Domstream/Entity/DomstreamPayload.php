<?php
namespace OWA\Module\Domstream\Entity;

/**
 * A chunk's samples: the tuples the recorder sent, as JSON, gzipped.
 *
 * Keyed by the chunk's day and id and read only for playback. Kept apart from
 * owa_domstream_chunk so the queries a report runs never read a sample.
 */
class DomstreamPayload extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'domstream_payload' );

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'yyyymmdd', OWA_DTD_INT ) );
        $this->setPartitionColumn( 'yyyymmdd' );

        // Keyed as the chunk is, day first (Update094).
        $this->setCompositePrimaryKey( [ 'yyyymmdd', 'id' ] );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'payload', OWA_DTD_BLOB ) );
    }
}
