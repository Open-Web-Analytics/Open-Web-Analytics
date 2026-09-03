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
     * THE MATH
     *
     * A CLOSED funnel with INDIRECTLY-followed steps, counted per subject --
     * GA's defaults, and the only ones OWA offers. Closed: everybody enters at
     * step 1, so somebody who lands on step 2 first is not in the funnel at
     * all. Indirect: other things may happen in between, the next step only has
     * to come later. Per subject: a subject enters once in the period, and it
     * is their FIRST run through that is counted.
     *
     * THE SHAPE, AND WHY IT IS NOT ONE FLAT QUERY
     *
     * This tagged every matching event with which step it satisfied, ordered
     * the lot by subject and time, and walked it in PHP. The arithmetic was
     * right and the cost was not: one row came back per matching EVENT, and
     * get_results() materialises the whole set before the first row is read --
     * measured at 46MB per 100k rows, so a busy site over a long period is
     * hundreds of megabytes of PHP array to answer with half a dozen integers.
     * The work scaled with the data instead of with the answer.
     *
     * So the sequencing happens where the data is. Each step is a derived table
     * over the one before it, carrying every subject and the time they reached
     * each step so far:
     *
     *     level 0    subj, MIN(time) among events matching step 1
     *     level 1    subj, t0, MIN(time) among events matching step 2 AFTER t0
     *     level 2    subj, t0, t1, MIN(time) matching step 3 AFTER t1
     *
     * LEFT JOIN rather than INNER, and that is the load-bearing choice: a
     * subject who stops is KEPT, with a null time from there on. Nulls
     * propagate -- comparing against a null t gives null, so every later step
     * is null too -- which is what lets one statement answer for every step at
     * once. An inner join would shrink to the completers and lose the counts
     * for the steps before.
     *
     * COUNT() ignores nulls, so the final SELECT is the whole answer: N
     * integers, one round trip, and nothing but those integers crosses the
     * wire.
     *
     * WHAT IT COSTS, SAID PLAINLY
     *
     * Nesting depth is the step count, which is why steps are capped. On MySQL
     * 5.x each level materialises rather than merging, so a deep funnel is a
     * stack of temporary result sets -- each one smaller than the last, because
     * a subject who never entered is not in level 0 to begin with.
     *
     * THE ONE THING THE WALK DID THAT THIS CANNOT
     *
     * The walk ordered rows and took them one at a time, so two events in the
     * same SECOND were still two events and could satisfy two steps. SQL has
     * only the timestamp to compare, and owa_request records whole seconds --
     * msec is declared INT and fed the fractional part of microtime() as a
     * string, so it rounds to 0 or 1 and carries no information (there is a
     * "wrong data type" comment beside it in the entity). Request ids cannot
     * stand in either: they are the tracker's random GUID, so they are unique
     * but not ordered.
     *
     * So two steps completed within one second are not counted as a sequence.
     * It is a real undercount, it is narrow -- it needs two consecutive funnel
     * steps inside the same second, which mostly means a redirect -- and it is
     * asserted in GoalFunnelOrderTest rather than left to be rediscovered.
     * Fixing msec would remove the limitation without changing this code.
     *
     * @param  array  $steps
     * @param  string $scope
     * @return array|null  a count per step, or null when a step was refused
     */
    private function countFunnel( array $steps, $scope ) {

        if ( ! $steps ) {

            return array();
        }

        $subject = self::SCOPES[ $scope ];

        /*
         * The parts every level shares: which site, which days, and which
         * subjects the segment allows. Applied at EVERY level rather than only
         * the first -- a later step must be reached inside the period too, or
         * a funnel over last week would count a conversion from today.
         */
        $common = $this->commonRestriction( $subject, $scope );

        if ( $common === null ) {

            // A segment that matches nobody is a funnel nobody entered, not an
            // unsegmented funnel.
            return array_fill( 0, count( $steps ), 0 );
        }

        $level = null;

        foreach ( $steps as $i => $step ) {

            $predicate = $this->stepPredicate( $step );

            if ( $predicate === null ) {

                // Refused, and stepPredicate() has said why. Not drawn with the
                // step ignored: a funnel missing a stage still looks like one.
                return null;
            }

            $level = $level === null
                ? $this->entryLevel( $subject, $predicate, $common )
                : $this->nextLevel( $subject, $predicate, $common, $level, $i );
        }

        return $this->countLevels( $level, count( $steps ) );
    }

    /**
     * Site, days and segment -- the restriction every level carries.
     *
     * @param  string $subject
     * @param  string $scope
     * @return array|null  array( 'sql', 'params' ), or null if nobody qualifies
     */
    private function commonRestriction( $subject, $scope ) {

        $where   = array( 'r.site_id = ?' );
        $params  = array( (string) $this->getParam( 'siteId' ) );

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
         * Deliberately not folded in as an ordinary condition. Constraining the
         * funnel query itself would filter the ROWS -- `medium==organic-search`
         * would drop every step the subject reached on some other medium and
         * the funnel would collapse for reasons that have nothing to do with
         * the funnel. GA's segments pick the users and then count all of their
         * events; this does the same.
         */
        $segment = $this->segmentSubjects( $scope );

        if ( $segment === array() ) {

            return null;
        }

        if ( is_array( $segment ) ) {

            $where[] = 'r.' . $subject . ' IN ('
                     . implode( ',', array_fill( 0, count( $segment ), '?' ) ) . ')';

            foreach ( $segment as $id ) {

                $params[] = $id;
            }
        }

        return array( 'sql' => implode( ' AND ', $where ), 'params' => $params );
    }

    /**
     * Level 0: who entered, and when.
     *
     * An INNER join to the document here, and the step's condition in the
     * WHERE, because a subject who never matched step 1 is not in the funnel --
     * that is what closed means, and it is what keeps every later level to the
     * entry population rather than to everyone on the site.
     *
     * @return array array( 'sql', 'params' )
     */
    private function entryLevel( $subject, array $predicate, array $common ) {

        $sql = 'SELECT r.' . $subject . ' AS subj, '
             . sprintf( OWA_SQL_MIN, 'r.timestamp' ) . ' AS t0'
             . ' FROM owa_request r'
             . ' INNER ' . OWA_SQL_JOIN . ' owa_document d ON d.id = r.document_id'
             . ' WHERE ' . $common['sql'] . ' AND ' . $predicate['sql']
             . ' GROUP BY r.' . $subject;

        return array(
            'sql'    => $sql,
            'params' => array_merge( $common['params'], $predicate['params'] ),
        );
    }

    /**
     * Level k: the first time each subject met step k AFTER meeting step k-1.
     *
     * The previous level is the FROM, so the subjects are already the ones who
     * entered. Everything they did is left-joined back on, and the CASE records
     * a time only for rows that satisfy this step and fall after the previous
     * one -- so a subject who stopped keeps their row and gains a null.
     *
     * PARAMETER ORDER IS TEXTUAL. The CASE sits in the SELECT list, ahead of
     * the FROM that holds the level below it, so this step's parameters bind
     * before every parameter of every level beneath. Assembled here in exactly
     * that order rather than gathered up afterwards, because two orders that
     * have to agree is the kind of thing that silently stops agreeing.
     *
     * @return array array( 'sql', 'params' )
     */
    private function nextLevel( $subject, array $predicate, array $common, array $previous, $k ) {

        $carried = array( 'p.subj AS subj' );

        // Every earlier step's time, carried forward so the final SELECT can
        // count them. Through MIN() rather than named in the GROUP BY: they are
        // constant within the group either way, and this does not depend on how
        // strictly the server reads a grouped query.
        for ( $j = 0; $j < $k; $j++ ) {

            $carried[] = sprintf( OWA_SQL_MIN, 'p.t' . $j ) . ' AS t' . $j;
        }

        $reached = sprintf( OWA_SQL_CASE_WHEN,
            $predicate['sql'] . ' AND r.timestamp > p.t' . ( $k - 1 ),
            'r.timestamp' );

        $carried[] = sprintf( OWA_SQL_MIN, $reached ) . ' AS t' . $k;

        $sql = 'SELECT ' . implode( ', ', $carried )
             . ' FROM ( ' . $previous['sql'] . ' ) p'
             . ' ' . OWA_SQL_LEFT_JOIN . ' owa_request r'
             . ' ON r.' . $subject . ' = p.subj AND ' . $common['sql']
             . ' ' . OWA_SQL_LEFT_JOIN . ' owa_document d ON d.id = r.document_id'
             . ' GROUP BY p.subj';

        return array(
            'sql'    => $sql,
            'params' => array_merge(
                $predicate['params'], $previous['params'], $common['params'] ),
        );
    }

    /**
     * One count per step, from the finished chain.
     *
     * COUNT( t{n} ) skips nulls, and a null is exactly "did not get this far",
     * so this is the cumulative count at each step -- everyone who reached AT
     * LEAST that far. Monotonic by construction: t{n} can only be non-null
     * where t{n-1} was.
     *
     * @return array
     */
    private function countLevels( array $level, $count ) {

        $columns = array();

        for ( $i = 0; $i < $count; $i++ ) {

            $columns[] = sprintf( OWA_SQL_COUNT, 'f.t' . $i ) . ' AS c' . $i;
        }

        $sql = 'SELECT ' . implode( ', ', $columns )
             . ' FROM ( ' . $level['sql'] . ' ) f';

        /*
         * get_results(), NOT query()->fetchAll().
         *
         * query() hands back the DRIVER's own result -- a PDOStatement under
         * pdo, a mysqli_result under mysqli -- and only one of those has
         * fetchAll(). get_results() is the pair's common contract: assoc rows,
         * or NULL for both "no rows" and "the query failed". It returns exactly
         * one row here, whatever the size of the funnel.
         */
        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results( $sql, $level['params'] );

        if ( $rows === null ) {

            // Null covers a failed query AND an empty result, so this is not an
            // error worth a notice -- a funnel nobody entered is a real answer,
            // and it is the same zeroes either way.
            return array_fill( 0, $count, 0 );
        }

        $row    = (array) $rows[0];
        $counts = array();

        for ( $i = 0; $i < $count; $i++ ) {

            $counts[] = (int) ( $row[ 'c' . $i ] ?? 0 );
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
