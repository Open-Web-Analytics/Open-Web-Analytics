<?php
namespace OWA\Module\Domstream\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A chunk's samples: the tuples the recorder sent, as JSON, gzipped.
 *
 * Keyed by the chunk's id and read only for playback. Kept apart from
 * owa_domstream_chunk so the queries a report runs never read a sample.
 */
class DomstreamPayload extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'domstream_payload' );

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'yyyymmdd', OWA_DTD_INT ) );
        $this->setPartitionColumn( 'yyyymmdd' );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'payload', OWA_DTD_BLOB ) );
    }
}

?>
