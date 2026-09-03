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

class VisualizationFunnel extends \OWA\Core\ReportController {

    /**
     * How the funnel is counted: one visitor's whole history, or one visit.
     *
     * On the URL and nowhere else. A funnel scope is a way of LOOKING at a
     * report, not a property of the site, so persisting it would make the same
     * link mean different things to two people -- and make a shared link mean
     * something different again tomorrow.
     */
    const SCOPE_PARAM = 'funnelScope';

    /**
     * The segment -- which people the funnel is drawn for -- is not the
     * funnel's own idea. It lives in ReportSegment, which the domstreams report
     * uses too, so a constraint means the same thing in both: it picks the
     * PEOPLE, and their whole activity is then counted.
     *
     * @var \OWA\Module\Base\Classes\ReportSegment|null
     */
    private $segment = null;

    /** Subject columns, keyed by the scope that selects them. */
    const SCOPES = array(
        'visitor' => 'visitor_id',
        'session' => 'session_id',
    );

    /**
     * The row this is drawing, from the reportId on the request.
     *
     * The dispatcher builds this controller from the REQUEST params, so nothing
     * it looked up reaches here -- the id is on the URL and is read again.
     *
     * @return string
     */
    protected function visualizationId() {

        $reportId = (string) $this->getParam( 'reportId' );
        $prefix   = \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX;

        return strpos( $reportId, $prefix ) === 0
            ? substr( $reportId, strlen( $prefix ) ) : '';
    }

    function action() {

        /*
         * The steps come from the VISUALIZATION's own definition.
         *
         * They used to come from a goal's funnel config, which made a report
         * depend on admin configuration and tied goal_N_start -- an ingest-time
         * stamp -- to a reporting artefact. A goal event says what starts it
         * directly now, so this is free to be what it always was: an analysis
         * of a path, defined where it is looked at.
         */
        $visualization = \OWA\Module\Base\Classes\CustomReports::load( $this->visualizationId() );

        if ( ! $visualization ) {

            $this->set( 'funnel', array() );
            $this->setSubview( 'base.visualizationFunnel' );
            $this->setTitle( 'Funnel' );

            return;
        }

        $definition = (array) $visualization['definition'];
        $funnel     = isset( $definition['steps'] ) ? (array) $definition['steps'] : array();

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
            /*
             * The steps ARE the path. The old report appended the goal's
             * destination as a final step because its steps described the route
             * TO a goal; a visualization's last step is the destination.
             */
            $steps = array_values( $funnel );

            $counted = $this->countFunnel( $steps, $scope );

            if ( $counted === null ) {

                /*
                 * REFUSED, and stepPredicate() has already said why.
                 *
                 * Not drawn with the offending step left out: a funnel missing
                 * a stage still looks like a funnel, and every percentage after
                 * the gap would be a real-looking number computed against the
                 * wrong denominator.
                 */
                $this->set( 'funnel', array() );
                $this->setSubview( 'base.visualizationFunnel' );
                $this->setTitle( (string) $visualization['name'] );
                $this->set( 'visualization_id', $this->visualizationId() );

                return;
            }

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
        $this->setSubview( 'base.visualizationFunnel' );
        $this->setTitle( (string) $visualization['name'] );
        $this->set( 'visualization_id', $this->visualizationId() );
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
    /**
     * One predicate per step, whatever the step is made of.
     *
     * A step is a CONDITION. A path step is the condition "this page"; a goal
     * event step is the condition somebody already named and saved. Compiling
     * both to the same shape is what makes them interchangeable in the walk
     * below -- the counting never learns which kind it is looking at.
     *
     * @param  array  $step
     * @param  string $alias  the document table's alias in the funnel query
     * @return array|null     array( 'sql', 'params' ), or null if refused
     */
    private function stepPredicate( array $step, $alias = 'd' ) {

        $goalEventId = (string) ( $step['goal_event_id'] ?? '' );

        if ( $goalEventId === '' ) {

            return array(
                'sql'    => $alias . '.uri = ?',
                'params' => array( (string) ( $step['path'] ?? '' ) ),
            );
        }

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->load( $goalEventId );

        if ( ! $goalEvent->wasPersisted() ) {

            /*
             * Named, not drawn as an empty stage.
             *
             * A goal event can be deleted while a funnel still points at it,
             * and a stage that silently reports zero is indistinguishable from
             * a stage nobody reached -- which is the number somebody would then
             * go and investigate.
             */
            $this->set( 'funnel_step_error', sprintf(
                'A step of this funnel names a goal event that no longer exists. '
                . 'Edit the funnel and choose another, or remove the step.' ) );

            return null;
        }

        $predicate = new \OWA\Module\Base\Classes\GoalEventPredicate;

        $compiled = $predicate->compile( $goalEvent, $alias );

        if ( $compiled === null ) {

            $this->set( 'funnel_step_error', sprintf(
                'The goal event "%s" tests %s, which a funnel cannot count against. '
                . 'A funnel step can test the page: its URL, its title or its type. '
                . 'The funnel is not drawn rather than drawn with that condition ignored, '
                . 'which would report a larger number than the goal event means.',
                (string) $goalEvent->get( 'name' ), $predicate->getError() ) );

            return null;
        }

        return $compiled;
    }

    /**
     * How many subjects reached each step, in order.
     *
     * THE MATH, AND WHY IT IS A WALK RATHER THAN A MIN PER STEP
     *
     * This is a CLOSED funnel with INDIRECTLY-followed steps, counted per
     * subject -- GA's defaults, and the only ones OWA offers. Closed: everybody
     * enters at step 1. Indirect: other things may happen in between, the next
     * step only has to come later. Per subject: a subject enters the funnel
     * once in the period, and it is their FIRST run through it that is counted.
     *
     * That last clause is the whole reason for the shape of this. The obvious
     * implementation -- MIN(timestamp) per step, then check the timestamps come
     * out in order -- is what this used to do, and it is wrong whenever a
     * step's page is also visited BEFORE the funnel starts. Take a funnel
     * / -> /pricing -> /docs and a visitor who reads the docs, comes back to
     * the home page, goes to pricing, and reads the docs again:
     *
     *     /docs 10:00   / 11:00   /pricing 12:00   /docs 13:00
     *
     * MIN per step gives 11:00, 12:00, 10:00 -- out of order, so the visitor is
     * dropped at the last step. They completed the funnel. GA counts them,
     * because it looks for the first occurrence of each step AFTER the one
     * before it, not the first occurrence overall.
     *
     * And it is not an edge case: funnels start on home pages and pricing
     * pages, which are exactly the pages people also visit at other times. The
     * error is always an UNDERCOUNT, always at the later steps, and always
     * looks like a conversion problem.
     *
     * So the rows come back in time order per subject and are walked forward,
     * advancing a stage each time the current step's condition is met. One row
     * per matching event rather than one per subject -- the WHERE only admits
     * events that satisfy some step, and the segment and the date bounds are
     * what keep that set to a size worth walking.
     *
     * @param  array  $steps
     * @param  string $scope
     * @return array  a count per step, positionally
     */
    private function countFunnel( array $steps, $scope ) {

        if ( ! $steps ) {

            return array();
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $subject = self::SCOPES[ $scope ];

        $zeroes = array_fill( 0, count( $steps ), 0 );

        $params = array();
        $select = array();
        $any    = array();

        foreach ( $steps as $i => $step ) {

            $predicate = $this->stepPredicate( $step );

            if ( $predicate === null ) {

                // Refused, and stepPredicate() has said why. Not drawn with the
                // step ignored: a funnel missing a stage still looks like a
                // funnel.
                return null;
            }

            $select[] = sprintf( 'CASE WHEN %s THEN 1 ELSE 0 END AS s%d',
                $predicate['sql'], $i );

            $any[] = $predicate['sql'];

            foreach ( $predicate['params'] as $p ) {

                $params[] = $p;
            }
        }

        /*
         * The step predicates appear TWICE -- once as the CASE columns above
         * and once as the WHERE that admits only rows matching some step -- so
         * their parameters are bound twice, in the same order. Kept as one list
         * built in one pass rather than two, because the two orders having to
         * agree is precisely the kind of thing that drifts.
         */
        $params = array_merge( $params, $params );

        $where   = array( '( ' . implode( ' OR ', $any ) . ' )', 'r.site_id = ?' );
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
            return $zeroes;
        }

        if ( is_array( $segment ) ) {

            $where[] = 'r.' . $subject . ' IN (' . implode( ',', array_fill( 0, count( $segment ), '?' ) ) . ')';

            foreach ( $segment as $id ) {

                $params[] = $id;
            }
        }

        $sql = 'SELECT r.' . $subject . ' AS subj, r.timestamp AS ts, '
             . implode( ', ', $select )
             . ' FROM owa_request r'
             . ' INNER JOIN owa_document d ON d.id = r.document_id'
             . ' WHERE ' . implode( ' AND ', $where )
             . ' ORDER BY r.' . $subject . ', r.timestamp';

        /*
         * get_results(), NOT query()->fetchAll().
         *
         * query() hands back the DRIVER's own result -- a PDOStatement under
         * pdo, a mysqli_result under mysqli -- and only one of those has
         * fetchAll(). get_results() is the pair's common contract: assoc rows,
         * or NULL for both "no rows" and "the query failed".
         */
        $rows = $db->get_results( $sql, $params );

        if ( $rows === null ) {

            // Null covers a failed query AND an empty result, so this is not
            // an error worth a notice -- a funnel nobody entered is a real
            // answer, and it is the same zeroes either way.
            return $zeroes;
        }

        return self::walk( $rows, count( $steps ) );
    }

    /**
     * Walk each subject's events forward, advancing a stage at a time.
     *
     * The rows arrive grouped by subject and ordered by time, so one pass is
     * enough: hold the stage this subject has reached, and advance it whenever
     * the row satisfies the step they are waiting for. A subject who reaches
     * stage N counts in every step up to N, which is what makes a funnel
     * monotonic by construction rather than by a guard.
     *
     * A subject is counted ONCE however many times they run the funnel, and it
     * is the first run that counts -- the walk never restarts.
     *
     * PUBLIC AND STATIC because it is the arithmetic, and arithmetic is worth
     * being able to test directly. It reads nothing but its arguments -- the
     * query above decides who is in the funnel, and this decides how far each
     * of them got.
     *
     * @param  array $rows   subject, timestamp-ordered, with an s{N} flag per step
     * @param  int   $count  how many steps
     * @return array
     */
    public static function walk( $rows, $count ) {

        $counts  = array_fill( 0, $count, 0 );
        $current = null;
        $stage   = 0;

        /*
         * The tally happens when the SUBJECT CHANGES, not per row, which is why
         * there is a flush after the loop as well. Counting inside the loop
         * would need the stage of a subject whose last row has not been read
         * yet.
         */
        foreach ( $rows as $row ) {

            if ( $current !== $row['subj'] ) {

                for ( $i = 0; $i < $stage; $i++ ) {

                    $counts[ $i ]++;
                }

                $current = $row['subj'];
                $stage   = 0;
            }

            /*
             * Only ever the step being waited for, and at most ONE stage per
             * event.
             *
             * A row satisfying a later step is not a shortcut -- a closed
             * funnel is reached in order. And two steps satisfied by the same
             * event is not two steps: a step is something the subject DID, so
             * the next one needs an event of its own. Two steps written with
             * overlapping conditions therefore need two visits, which is what
             * they say.
             */
            if ( $stage < $count && (int) $row[ 's' . $stage ] === 1 ) {

                $stage++;
            }
        }

        for ( $i = 0; $i < $stage; $i++ ) {

            $counts[ $i ]++;
        }

        return $counts;
    }

    /**
     * What the filter control may constrain on.
     *
     * Delegated, because the funnel's segment is the same segment the
     * domstreams report uses and a picker that offered different choices in the
     * two places would be lying about one of them.
     *
     * @return array {dimensions, metrics} in the shape the picker reads
     */
    private function filterOptions() {

        return $this->segment()->options();
    }

    /**
     * The subjects the segment selects, or null when none was asked for.
     *
     * The refusal message is lifted onto the view here rather than inside the
     * segment: the segment knows WHY it selected nobody, and the template knows
     * where to say so, and neither should have to know the other.
     *
     * @param string $scope visitor|session
     * @return array|null  ids, or null for "no segment asked for"
     */
    private function segmentSubjects( $scope ) {

        $ids = $this->segment()->subjects( $scope );

        $error = $this->segment()->getError();

        if ( $error ) {

            $this->set( 'funnel_segment_error', $error );
        }

        return $ids;
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
     * The reporting period as yyyymmdd bounds. Same resolution the segment
     * uses, so the two halves of the query cannot disagree about the period.
     *
     * @return array|null
     */
    private function dateBounds() {

        return $this->segment()->bounds();
    }
}




?>
