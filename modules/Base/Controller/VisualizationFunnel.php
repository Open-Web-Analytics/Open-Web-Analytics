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
 * Funnel Report Controller
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

    /** Draws cube data, so it is not drawn for a Property with no cube yet. */
    protected $reads_reporting_data = true;

    /**
     * The segment -- which people the funnel is drawn for -- is not the
     * funnel's own idea. It lives in ReportSegment, which any report may use,
     * so a constraint means the same thing wherever it is: it picks the
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
     * The cube's alias in the funnel query.
     *
     * One place, because a step predicate is compiled elsewhere -- by
     * Classes\EventPredicate -- and is handed the alias rather than assuming
     * it. The two disagreeing is exactly the kind of
     * thing that produces a query that runs and answers about the wrong table.
     */
    const ALIAS = 'e';

    /**
     * The row this is drawing, from the reportId on the request.
     *
     * The dispatcher builds this controller from the REQUEST params, so nothing
     * it looked up reaches here -- the id is on the URL and is read again.
     *
     * @return string
     */
    /**
     * The funnel's own filter, from its definition. It applies to every step,
     * under whatever filter the reader adds on the URL.
     */
    private $savedConstraints = '';

    /**
     * Drawn on its own Property when the request names none, as the report
     * dispatcher does (CustomReports::requestProperty()): this action can be
     * reached by name as well as through it.
     */
    function __construct( $params ) {

        parent::__construct( $params );

        $propertyId = \OWA\Module\Base\Classes\CustomReports::requestProperty(
            $this->visualizationId(), $this->getParam( 'propertyId' ), $this->getCurrentSiteId() );

        if ( $propertyId !== '' ) {

            $this->setParam( 'propertyId', $propertyId );
        }
    }

    /**
     * The visualization being drawn: the stored one this report names, if it
     * is of the Property being reported on.
     */
    protected function visualization() {

        return \OWA\Module\Base\Classes\CustomReports::loadOf(
            $this->visualizationId(), (string) $this->getParam( 'propertyId' ) );
    }

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
         * They used to come from a 1.x goal's funnel config, which made a
         * report depend on admin configuration. A funnel is an analysis of a
         * path, defined where it is looked at.
         */
        $visualization = $this->visualization();

        if ( ! $visualization ) {

            $this->set( 'funnel', array() );
            $this->setSubview( 'base.visualizationFunnel' );
            $this->setTitle( 'Funnel' );

            return;
        }

        $definition = (array) $visualization['definition'];
        $funnel     = isset( $definition['steps'] ) ? (array) $definition['steps'] : array();

        // Before anything builds the segment, which reads it.
        $this->savedConstraints = trim( (string) ( $definition['constraints'] ?? '' ) );
        $this->set( 'funnel_saved_constraints', $this->savedConstraints );

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
        $this->set( 'funnel_scope_label', $scope === 'session' ? 'sessions' : 'users' );
        $this->set( 'funnel_scope_other', $scope === 'visitor' ? 'session' : 'visitor' );

        if ( $funnel ) {

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
                // one. This is the backstop, so a funnel saved before the field existed
                // still draws in order rather than as a row of blanks.
                if ( empty( $steps[ $i ]['step_number'] ) ) {

                    $steps[ $i ]['step_number'] = $i + 1;
                }

                /*
                 * What the step matches, in words, for the chart and the
                 * table: its page for a page step, else its event and
                 * conditions (FunnelStep::summary()).
                 */
                $steps[ $i ]['summary'] = \OWA\Module\Base\Classes\FunnelStep::summary(
                    $step, $this->customColumns() );

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
            $last    = end( $steps );

            /*
             * The Completion Rate: of those who entered, the share who reached
             * the last step. Against the population that ENTERED the funnel,
             * not against a separate query.
             *
             * The denominator used to be its own request constrained on every
             * required step at once -- `pagePath==a,pagePath==b,...`. Those are
             * ANDed on a single fact row, and a row has one path, so it could
             * never match; worse, constraints are keyed by column, so all but
             * the last were silently discarded and the denominator quietly
             * became "whoever hit the last required step".
             */
            $completion_rate = $entered > 0
                ? round( $last['visitors'] / $entered, 4 ) * 100 . '%'
                : '0%';

            $this->set( 'total_visitors', $entered );
            $this->set( 'funnel_table', $this->stepsAsResultSet(
                $steps, $entered, $scope === 'session' ? 'sessions' : 'users' ) );
            $this->set( 'completion_rate', $completion_rate );
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
                'matches'   => self::cell( 'dimension', 'matches', \OWA\Core\CoreAPI::t( 'Matches' ), $step['summary'] ),
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
     * One predicate per step.
     *
     * A step is an event and conditions on it (Classes\FunnelStep), compiled
     * by Classes\EventPredicate over the cube. A page step is a page_view with
     * a page_path condition, whose value is canonicalised the way the row's
     * was: the predicate is handed the install's directory index pages.
     *
     * A step of any other shape is refused by name. Update079 made every saved
     * path and goal event step an event step, except one whose goal event
     * definition was gone or could match nothing, which it left for this to
     * refuse rather than guess at.
     *
     * @param  array  $step
     * @param  int    $number  its position, from 1, for the message
     * @param  string $alias   the cube's alias in the funnel query
     * @return array|null      array( 'sql', 'params' ), or null if refused
     */
    private function stepPredicate( array $step, $number, $alias = self::ALIAS ) {

        $event = (string) ( $step['event'] ?? '' );

        if ( ( $step['kind'] ?? '' ) !== \OWA\Module\Base\Classes\FunnelStep::KIND_EVENT || $event === '' ) {

            /*
             * Named, not drawn as an empty stage: a stage that silently reports
             * zero is indistinguishable from a stage nobody reached.
             */
            $this->set( 'funnel_step_error', sprintf( \OWA\Core\CoreAPI::t(
                'Step %s of this funnel has no event to match. Edit the funnel and choose one.' ), $number ) );

            return null;
        }

        $predicate = new \OWA\Module\Base\Classes\EventPredicate(
            \OWA\Module\Base\Classes\TrackingEventHelpers::directoryIndexPages(),
            $this->customColumns() );

        $compiled = $predicate->compile( $event, \OWA\Module\Base\Classes\FunnelStep::matchOf( $step ),
            \OWA\Module\Base\Classes\FunnelStep::conditionsOf( $step, false ), $alias );

        if ( $compiled === null ) {

            /*
             * Not drawn with that condition ignored, which would report a
             * larger number than the step means.
             */
            $this->set( 'funnel_step_error', sprintf( \OWA\Core\CoreAPI::t(
                'Step %s tests %s, which a funnel step cannot test. Edit the funnel and choose another property.' ),
                $number, $predicate->getError() ) );

            return null;
        }

        return $compiled;
    }

    /** @var array|null the Property's applied custom dimensions, column => label */
    private $customColumns = null;

    /** The custom dimensions a step of this Property's funnel may test. */
    private function customColumns() {

        if ( $this->customColumns === null ) {

            $this->customColumns = \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(
                (string) $this->getParam( 'propertyId' ) );
        }

        return $this->customColumns;
    }

    /**
     * How many subjects reached each step, in order.
     *
     * THE MATH
     *
     * A CLOSED funnel with INDIRECTLY-followed steps, counted per subject --
     * the only ones OWA offers. Closed: everybody enters at
     * step 1, so somebody who lands on step 2 first is not in the funnel at
     * all. Indirect: other things may happen in between, the next step only has
     * to come later. Per subject: a subject enters once in the period, and it
     * is their FIRST run through that is counted.
     *
     * ONE SCAN, AND WHY IT IS NOT DONE IN SQL
     *
     * The database tags each matching event with the steps it satisfies and
     * hands them back per subject in time order. The sequencing -- which is a
     * state machine, one cursor per subject -- happens here.
     *
     * That looks like the wrong side of the line, so it was moved into SQL and
     * measured. Three formulations, against scratch tables carrying the fact
     * table's real schema and indexes -- owa_request's, when the funnel still
     * read it -- with a realistic drop-off
     * (100% -> 33% -> 10%) over a three-step funnel at 262,144 rows:
     *
     *     this, streamed                1.11s     4MB
     *     window functions              3.01s
     *     temporary table per step      3.06s
     *     nested derived tables         3.87s
     *
     * All four agree on the counts; the last three are between 2.7x and 3.5x
     * slower. The reason is the same for each: every one of them reads the
     * request table once per STEP, and this reads it once. Narrowing does not
     * rescue them -- the version that genuinely shrinks its input at each level
     * (temporary tables, inner-joined) is still three times slower -- and
     * neither does the composite index those joins want, which made the derived
     * chain worse rather than better.
     *
     * MEMORY WAS THE REAL PROBLEM, AND IT WAS NOT THE QUERY'S FAULT
     *
     * The cost that prompted all of that was get_results() building one PHP
     * assoc array per row before the walk read any of them -- 46MB per 100,000
     * rows. get_result_iterator() hands them over one at a time instead, which
     * takes the same 262,144-row funnel from 30MB to 4MB and costs nothing in
     * time. The scan was never the problem; the copy of it was.
     *
     * @param  array  $steps
     * @param  string $scope
     * @return array|null  a count per step, or null when a step was refused
     */
    private function countFunnel( array $steps, $scope ) {

        if ( ! $steps ) {

            return array();
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $subject = self::SCOPES[ $scope ];
        $alias   = self::ALIAS;

        $zeroes = array_fill( 0, count( $steps ), 0 );

        /*
         * THE CUBE, not the request facts.
         *
         * A cube row is one event -- the build is an INSERT ... SELECT off raw
         * with no GROUP BY -- carrying every raw column under its own name plus
         * the derived ones. So it has the subject, the time and the id this walk
         * orders by, and a step can name the CLASSIFIED readings (source, medium,
         * new_vs_returning) and not only what a URL claimed.
         *
         * The funnel was already half here: the segment below picks its subjects
         * through the ordinary reporting stack, which resolves to this same
         * table. The walk read owa_request joined to owa_document, which v2
         * ingest does not write at all -- so the segment chose people and the
         * walk then found none of their events.
         *
         * One cube per Property, and the funnel is of the Property. A report
         * that resolved no Property has no cube to read, which is a funnel
         * that cannot be counted rather than an empty one.
         */
        $property = (string) $this->getParam( 'propertyId' );

        if ( ! $property ) {

            $this->set( 'funnel_step_error',
                \OWA\Core\CoreAPI::t( 'There is no Property to count the funnel against.' ) );

            return null;
        }

        /*
         * The table has to EXIST, not merely be nameable. tableFor() composes a
         * name from the Property id whether or not anything has ever built it, so
         * a Property that has never had a build would otherwise reach MySQL as
         * "Table 'owa_event_7' doesn't exist" -- an error about the schema for
         * what is really "there is nothing to count yet".
         */
        $cubes = \OWA\Module\Base\Classes\Cube\Cubes::existing();

        if ( ! isset( $cubes[ (string) $property ] ) ) {

            $this->set( 'funnel_step_error', \OWA\Core\CoreAPI::t(
                'This Property\'s cube has not been built yet, so there is nothing to '
                . 'count the funnel against. Run a build and reload.' ) );

            return null;
        }

        $table = $cubes[ (string) $property ];

        $params = array();
        $select = array();
        $any    = array();

        foreach ( $steps as $i => $step ) {

            $predicate = $this->stepPredicate( $step, $i + 1, $alias );

            if ( $predicate === null ) {

                // Refused, and stepPredicate() has said why. Not drawn with the
                // step ignored: a funnel missing a stage still looks like one.
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

        // The cube is the Property's, so every row in it is in the funnel's scope.
        $where = array( '( ' . implode( ' OR ', $any ) . ' )' );

        $bounds = $this->dateBounds();

        if ( $bounds ) {

            // Closed at both ends: the cube is RANGE-partitioned on yyyymmdd,
            // and an open bound reads every partition from there on.
            $where[]  = $alias . '.yyyymmdd BETWEEN ? AND ?';
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
         * query itself would filter the ROWS -- `medium==organic` would
         * drop every step the subject reached on some other medium and the
         * funnel would collapse for reasons that have nothing to do with the
         * funnel. So the segment picks the users and then counts all of their
         * events.
         */
        $segment = $this->segmentSubjects( $scope );

        if ( $segment === array() ) {

            // A segment that matches nobody is a funnel nobody entered, not an
            // unsegmented funnel.
            return $zeroes;
        }

        if ( is_array( $segment ) ) {

            $where[] = $alias . '.' . $subject . ' IN ('
                     . implode( ',', array_fill( 0, count( $segment ), '?' ) ) . ')';

            foreach ( $segment as $id ) {

                $params[] = $id;
            }
        }

        /*
         * ORDER BY carries a TIEBREAK, and it is not cosmetic.
         *
         * The walk reads rows in the order they arrive, so without a total
         * order two events in the same second could arrive either way round and
         * the same funnel could report different numbers on consecutive loads.
         * The id makes the order stable. It cannot make it MEANINGFUL -- ids
         * are the tracker's random GUID -- which is what groupsAtSameTime()
         * below exists to handle.
         */
        $e = $alias;

        /*
         * A SESSION IS (visitor_id, session_id). session_id alone is not
         * unique -- it embeds its creation second, so two visitors starting
         * together can share one (Entity\EventRaw) -- and keyed on it alone
         * their events merge into one subject.
         *
         * DEVICE ORDER FIRST, as the cube build orders a session: event_seq,
         * then ts, then id (Cube\Builder). ts is stamped on arrival, so a late
         * beacon would otherwise sort after what happened after it. A
         * visitor's sessions are taken in the order they began.
         *
         * THE TIE KEY handed to walk() as `ts`: events are only simultaneous
         * when nothing orders them. One with a device position is its own
         * moment; without one, the second it arrived in is.
         */
        if ( $scope === 'session' ) {

            // COALESCE: CONCAT() of a NULL is NULL, and every session without
            // a visitor id would otherwise be one subject.
            $subj  = 'CONCAT(COALESCE(' . $e . '.visitor_id, \'\'), \':\', ' . $e . '.session_id)';
            $order = $e . '.visitor_id, ' . $e . '.session_id';

        } else {

            $subj  = $e . '.visitor_id';
            $order = $e . '.visitor_id, MIN(' . $e . '.ts) OVER (PARTITION BY ' . $e . '.visitor_id, '
                   . $e . '.session_id), ' . $e . '.session_id';
        }

        $tie = 'CASE WHEN ' . $e . '.event_seq IS NULL THEN CAST(' . $e . '.ts AS CHAR)'
             . ' ELSE CONCAT(' . $e . '.session_id, \':\', ' . $e . '.event_seq) END';

        $sql = 'SELECT ' . $subj . ' AS subj, ' . $tie . ' AS ts, ' . $e . '.id AS rid, '
             . implode( ', ', $select )
             . ' FROM ' . $table . ' ' . $e
             . ' WHERE ' . implode( ' AND ', $where )
             . ' ORDER BY ' . $order . ', COALESCE(' . $e . '.event_seq, 0), '
             . $e . '.ts, ' . $e . '.id';

        /*
         * get_result_iterator(), NOT get_results().
         *
         * The rows here are the WORK, not the answer -- the answer is a handful
         * of integers. get_results() would build a PHP array of every one of
         * them first, which is 46MB per 100,000 rows and unbounded. This hands
         * them over one at a time; the walk never holds more than one second's
         * worth.
         */
        return self::walk( $db->get_result_iterator( $sql, $params ), count( $steps ) );
    }

    /**
     * Walk each subject's events forward, advancing a stage at a time.
     *
     * The rows arrive grouped by subject and ordered by time, so one pass is
     * enough: hold the stage this subject has reached, and advance it whenever
     * an event satisfies the step they are waiting for. A subject who reaches
     * stage N counts in every step up to N, which is what makes a funnel
     * monotonic by construction rather than by a guard.
     *
     * A subject is counted ONCE however many times they run the funnel, and it
     * is the first run that counts -- the walk never restarts.
     *
     * ONE EVENT, ONE STEP. Two steps satisfied by the same event is not two
     * steps: a step is something the subject DID, so the next one needs an
     * event of its own. Two steps written with overlapping conditions therefore
     * need two visits, which is what they say.
     *
     * TAKES AN ITERABLE, not an array, so the caller can stream. Everything
     * below holds at most one second's worth of one subject's events.
     *
     * @param  iterable $rows   subject-grouped, time-ordered, with an s{N} flag per step
     * @param  int      $count  how many steps
     * @return array
     */
    public static function walk( $rows, $count ) {

        $counts  = array_fill( 0, $count, 0 );
        $current = null;
        $stage   = 0;
        $tied    = array();

        /*
         * The tally happens when the SUBJECT CHANGES, not per row, which is why
         * there is a flush after the loop as well. Counting inside the loop
         * would need the stage of a subject whose last row has not been read
         * yet.
         */
        foreach ( $rows as $row ) {

            if ( $current !== $row['subj'] ) {

                $stage = self::resolveTied( $tied, $stage, $count );
                $tied  = array();

                for ( $i = 0; $i < $stage; $i++ ) {

                    $counts[ $i ]++;
                }

                $current = $row['subj'];
                $stage   = 0;
            }

            /*
             * Events sharing a timestamp are held back and resolved together.
             *
             * The fact table records whole SECONDS -- msec is declared INT and
             * fed the fractional part of microtime() as a string, so it rounds
             * to 0 or 1 and carries nothing -- and ids are random, so within
             * one second there is no evidence about what happened first.
             *
             * Ordering them arbitrarily would decide it by coin flip: the same
             * two events would count as a sequence for one visitor and not for
             * the next, for no reason anybody could point at. So they are
             * resolved as a SET instead, in the funnel's own order -- if
             * somebody hit /basket and /checkout inside one second, the reading
             * that they did it in that order is the only one worth having.
             *
             * Each event is still used at most once, so this cannot turn a
             * single page view into a completed funnel.
             *
             * The assumption becomes a real comparison when msec is fixed,
             * which is DECIDED FOR V2 rather than 1.x -- fixing it here would
             * change behaviour for new rows only, and leave old and new rows
             * ordering differently in the same report.
             */
            if ( $tied && $tied[0]['ts'] !== $row['ts'] ) {

                $stage = self::resolveTied( $tied, $stage, $count );
                $tied  = array();
            }

            $tied[] = $row;
        }

        $stage = self::resolveTied( $tied, $stage, $count );

        for ( $i = 0; $i < $stage; $i++ ) {

            $counts[ $i ]++;
        }

        return $counts;
    }

    /**
     * Advance the stage as far as one second's worth of events allows.
     *
     * Greedy and order-free: repeatedly look through the events nobody has
     * used yet for one satisfying the step being waited for, spend it, and go
     * again. A group of one -- which is nearly every group -- is the ordinary
     * "does this event advance the stage" test, so the common path costs a
     * single comparison.
     *
     * @param  array $tied
     * @param  int   $stage
     * @param  int   $count
     * @return int   the stage after this group
     */
    private static function resolveTied( array $tied, $stage, $count ) {

        $used = array();

        while ( $stage < $count ) {

            $spent = false;

            foreach ( $tied as $i => $row ) {

                if ( isset( $used[ $i ] ) || (int) $row[ 's' . $stage ] !== 1 ) {

                    continue;
                }

                $used[ $i ] = true;
                $stage++;
                $spent = true;

                break;
            }

            if ( ! $spent ) {

                break;
            }
        }

        return $stage;
    }

    /**
     * What the filter control may constrain on.
     *
     * Delegated, because the funnel's segment is the same ReportSegment other
     * reports use, and a picker that offered different choices in two places
     * would be lying about one of them.
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

            $period    = $this->getParam( 'period' );
            $startDate = $this->getParam( 'startDate' );
            $endDate   = $this->getParam( 'endDate' );

            /*
             * No period on the URL is the report's default period, which is
             * what the header says the numbers are for -- pre() resolved it.
             * Passed through as nothing, a filtered funnel queried with no
             * period at all and failed (a saved funnel opens with none), and an
             * unfiltered one counted all time under a header naming a week.
             */
            if ( ! $period && ! ( $startDate && $endDate ) && $this->getPeriod() ) {

                $period = $this->getPeriod()->get();
            }

            $this->segment = new \OWA\Module\Base\Classes\ReportSegment(
                $this->getParam( 'propertyId' ),
                $period,
                $startDate,
                $endDate,
                // The funnel's own filter AND the reader's: both narrow it.
                implode( ',', array_filter( array(
                    $this->savedConstraints, trim( (string) $this->getParam( 'constraints' ) ) ), 'strlen' ) )
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
