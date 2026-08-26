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
 * Goal Funnel Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ReportGoalFunnel extends \OWA\Core\ReportController {

    /**
     * How the funnel is counted: one visitor's whole history, or one visit.
     *
     * On the URL and nowhere else. A funnel scope is a way of LOOKING at a
     * report, not a property of the site, so persisting it would make the same
     * link mean different things to two people -- and make a shared link mean
     * something different again tomorrow.
     */
    const SCOPE_PARAM = 'funnelScope';

    /** Subject columns, keyed by the scope that selects them. */
    /**
     * How many subjects a segment may select before the funnel refuses.
     *
     * The ids travel back as an IN list, so this bounds the statement rather
     * than expressing a view about how big a segment is allowed to be.
     */
    const SEGMENT_LIMIT = 10000;

    const SCOPES = array(
        'visitor' => 'visitor_id',
        'session' => 'session_id',
    );

    function action() {

        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $this->getParam( 'siteId' ) );

        $goal_number = $this->getParam('goalNumber');

        if ( ! $goal_number ) {
            $goal_number = 1;
        }

        $goal   = $gm->getGoal($goal_number);
        $funnel = $gm->getGoalFunnel($goal_number);

        $scope = $this->scope();

        $this->set( 'funnel_scope', $scope );

        /*
         * The filter control: what it may offer, and what is currently applied.
         * The constraint itself lives on the URL like the scope does -- it is a
         * way of looking at the report, not a property of the site.
         */
        $filter = $this->filterOptions();

        $this->set( 'funnel_filter_dimensions', $filter['dimensions'] );
        $this->set( 'funnel_filter_metrics',    $filter['metrics'] );
        $this->set( 'funnel_constraints',       (string) $this->getParam( 'constraints' ) );
        // What the counts are counting, so the template does not have to say
        // "visitors" when it is counting visits.
        $this->set( 'funnel_scope_label', $scope === 'session' ? 'visits' : 'visitors' );
        $this->set( 'funnel_scope_other', $scope === 'visitor' ? 'session' : 'visitor' );

        if ( $funnel ) {

            /*
             * The goal's own destination is the last step. Keyed `path` like
             * every other element: the counting below reads $step['path'], and
             * the stored steps carry that key since the rename. Built with
             * 'url' it was the one element the loop could not read.
             */
            $steps = array_values( $funnel );
            $steps[] = array(
                'path'        => $goal['details']['goal_url'],
                'name'        => $goal['goal_name'],
                'step_number' => count( $steps ) + 1,
            );

            $counted = $this->countFunnel( $steps, $scope );

            $previous = null;

            foreach ( $steps as $i => $step ) {

                $reached = isset( $counted[ $i ] ) ? (int) $counted[ $i ] : 0;

                $steps[ $i ]['visitors'] = $reached;

                // The template renders by step_number. A stored step carries
                // one; the appended goal destination is given one above. This
                // is the backstop, so a funnel saved before the field existed
                // still draws in order rather than as a row of blanks.
                if ( empty( $steps[ $i ]['step_number'] ) ) {

                    $steps[ $i ]['step_number'] = $i + 1;
                }

                /*
                 * Drop-off against the step before, which is what a funnel
                 * shows. The first step is the entry population, so it is 100%
                 * of itself by definition.
                 *
                 * No backfill guard any more: a step can no longer out-count
                 * the one before it, because reaching step N now REQUIRES
                 * having reached N-1. The old code needed that guard precisely
                 * because its steps were independent counts.
                 */
                if ( $previous === null ) {

                    $steps[ $i ]['visitor_percentage'] = '100%';

                } elseif ( $previous > 0 ) {

                    $steps[ $i ]['visitor_percentage'] =
                        round( $reached / $previous, 4 ) * 100 . '%';

                } else {

                    $steps[ $i ]['visitor_percentage'] = '0.00%';
                }

                $previous = $reached;
            }

            $entered = isset( $counted[0] ) ? (int) $counted[0] : 0;
            $goal_step = end( $steps );

            /*
             * Against the population that ENTERED the funnel, not against a
             * separate query.
             *
             * The denominator used to be its own request constrained on every
             * required step at once -- `pagePath==a,pagePath==b,...`. Those are
             * ANDed on a single fact row, and a row has one path, so it could
             * never match; worse, constraints are keyed by column, so all but
             * the last were silently discarded and the denominator quietly
             * became "whoever hit the last required step".
             */
            $goal_conversion_rate = $entered > 0
                ? round( $goal_step['visitors'] / $entered, 4 ) * 100 . '%'
                : '0%';

            $this->set( 'total_visitors', $entered );
            $this->set( 'funnel_table', $this->stepsAsResultSet(
                $steps, $entered, $scope === 'session' ? 'visits' : 'visitors' ) );
            $this->set( 'goal_conversion_rate', $goal_conversion_rate );
            $this->set( 'funnel', $steps );
        }

        // set view stuff
        $this->setSubview('base.reportGoalFunnel');
        $this->setTitle('Funnel Visualization:', 'Goal ' . $goal_number);
        $this->set('goal_number', $goal_number);
    }

    /**
     * The steps shaped as a RESULT SET, so the grid control can draw them.
     *
     * The table under the funnel is the same grid every other report uses,
     * rather than a hand-written <table> that would drift from it. The grid
     * takes a result set, so the computed steps are given the shape one has:
     * a row per step, each cell carrying its own name, label and value.
     *
     * Its explorer controls are switched off at the call site. A grid normally
     * offers a secondary dimension and a Filter, and both re-query the result
     * set's own URL -- there is no such URL here, because these rows came from
     * one ordered query this report ran itself.
     *
     * @param array $steps
     * @param int $entered the population that entered the funnel
     * @param string $label what the counts count
     * @return array
     */
    private function stepsAsResultSet( array $steps, $entered, $label ) {

        $rows = array();

        foreach ( $steps as $i => $step ) {

            $count = (int) $step['visitors'];
            $prior = $i > 0 ? (int) $steps[ $i - 1 ]['visitors'] : null;

            $dropped   = $prior === null ? null : $prior - $count;
            $of_entry  = $entered > 0 ? round( ( $count / $entered ) * 100, 1 ) . '%' : '0%';

            $rows[] = array(
                'step'      => self::cell( 'dimension', 'step', 'Step', $step['step_number'] ),
                'name'      => self::cell( 'dimension', 'name', 'Name', $step['name'] ),
                'page'      => self::cell( 'dimension', 'page', 'Page', $step['path'] ),
                'reached'   => self::cell( 'metric', 'reached', ucfirst( $label ), $count, 'integer' ),
                'continued' => self::cell( 'metric', 'continued', 'Continued',
                                   $i > 0 ? $step['visitor_percentage'] : '' ),
                'dropped'   => self::cell( 'metric', 'dropped', 'Dropped',
                                   $dropped === null ? '' : $dropped, 'integer' ),
                'ofEntry'   => self::cell( 'metric', 'ofEntry', 'Of entry', $of_entry ),
            );
        }

        return array(
            'resultsRows'     => $rows,
            'resultsReturned' => count( $rows ),
            'resultsTotal'    => count( $rows ),
            // The grid skips a redraw when the guid is unchanged, so it has to
            // differ whenever the numbers do.
            'guid'            => md5( json_encode( $rows ) ),
        );
    }

    /** One result-set cell, in the shape the grid reads. */
    private static function cell( $type, $name, $label, $value, $dataType = 'string' ) {

        return array(
            'result_type'     => $type,
            'name'            => $name,
            'label'           => $label,
            'value'           => $value,
            'formatted_value' => (string) $value,
            'data_type'       => $dataType,
        );
    }

    /**
     * visitor or session, from the URL.
     *
     * Anything else reads as visitor rather than erroring: the scope is a view
     * toggle, and a mistyped one should not take a report down.
     *
     * @return string
     */
    private function scope() {

        $asked = (string) $this->getParam( self::SCOPE_PARAM );

        return isset( self::SCOPES[ $asked ] ) ? $asked : 'visitor';
    }

    /**
     * How many subjects reached each step, IN ORDER.
     *
     * ONE query, not one per step. Per subject it takes the first time they hit
     * each step, then counts those whose times run in order -- which is GA's
     * "indirectly followed by": intervening pages are allowed, going backwards
     * is not.
     *
     * What this replaces counted each step independently
     * (`visitors where pagePath == step`), so it was not a funnel at all: a
     * visitor who landed straight on the last page and never saw the first
     * counted in the last step, and steps could out-count the ones before them.
     *
     * @param array  $steps ordered, each with a `path`
     * @param string $scope visitor|session
     * @return array step index => count
     */
    private function countFunnel( array $steps, $scope ) {

        if ( ! $steps ) {

            return array();
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $subject = self::SCOPES[ $scope ];

        $params = array();
        $select = array();

        foreach ( $steps as $i => $step ) {

            // The first time this subject reached the step. MIN, because a page
            // hit twice must not read as two different positions in the order.
            $select[] = sprintf( 'MIN(CASE WHEN d.uri = ? THEN r.timestamp END) AS t%d', $i );
            $params[] = (string) $step['path'];
        }

        $where  = array( 'r.site_id = ?' );
        $params[] = (string) $this->getParam( 'siteId' );

        $bounds = $this->dateBounds();

        if ( $bounds ) {

            // Closed at both ends: the fact tables are RANGE-partitioned on
            // yyyymmdd, and an open bound reads every partition from there on.
            $where[]  = 'r.yyyymmdd BETWEEN ? AND ?';
            $params[] = $bounds['start'];
            $params[] = $bounds['end'];
        }

        /*
         * The segment: WHICH subjects are in the funnel at all.
         *
         * Selected by an OUTER query through the ordinary reporting stack, so
         * the funnel accepts exactly the constraints every other report does --
         * validated against the registry, resolved through the same joins, and
         * refused the same way when a name does not exist.
         *
         * Deliberately not folded into the WHERE below. Constraining the funnel
         * query itself would filter the ROWS -- `medium==organic-search` would
         * drop every step the subject reached on some other medium and the
         * funnel would collapse for reasons that have nothing to do with the
         * funnel. GA's segments pick the users and then count all of their
         * events; this does the same.
         */
        $segment = $this->segmentSubjects( $scope );

        if ( $segment === array() ) {

            // A segment that matches nobody is a funnel nobody entered, not an
            // unsegmented funnel.
            return array_fill( 0, count( $steps ), 0 );
        }

        if ( is_array( $segment ) ) {

            $where[] = 'r.' . $subject . ' IN (' . implode( ',', array_fill( 0, count( $segment ), '?' ) ) . ')';

            foreach ( $segment as $id ) {

                $params[] = $id;
            }
        }

        $sql = 'SELECT ' . implode( ', ', $select )
             . ' FROM owa_request r'
             . ' INNER JOIN owa_document d ON d.id = r.document_id'
             . ' WHERE ' . implode( ' AND ', $where )
             . ' GROUP BY r.' . $subject;

        $stmt = $db->query( $sql, $params );

        if ( ! $stmt ) {

            \OWA\Core\CoreAPI::notice( 'Goal funnel query failed.' );

            return array();
        }

        $counts = array_fill( 0, count( $steps ), 0 );

        foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {

            $previous = null;

            foreach ( $steps as $i => $step ) {

                $at = $row[ 't' . $i ];

                // Never reached, or reached before the step in front of it:
                // this subject leaves the funnel here and counts in no step
                // beyond it.
                if ( $at === null || ( $previous !== null && $at < $previous ) ) {

                    break;
                }

                $counts[ $i ]++;
                $previous = $at;
            }
        }

        return $counts;
    }

    /**
     * What the filter control may constrain on.
     *
     * The funnel's segment accepts the same constraints every other report does,
     * so the picker has to offer the same choices -- and the authority on those
     * is the reporting stack, not a list written out here. A list of our own
     * would offer names the segment then refuses.
     *
     * Taken from an AGGREGATE-ONLY query: asking for a metric with no dimensions
     * still comes back carrying the full related-dimension and related-metric
     * lists (measured: 10 groups, 70 dimensions), and costs one aggregate rather
     * than a group-by over every visitor in the period.
     *
     * @return array {dimensions, metrics} in the shape the picker reads
     */
    private function filterOptions() {

        $empty = array( 'dimensions' => array(), 'metrics' => array() );

        /*
         * No period, no query. The report normally always has one, but this
         * controller is also constructed directly -- by the registry contract
         * test, and by anything checking a route -- and a picker is decoration:
         * it must never be the reason a report cannot render.
         */
        if ( ! $this->getParam( 'period' )
             && ! ( $this->getParam( 'startDate' ) && $this->getParam( 'endDate' ) ) ) {

            return $empty;
        }

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( 'visits' );
        $rsm->setSiteId( $this->getParam( 'siteId' ) );
        $rsm->setTimePeriod(
            $this->getParam( 'period' ),
            $this->getParam( 'startDate' ),
            $this->getParam( 'endDate' )
        );
        $rsm->setLimit( 1 );

        try {

            $rs = $rsm->getResults();

        } catch ( \Throwable $e ) {

            \OWA\Core\CoreAPI::notice( 'Goal funnel could not build its filter options: ' . $e->getMessage() );

            return $empty;
        }

        return array(
            'dimensions' => (array) ( $rs->relatedDimensions ?? array() ),
            'metrics'    => (array) ( $rs->relatedMetrics ?? array() ),
        );
    }

    /**
     * The subjects a constraint selects, or null when there is no constraint.
     *
     * Run through ResultSetManager rather than assembled here, and the reason is
     * concrete: a dimension does not resolve to a column on this table. `medium`
     * is denormalized onto the request, but `browserType` resolves to
     * ua_via_.browser_type and `city` to location_dim_via_.city -- each needing
     * the join the result-set manager already knows how to build. Hand-rolling
     * the segment SQL would mean reimplementing that, and it would drift.
     *
     * A cap, and it REFUSES rather than truncating. A silently shortened id list
     * would answer with a funnel that looks complete and counts a fraction of
     * the people in it -- the same class of wrong-but-plausible number this
     * report already had.
     *
     * @param string $scope visitor|session
     * @return array|null  ids, or null for "no segment asked for"
     */
    private function segmentSubjects( $scope ) {

        $constraints = (string) $this->getParam( 'constraints' );

        if ( $constraints === '' ) {

            return null;
        }

        $dimension = $scope === 'session' ? 'sessionId' : 'visitorId';

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( 'visits' );
        $rsm->setDimensions( $rsm->dimensionsStringToArray( $dimension ) );
        $rsm->setSiteId( $this->getParam( 'siteId' ) );
        $rsm->setTimePeriod(
            $this->getParam( 'period' ),
            $this->getParam( 'startDate' ),
            $this->getParam( 'endDate' )
        );
        $rsm->setConstraints( $rsm->constraintsStringToArray( $constraints ) );
        $rsm->setLimit( self::SEGMENT_LIMIT + 1 );

        $results = $rsm->getResults();

        // The constraint itself was refused -- an unknown dimension, a missing
        // value. Say so instead of quietly showing an unsegmented funnel.
        if ( ! empty( $results->request_errors ) ) {

            $this->set( 'funnel_segment_error', implode( ' ', $results->request_errors ) );

            return array();
        }

        $ids = array();

        foreach ( (array) ( $results->resultsRows ?? array() ) as $row ) {

            if ( isset( $row[ $dimension ]['value'] ) ) {

                $ids[] = $row[ $dimension ]['value'];
            }
        }

        if ( count( $ids ) > self::SEGMENT_LIMIT ) {

            $this->set( 'funnel_segment_error', sprintf(
                'This segment selects more than %s %ss. Narrow it -- a funnel drawn from '
                . 'part of them would look complete and count a fraction of the people in it.',
                number_format( self::SEGMENT_LIMIT ), $scope
            ) );

            return array();
        }

        return $ids;
    }

    /**
     * The reporting period as yyyymmdd bounds.
     *
     * Resolved through timePeriod, the same class the result-set manager uses,
     * so a funnel and a report beside it cannot disagree about what "last
     * thirty days" means.
     *
     * @return array|null
     */
    private function dateBounds() {

        $period    = $this->getParam( 'period' );
        $startDate = $this->getParam( 'startDate' );
        $endDate   = $this->getParam( 'endDate' );

        $timePeriod = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );

        if ( $startDate && $endDate ) {

            $timePeriod->set( 'date_range', array( 'startDate' => $startDate, 'endDate' => $endDate ) );

        } elseif ( $period ) {

            $timePeriod->set( $period );

        } else {

            return null;
        }

        $start = $timePeriod->startDate->get( 'yyyymmdd' );
        $end   = $timePeriod->endDate->get( 'yyyymmdd' );

        return ( $start && $end ) ? array( 'start' => $start, 'end' => $end ) : null;
    }
}




?>
