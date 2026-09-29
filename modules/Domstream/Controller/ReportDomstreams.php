<?php
namespace OWA\Module\Domstream\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The recordings report: one row per recording, newest first.
 *
 * A permanent controller, not a report definition: this is a list of
 * recordings, not a metric grouped by a dimension.
 *
 * A recording is every chunk sharing a recording_id. Its row is those chunks
 * grouped: started at the first chunk's arrival, as long as its last sample
 * (MAX(offset_ms + duration_ms)), and counted in samples, clicks and key
 * presses. The samples themselves are never read here.
 *
 * Segmented like every report: the constraints select VISITS through the
 * shared ReportSegment, and the recordings made during them are listed. A
 * recording happens inside one visit and every dimension offered is a visit
 * property, so selecting people would return their recordings from other
 * visits too.
 */
class ReportDomstreams extends \OWA\Core\ReportController {

    const PER_PAGE = 50;

    const SCOPE = 'session';

    /** @var \OWA\Module\Base\Classes\ReportSegment|null */
    private $segment = null;

    function action() {

        $page_path = (string) $this->getParam( 'pagePath' );

        if ( $page_path !== '' ) {

            $this->setTitle( 'Recordings: ', $page_path );

        } else {

            $this->setTitle( 'Recordings' );
        }

        $filter = $this->segment()->options();
        $this->set( 'domstreams_filter_dimensions', $filter['dimensions'] );
        $this->set( 'domstreams_filter_metrics',    $filter['metrics'] );
        $this->set( 'domstreams_constraints',       $this->segment()->getConstraints() );

        $subjects = $this->segment()->subjects( self::SCOPE );

        if ( $this->segment()->getError() ) {

            $this->set( 'domstreams_segment_error', $this->segment()->getError() );
        }

        $page       = (int) $this->getParam( 'page' ) ?: 1;
        $recordings = $this->listRecordings( $page_path, $subjects, $page );
        $total      = $this->countRecordings( $page_path, $subjects );

        $this->set( 'domstreams', $this->asResultSet( $recordings ) );
        $this->set( 'domstreams_total', $total );
        $this->set( 'domstreams_pagination', (object) array(
            'page'        => $page,
            'total_pages' => (int) ceil( $total / self::PER_PAGE ),
        ) );

        $this->setSubview( 'domstream.reportDomstreams' );
    }

    private function segment() {

        if ( ! $this->segment ) {

            $this->segment = new \OWA\Module\Base\Classes\ReportSegment(
                $this->getParam( 'siteId' ),
                $this->getParam( 'period' ),
                $this->getParam( 'startDate' ),
                $this->getParam( 'endDate' ),
                (string) $this->getParam( 'constraints' )
            );
        }

        return $this->segment;
    }

    private static function table() {

        return \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' )->getTableName();
    }

    /**
     * @param  string     $page_path '' for every page
     * @param  array|null $subjects  session ids a segment selected, null for no segment
     * @return array sql, params
     */
    private function scopeClause( $page_path, $subjects ) {

        $where  = array( 'site_id = ?' );
        $params = array( (string) $this->getParam( 'siteId' ) );

        $bounds = $this->segment()->bounds();

        if ( $bounds ) {

            $where[]  = 'yyyymmdd BETWEEN ? AND ?';
            $params[] = $bounds['start'];
            $params[] = $bounds['end'];
        }

        if ( $page_path !== '' ) {

            $where[]  = 'page_path = ?';
            $params[] = $page_path;
        }

        if ( is_array( $subjects ) ) {

            if ( ! $subjects ) {

                $where[] = '1 = 0';

            } else {

                $where[] = 'session_id IN (' . implode( ',', array_fill( 0, count( $subjects ), '?' ) ) . ')';

                foreach ( $subjects as $id ) {

                    $params[] = $id;
                }
            }
        }

        return array( 'sql' => implode( ' AND ', $where ), 'params' => $params );
    }

    private function listRecordings( $page_path, $subjects, $page = 1 ) {

        $scope  = $this->scopeClause( $page_path, $subjects );
        $offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;

        $sql = 'SELECT recording_id,'
             . ' MIN(ts) AS started,'
             . ' MAX(offset_ms + duration_ms) AS length_ms,'
             . ' SUM(sample_count) AS samples,'
             . ' SUM(click_count) AS clicks,'
             . ' SUM(keypress_count) AS keypresses,'
             . ' MIN(page_location) AS page_location,'
             . ' MIN(viewport_w) AS viewport_w,'
             . ' MIN(viewport_h) AS viewport_h'
             . ' FROM ' . self::table()
             . ' WHERE ' . $scope['sql']
             . ' GROUP BY recording_id'
             . ' ORDER BY started DESC'
             . ' LIMIT ' . (int) self::PER_PAGE . ' OFFSET ' . (int) $offset;

        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results( $sql, $scope['params'] );

        return $rows === null ? array() : (array) $rows;
    }

    private function countRecordings( $page_path, $subjects ) {

        $scope = $this->scopeClause( $page_path, $subjects );

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(DISTINCT recording_id) AS total FROM ' . self::table()
            . ' WHERE ' . $scope['sql'], $scope['params'] );

        return $row ? (int) ( (array) $row )['total'] : 0;
    }

    private function asResultSet( array $recordings ) {

        $rows = array();

        foreach ( $recordings as $r ) {

            $r       = (array) $r;
            $started = intdiv( (int) $r['started'], 1000000 );
            $length  = intdiv( (int) $r['length_ms'], 1000 );

            $rows[] = array(
                'recorded'   => self::cell( 'dimension', 'recorded', 'Recorded', $started,
                                    date( 'M j, Y g:i a', $started ), 'number' ),
                'page'       => self::cell( 'dimension', 'page', 'Page',
                                    (string) $r['page_location'], (string) $r['page_location'] ),
                'length'     => self::cell( 'metric', 'length', 'Length', $length,
                                    self::asClock( $length ), 'number' ),
                'clicks'     => self::cell( 'metric', 'clicks', 'Clicks', (int) $r['clicks'],
                                    (string) (int) $r['clicks'], 'number' ),
                'keypresses' => self::cell( 'metric', 'keypresses', 'Key Presses', (int) $r['keypresses'],
                                    (string) (int) $r['keypresses'], 'number' ),
                'samples'    => self::cell( 'metric', 'samples', 'Events', (int) $r['samples'],
                                    (string) (int) $r['samples'], 'number' ),
                /*
                 * The player's parameters as the cell's DATA; the grid's
                 * overlayLink formatter turns them into the link.
                 */
                'play'       => self::cell( 'dimension', 'play', '', $this->playerPayload( $r ), 'Play' ),
            );
        }

        return array(
            'resultsRows'     => $rows,
            'resultsReturned' => count( $rows ),
            'resultsTotal'    => count( $rows ),
            'guid'            => md5( json_encode( $rows ) ),
        );
    }

    private function playerPayload( array $r ) {

        $template = new \OWA\Core\Template();

        $api_url = $template->makeOverlayApiLink( array(
            'recording_id' => (string) $r['recording_id'],
            'siteId'       => (string) $this->getParam( 'siteId' ),
            'module'       => 'domstream',
            'version'      => 'v1',
            'do'           => 'domstreams',
        ), 'recording_id' );

        $url = (string) $r['page_location'];

        if ( strpos( $url, '#' ) !== false ) {

            $url = explode( '#', $url )[0];
        }

        return array(
            'overlay' => trim( base64_encode( $template->makeParamString( array(
                'action'  => 'loadPlayer',
                'api_url' => $api_url,
            ), true, 'json' ) ), "\0" ),
            'url'    => $url,
            'width'  => (int) $r['viewport_w'],
            'height' => (int) $r['viewport_h'],
            'label'  => 'Play',
        );
    }

    private static function asClock( $seconds ) {

        $seconds = max( 0, (int) $seconds );

        return sprintf( '%d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 );
    }

    private static function cell( $type, $name, $label, $value, $formatted = null, $dataType = 'string' ) {

        return array(
            'result_type'     => $type,
            'name'            => $name,
            'label'           => $label,
            'value'           => $value,
            'formatted_value' => $formatted === null ? (string) $value : $formatted,
            'data_type'       => $dataType,
        );
    }
}

?>
