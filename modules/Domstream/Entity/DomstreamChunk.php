<?php
namespace OWA\Module\Domstream\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One chunk of a recording: what it is, not what it holds.
 *
 * A recording is every chunk sharing a recording_id -- one per page load --
 * in seq order. The samples are in owa_domstream_payload under the same id,
 * so the roster and the report scan this table and never touch them.
 *
 * Attached to a page view by (session_id, page_view_seq): the event_seq of the
 * page view the tracker had sent when the chunk was flushed.
 *
 * Append-only. A recording's totals come from grouping its chunks -- length is
 * MAX(offset_ms + duration_ms), events SUM(sample_count) -- so no row is ever
 * updated.
 */
class DomstreamChunk extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'domstream_chunk' );

        // Lib::wideStringGuid( site_id|recording_id|seq ): derived from the
        // chunk's own content, so a redelivered chunk collapses on the key.
        $this->setProperty( $this->column( 'id', OWA_DTD_BIGINT, false ) );
        $this->properties['id']->setPrimaryKey();

        $this->setProperty( $this->column( 'yyyymmdd', OWA_DTD_INT, false ) );
        $this->setPartitionColumn( 'yyyymmdd' );

        $this->setProperty( $this->column( 'site_id', OWA_DTD_VARCHAR64, false ) );
        $this->setProperty( $this->column( 'recording_id', OWA_DTD_BIGINT, false ) );
        $this->setProperty( $this->column( 'seq', OWA_DTD_INT, false ) );

        $this->setProperty( $this->column( 'visitor_id', OWA_DTD_BIGINT, false ) );
        $this->setProperty( $this->column( 'session_id', OWA_DTD_BIGINT, false ) );
        $this->setProperty( $this->column( 'page_view_seq', OWA_DTD_INT ) );
        $this->setProperty( $this->column( 'page_location', OWA_DTD_VARCHAR1024 ) );
        // Cut from page_location at ingest, as raw events cut theirs: what
        // "recordings of this page" is asked by.
        $this->setProperty( $this->column( 'page_path', OWA_DTD_VARCHAR1024 ) );

        // Edge receipt, microseconds, as on every raw event.
        $this->setProperty( $this->column( 'ts', OWA_DTD_BIGINT, false ) );

        // Where this chunk sits in the recording, and how long it spans.
        $this->setProperty( $this->column( 'offset_ms', OWA_DTD_INT, false ) );
        $this->setProperty( $this->column( 'duration_ms', OWA_DTD_INT, false ) );

        $this->setProperty( $this->column( 'sample_count', OWA_DTD_INT, false ) );
        $this->setProperty( $this->column( 'click_count', OWA_DTD_INT, false ) );
        $this->setProperty( $this->column( 'keypress_count', OWA_DTD_INT, false ) );

        $this->setProperty( $this->column( 'viewport_w', OWA_DTD_INT ) );
        $this->setProperty( $this->column( 'viewport_h', OWA_DTD_INT ) );

        // Stored size of the payload, compressed.
        $this->setProperty( $this->column( 'bytes', OWA_DTD_INT, false ) );

        // A recording's chunks, for playback and the roster.
        $this->addCompositeIndex( 'site_recording', array( 'site_id', 'recording_id' ) );
    }

    /**
     * @param string $name
     * @param string $type     an OWA_DTD_* value
     * @param bool   $nullable
     * @return \OWA\Module\Base\Classes\DbColumn
     */
    private function column( $name, $type, $nullable = true ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );

        if ( $nullable ) {

            $column->setNullable();
        }

        return $column;
    }
}

?>
