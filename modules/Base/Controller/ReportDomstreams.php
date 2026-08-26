<?php
namespace OWA\Module\Base\Controller;


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
 * Domstreams Report Controller
 *
 * The recordings made on this site, as a filterable list.
 *
 * WHAT A ROW IS
 *
 * One recording, keyed by domstream_guid -- NOT one row of owa_domstream. The
 * tracker flushes its event queue on a timer, so a single recording is stored
 * as however many rows it took to hold it, all sharing a guid. The list groups
 * them back together, and the numbers beside each recording are computed over
 * that group.
 *
 * WHAT THE AGGREGATES MEAN, AND WHY THEY ARE AGGREGATES
 *
 * The previous query grouped by domstream_guid and then selected `duration`,
 * `page_url`, `page_height` and `page_width` as BARE columns. Under this
 * install's sql_mode -- which is set to '' on every connection, so
 * ONLY_FULL_GROUP_BY is off -- MySQL answers with an arbitrary row's value
 * instead of refusing. For duration that is not cosmetic: `duration` is
 * cumulative elapsed seconds at the moment of each flush, so a twenty-minute
 * recording stored in twelve rows carries twelve different durations and the
 * list would show whichever one the optimiser reached first. Measured on this
 * install: one recording with durations from 552 to 1773 seconds.
 *
 *   duration  MAX  -- cumulative, so the last flush is the whole recording.
 *                    Verified against the data: max(duration) - min(duration)
 *                    equals max(timestamp) - min(timestamp) exactly, which is
 *                    what "cumulative, in seconds" predicts.
 *   started   MIN(timestamp) -- when the recording BEGAN. The old query used
 *                    max(), which is when the last chunk arrived.
 *   segments  COUNT(*) -- how many flushes the recording took.
 *   size      SUM(OCTET_LENGTH(events)) -- how much was recorded, in bytes.
 *
 * WHY NOT AN EVENT COUNT
 *
 * Because there is no column for it. The events themselves live in a BLOB, so
 * counting them means either decoding every recording in PHP or a string
 * measurement in SQL, and neither belongs in a list query. Worth recording that
 * the tracker already SENDS the number -- Tracker.js sets `stream_length` on
 * each dom.stream event -- and the entity has no such property, so the handler
 * drops it on write. One column would make it a real metric; adding one was out
 * of scope here.
 *
 * `segments` and `size` are what the existing columns can honestly answer. A
 * flush happens once the queue passes domstreamEventThreshold (10) OR the
 * logging interval elapses, so segments is not a proxy for event count and is
 * not presented as one.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.2.1
 */

class ReportDomstreams extends \OWA\Core\ReportController {

    /** Recordings per page. */
    const PER_PAGE = 50;

    /**
     * The segment selects VISITS, not visitors.
     *
     * A recording is made during one visit, and every dimension the picker
     * offers -- medium, source, browser, city, campaign -- is a property of a
     * visit. Selecting people instead would answer "organic-search" with that
     * person's recordings from their direct visit too, which is not what the
     * constraint says.
     *
     * The funnel offers visitor/visit as a toggle because a funnel can sensibly
     * be counted either way. A list of recordings cannot: the recordings are
     * the same either way, only the selection changes.
     */
    const SCOPE = 'session';

    /** @var \OWA\Module\Base\Classes\ReportSegment|null */
    private $segment = null;

    function action() {

        $document_id = '';

        // Recordings for one page, when the report was reached from a document.
        // pageUrl is resolved INSIDE this branch and used only here -- it used
        // to be initialised empty above and passed to the query unconditionally.
        if ( $this->getParam('document_id') || $this->getParam('pageUrl') || $this->getParam('pagePath') ) {

            $doc = \OWA\Core\CoreAPI::entityFactory('base.document');

            if ( $this->get( 'document_id' ) ) {

                $doc->load( $this->getParam('document_id') );

            } elseif ( $this->getParam('pageUrl') ) {

                $doc->getByColumn( 'url', $this->getParam('pageUrl') );

            } elseif ( $this->getParam('pagePath') ) {

                $doc->getByColumn( 'uri', $this->getParam('pagePath') );
            }

            $document_id = $doc->get('id');

            $this->setTitle('Domstream Recordings: ', $doc->get('url'));
            $this->set('document', $doc->_getProperties());
            $this->set('item_properties', $doc);

        } else {

            $this->setTitle('Latest Domstreams');
        }

        /*
         * The filter control: what it may offer, and what is applied. Both live
         * on the URL, like every other way of looking at a report.
         */
        $filter = $this->segment()->options();

        $this->set( 'domstreams_filter_dimensions', $filter['dimensions'] );
        $this->set( 'domstreams_filter_metrics',    $filter['metrics'] );
        $this->set( 'domstreams_constraints',       $this->segment()->getConstraints() );

        $subjects = $this->segment()->subjects( self::SCOPE );

        if ( $this->segment()->getError() ) {

            $this->set( 'domstreams_segment_error', $this->segment()->getError() );
        }

        $page = (int) $this->getParam('page') ?: 1;

        $recordings = $this->listRecordings( $document_id, $subjects, $page );
        $total      = $this->countRecordings( $document_id, $subjects );

        $this->set( 'domstreams', $this->asResultSet( $recordings ) );
        $this->set( 'domstreams_total', $total );
        $this->set( 'domstreams_pagination', (object) array(
            'page'        => $page,
            'total_pages' => (int) ceil( $total / self::PER_PAGE ),
        ) );

        $this->setSubview('base.reportDomstreams');
    }

    /** The segment, built once from the request. */
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

    /**
     * The WHERE that selects which recordings are in scope, with its bindings.
     *
     * Shared by the list and the count so the two cannot disagree -- a total
     * computed under different conditions from the rows gives a page count that
     * does not match the pages.
     *
     * @param string     $document_id restrict to one page, or '' for all
     * @param array|null $subjects    session ids from the segment, or null
     * @return array {sql, params}
     */
    private function scopeClause( $document_id, $subjects ) {

        $where  = array( 'site_id = ?' );
        $params = array( (string) $this->getParam('siteId') );

        $bounds = $this->segment()->bounds();

        if ( $bounds ) {

            // Closed at both ends: the fact tables are RANGE-partitioned on
            // yyyymmdd, and an open bound reads every partition from there on.
            // owa_domstream is the heaviest of them -- it holds the serialised
            // events -- so an unbounded scan here costs more than elsewhere.
            $where[]  = 'yyyymmdd BETWEEN ? AND ?';
            $params[] = $bounds['start'];
            $params[] = $bounds['end'];
        }

        if ( $document_id ) {

            $where[]  = 'document_id = ?';
            $params[] = $document_id;
        }

        if ( is_array( $subjects ) ) {

            if ( ! $subjects ) {

                // A segment that matches nobody has no recordings. Expressed as
                // a clause that cannot match rather than by skipping the query,
                // so both callers agree without either knowing about the case.
                $where[] = '1 = 0';

            } else {

                $where[] = 'session_id IN (' . implode( ',', array_fill( 0, count( $subjects ), '?' ) ) . ')';

                foreach ( $subjects as $id ) {

                    $params[] = $id;
                }
            }
        }

        return array(
            'sql'    => implode( ' AND ', $where ),
            'params' => $params,
        );
    }

    /**
     * One page of recordings, newest first.
     *
     * @param string     $document_id
     * @param array|null $subjects
     * @param int        $page
     * @return array
     */
    private function listRecordings( $document_id, $subjects, $page = 1 ) {

        $scope = $this->scopeClause( $document_id, $subjects );

        $offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;

        /*
         * Every non-grouped column is aggregated. page_url and the viewport are
         * constant within a recording in practice -- one guid is one page load
         * -- but MIN() says so explicitly instead of relying on sql_mode being
         * permissive, and keeps one row per recording if it ever is not.
         */
        $sql = 'SELECT domstream_guid,'
             . ' MIN(timestamp) AS started,'
             . ' MAX(duration) AS duration,'
             . ' COUNT(*) AS segments,'
             . ' SUM(OCTET_LENGTH(events)) AS bytes,'
             . ' MIN(page_url) AS page_url,'
             . ' MIN(page_width) AS page_width,'
             . ' MIN(page_height) AS page_height'
             . ' FROM owa_domstream'
             . ' WHERE ' . $scope['sql']
             . ' GROUP BY domstream_guid'
             . ' ORDER BY started DESC'
             . ' LIMIT ' . (int) self::PER_PAGE . ' OFFSET ' . (int) $offset;

        $stmt = \OWA\Core\CoreAPI::dbSingleton()->query( $sql, $scope['params'] );

        if ( ! $stmt ) {

            \OWA\Core\CoreAPI::notice( 'Domstream list query failed.' );

            return array();
        }

        return $stmt->fetchAll( \PDO::FETCH_ASSOC );
    }

    /**
     * How many recordings are in scope, for the pager.
     *
     * COUNT(DISTINCT domstream_guid), because a recording is a guid and not a
     * row -- counting rows would page a twelve-chunk recording as twelve.
     *
     * @param string     $document_id
     * @param array|null $subjects
     * @return int
     */
    private function countRecordings( $document_id, $subjects ) {

        $scope = $this->scopeClause( $document_id, $subjects );

        $sql = 'SELECT COUNT(DISTINCT domstream_guid) AS total'
             . ' FROM owa_domstream WHERE ' . $scope['sql'];

        $stmt = \OWA\Core\CoreAPI::dbSingleton()->query( $sql, $scope['params'] );

        if ( ! $stmt ) {

            return 0;
        }

        $row = $stmt->fetch( \PDO::FETCH_ASSOC );

        return $row ? (int) $row['total'] : 0;
    }

    /**
     * The recordings shaped as a RESULT SET, so the grid control can draw them.
     *
     * The same grid every other report uses, rather than the hand-written
     * <table> this report had -- which drew its own header from a labels object
     * and its own rows, and so shared nothing with the rest of the reporting UI.
     *
     * The grid's explorer controls are switched off at the call site: a
     * secondary dimension and its Filter both re-query the result set's own
     * URL, and these rows came from a query this report ran itself.
     *
     * @param array $recordings
     * @return array
     */
    private function asResultSet( array $recordings ) {

        $rows = array();

        foreach ( $recordings as $r ) {

            $duration = (int) $r['duration'];
            $bytes    = (int) $r['bytes'];

            $rows[] = array(
                'recorded' => self::cell( 'dimension', 'recorded', 'Recorded',
                                  (int) $r['started'],
                                  date( 'M j, Y g:i a', (int) $r['started'] ), 'number' ),

                'page'     => self::cell( 'dimension', 'page', 'Page',
                                  $r['page_url'], $r['page_url'] ),

                'duration' => self::cell( 'metric', 'duration', 'Duration',
                                  $duration, self::asClock( $duration ), 'number' ),

                'segments' => self::cell( 'metric', 'segments', 'Segments',
                                  (int) $r['segments'], (string) (int) $r['segments'], 'number' ),

                'size'     => self::cell( 'metric', 'size', 'Size',
                                  $bytes, self::asBytes( $bytes ), 'number' ),

                /*
                 * The player. The cell carries the payload as its VALUE and a
                 * named formatter builds the link -- the same mechanism the
                 * attribution column uses. The value is data, not markup: the
                 * formatter is the one place these fields can be escaped, and
                 * a report must never hand the grid HTML it assembled itself.
                 */
                'play'     => self::cell( 'dimension', 'play', '',
                                  $this->playerPayload( $r ), 'Play' ),
            );
        }

        return array(
            'resultsRows'     => $rows,
            'resultsReturned' => count( $rows ),
            'resultsTotal'    => count( $rows ),
            // The grid skips a redraw when the guid is unchanged, so it has to
            // differ whenever the rows do.
            'guid'            => md5( json_encode( $rows ) ),
        );
    }

    /**
     * What the player needs to replay one recording.
     *
     * The overlay token is minted for THIS recording, so a payload lifted from
     * one row cannot be used to fetch another.
     *
     * @param array $r
     * @return array
     */
    private function playerPayload( array $r ) {

        $template = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' );

        $api_url = $template->makeOverlayApiLink(
            array(
                'domstream_guid' => $r['domstream_guid'],
                'module'         => 'domstream',
                'version'        => 'v1',
                'do'             => 'domstreams',
            ),
            'domstream_guid'
        );

        // The player is opened on the recorded page itself, and a fragment on
        // that URL is where the overlay parameters ride. A page_url that
        // already carries one would produce two.
        $page_url = (string) $r['page_url'];

        if ( strpos( $page_url, '#' ) !== false ) {

            $parts    = explode( '#', $page_url );
            $page_url = $parts[0];
        }

        return array(
            'overlay' => trim( base64_encode( $template->makeParamString(
                array(
                    'action'         => 'loadPlayer',
                    'domstream_guid' => $r['domstream_guid'],
                    'api_url'        => $api_url,
                ),
                true,
                'json'
            ) ), "\0" ),
            'url'    => $page_url,
            'width'  => (int) $r['page_width'],
            'height' => (int) $r['page_height'],
        );
    }

    /** Seconds as h:mm:ss, without pretending a duration is a time of day. */
    private static function asClock( $seconds ) {

        $seconds = max( 0, (int) $seconds );

        return sprintf( '%d:%02d:%02d',
            intdiv( $seconds, 3600 ),
            intdiv( $seconds % 3600, 60 ),
            $seconds % 60 );
    }

    /** Bytes, at the scale a reader can hold in their head. */
    private static function asBytes( $bytes ) {

        $bytes = max( 0, (int) $bytes );

        if ( $bytes < 1024 ) {

            return $bytes . ' B';
        }

        if ( $bytes < 1024 * 1024 ) {

            return round( $bytes / 1024, 1 ) . ' KB';
        }

        return round( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
    }

    /** One result-set cell, in the shape the grid reads. */
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
