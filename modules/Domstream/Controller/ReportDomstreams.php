<?php
namespace OWA\Module\Domstream\Controller;

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
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-import-type Bindings from \OWA\Core\Db
 * @phpstan-import-type Cell from \OWA\Module\Base\Classes\PaginatedResultSet
 * @phpstan-import-type ResultRow from \OWA\Module\Base\Classes\PaginatedResultSet
 */
class ReportDomstreams extends \OWA\Core\ReportController {

    const PER_PAGE = 50;

    const SCOPE = 'session';

    /** @var \OWA\Module\Base\Classes\ReportSegment|null */
    private $segment = null;

    public function action() {

        $page_path = (string) $this->getParam( 'pagePath' );

        if ( $page_path !== '' ) {

            $this->setTitle( \OWA\Core\CoreAPI::t( 'Recordings: ' ), $page_path );

        } else {

            $this->setTitle( \OWA\Core\CoreAPI::t( 'Recordings' ) );
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

        $this->set( 'domstreams', $this->asResultSet( $recordings, $total ) );
        $this->set( 'domstreams_total', $total );
        $this->set( 'domstreams_pagination', (object) [
            'page'        => $page,
            'total_pages' => (int) ceil( $total / self::PER_PAGE ),
        ] );

        $this->setSubview( 'domstream.reportDomstreams', [
            'template' => 'report_domstreams.php',
            'vars'     => [ 'domstreams', 'domstreams_pagination', 'domstreams_total',
                            'domstreams_filter_dimensions', 'domstreams_filter_metrics',
                            'domstreams_constraints', 'domstreams_segment_error' ] ] );
    }

    /**
     * The report's segment, made once from the request.
     *
     * @return \OWA\Module\Base\Classes\ReportSegment
     */
    private function segment() {

        if ( ! $this->segment ) {

            $this->segment = new \OWA\Module\Base\Classes\ReportSegment(
                $this->getParam( 'propertyId' ),
                $this->getParam( 'period' ),
                $this->getParam( 'startDate' ),
                $this->getParam( 'endDate' ),
                (string) $this->getParam( 'constraints' )
            );
        }

        return $this->segment;
    }

    /**
     * @return string the chunk table's name
     */
    private static function table() {

        return \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' )->getTableName();
    }

    /**
     * @param  string           $page_path '' for every page
     * @param  list<mixed>|null $subjects  session ids a segment selected, as ReportSegment::subjects() answers; null for no segment
     * @return array{sql: string, params: Bindings}
     */
    private function scopeClause( $page_path, $subjects ) {

        /*
         * Every Profile of the Property. None is no rows, not no restriction:
         * an empty IN list is a syntax error, and dropping the clause would list
         * every Property's recordings.
         */
        $sites  = \OWA\Module\Base\Classes\Cube\Cubes::siteIds( (string) $this->getParam( 'propertyId' ) );
        $where  = [ $sites ? 'site_id IN (' . implode( ',', array_fill( 0, count( $sites ), '?' ) ) . ')' : '1 = 0' ];
        $params = $sites;

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

        return [ 'sql' => implode( ' AND ', $where ), 'params' => $params ];
    }

    /**
     * A page of recordings, newest first: a row per recording_id.
     *
     * @param  string           $page_path '' for every page
     * @param  list<mixed>|null $subjects  as scopeClause() takes them
     * @param  int              $page      from 1
     * @return list<Row>
     */
    private function listRecordings( $page_path, $subjects, $page = 1 ) {

        $scope  = $this->scopeClause( $page_path, $subjects );
        $offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;

        // A recording is made on one Profile, and the player is asked by it.
        $sql = 'SELECT recording_id,'
             . ' MIN(site_id) AS site_id,'
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

        return $rows === null ? [] : (array) $rows;
    }

    /**
     * @param  string           $page_path '' for every page
     * @param  list<mixed>|null $subjects  as scopeClause() takes them
     * @return int
     */
    private function countRecordings( $page_path, $subjects ) {

        $scope = $this->scopeClause( $page_path, $subjects );

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(DISTINCT recording_id) AS total FROM ' . self::table()
            . ' WHERE ' . $scope['sql'], $scope['params'] );

        return $row ? (int) ( (array) $row )['total'] : 0;
    }

    /**
     * The recordings in a result set's shape, for the grid.
     *
     * resultsTotal is every recording the report lists, on all its pages: the
     * count domstreams_total holds. The set carries no page or resultsPerPage,
     * so OWA.dataGrid.range() adds no offset to it.
     *
     * @param  list<Row> $recordings this page of them, as listRecordings() answers it
     * @param  int       $total      every recording listed, as countRecordings() answers it
     * @return array{resultsRows: list<ResultRow>, resultsReturned: int, resultsTotal: int, guid: string}
     */
    private function asResultSet( array $recordings, $total ) {

        $rows = [];

        foreach ( $recordings as $r ) {

            $r       = (array) $r;
            $started = intdiv( (int) $r['started'], 1000000 );
            $length  = intdiv( (int) $r['length_ms'], 1000 );

            $rows[] = [
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
            ];
        }

        return [
            'resultsRows'     => $rows,
            'resultsReturned' => count( $rows ),
            'resultsTotal'    => $total,
            'guid'            => md5( json_encode( $rows ) ),
        ];
    }

    /**
     * The Play cell's data: what the grid's overlayLink formatter needs.
     *
     * `launch` is the player launcher (PlayerLauncher), which mints the
     * player's token when Play is chosen and opens the page. `url` is the page
     * it opens, '' when it is not on the Profile's domain or a subdomain of it:
     * no Play link then.
     *
     * @param  Row $r a row of listRecordings()
     * @return array{launch: string, url: string, width: int, height: int, label: string}
     */
    private function playerPayload( array $r ) {

        $template = new \OWA\Core\Template();

        return [
            'launch' => $template->makeLink( [
                'do'           => 'domstream.playerLauncher',
                'siteId'       => (string) $r['site_id'],
                'recording_id' => (string) $r['recording_id'],
            ] ),
            'url'    => PlayerLauncher::playableUrl( (string) $r['page_location'], (string) $r['site_id'] ),
            'width'  => (int) $r['viewport_w'],
            'height' => (int) $r['viewport_h'],
            'label'  => \OWA\Core\CoreAPI::t( 'Play' ),
        ];
    }

    /**
     * @param  int    $seconds
     * @return string h:mm:ss
     */
    private static function asClock( $seconds ) {

        $seconds = max( 0, (int) $seconds );

        return sprintf( '%d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 );
    }

    /**
     * @param  string      $type      dimension or metric
     * @param  string      $name
     * @param  string      $label
     * @param  mixed       $value
     * @param  string|null $formatted the value as shown; null for the value as a string
     * @param  string      $dataType
     * @return Cell
     */
    private static function cell( $type, $name, $label, $value, $formatted = null, $dataType = 'string' ) {

        return [
            'result_type'     => $type,
            'name'            => $name,
            'label'           => $label,
            'value'           => $value,
            'formatted_value' => $formatted === null ? (string) $value : $formatted,
            'data_type'       => $dataType,
        ];
    }
}
