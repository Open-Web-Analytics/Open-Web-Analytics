<?php
namespace OWA\Module\Base\Classes;


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


/**
 * A segment: WHICH people a report is drawn for.
 *
 * WHAT A SEGMENT IS, AND WHAT IT IS NOT
 *
 * It selects SUBJECTS -- visitors or visits -- through an outer query, and the
 * report is then drawn over everything those subjects did. It is deliberately
 * not a filter on the report's own rows, and the difference is not academic.
 *
 * Constraining a funnel's own rows with `medium==organic-search` would drop
 * every step a subject reached on some other medium, and the funnel would
 * collapse for reasons that have nothing to do with the funnel. Constraining a
 * domstream list the same way would hide recordings made by exactly the people
 * the segment asked for. GA's segments pick the users and then show all of
 * their activity; this does the same.
 *
 * WHY IT RUNS THROUGH ResultSetManager
 *
 * Because a dimension does not resolve to a column on any one table. `medium`
 * is denormalized onto the fact row, but `browserType` resolves to
 * ua_via_.browser_type and `city` to location_dim_via_.city -- each needing a
 * join the result-set manager already knows how to build. Hand-rolling the
 * segment SQL would mean reimplementing that, and it would drift.
 *
 * It also means a segment accepts exactly the constraints every other report
 * does: validated against the registry, resolved through the same joins, and
 * refused the same way when a name does not exist.
 *
 * @since owa 1.8.0
 */
class ReportSegment {

    /**
     * How many subjects a segment may select before it refuses.
     *
     * The ids travel back into the report's own query as an IN list, so this
     * bounds the statement rather than expressing a view about how big a
     * segment is allowed to be.
     */
    const LIMIT = 10000;

    /**
     * Dimension groups the picker does not offer.
     *
     * `site`: the report is already scoped to one site, and the site filter in
     * the report chrome is where that is chosen. Offering siteId here is either
     * redundant or a way to ask for a contradiction.
     *
     * `time`: the reporting period already bounds the report, and a date inside
     * the SEGMENT means something almost nobody intends -- the segment selects
     * PEOPLE, so `date==20260825` picks everyone active that day and then shows
     * their activity across the whole period. It reads like "the report for
     * that day" and is not.
     */
    const EXCLUDED_GROUPS = array( 'site', 'time' );

    /** The dimension that names each kind of subject. */
    const SUBJECT_DIMENSIONS = array(
        'visitor' => 'visitorId',
        'session' => 'sessionId',
    );

    private $siteId;
    private $period;
    private $startDate;
    private $endDate;
    private $constraints;

    /** Why the segment selected nobody, when that was a refusal rather than a result. */
    private $error = null;

    public function __construct( $siteId, $period, $startDate, $endDate, $constraints = '' ) {

        $this->siteId      = $siteId;
        $this->period      = $period;
        $this->startDate   = $startDate;
        $this->endDate     = $endDate;
        $this->constraints = (string) $constraints;
    }

    /** Whether the reader has actually asked for a segment. */
    public function isApplied() {

        return $this->constraints !== '';
    }

    /** The constraint string, as it travels on the URL. */
    public function getConstraints() {

        return $this->constraints;
    }

    /**
     * Why the segment came back empty, if it was refused rather than unmatched.
     *
     * A report must say so instead of quietly drawing itself unsegmented, and
     * equally instead of drawing zeroes that read as "nobody did this".
     *
     * @return string|null
     */
    public function getError() {

        return $this->error;
    }

    /**
     * What the filter control may constrain on.
     *
     * The authority is the reporting stack, not a list written out here: a list
     * of our own would offer names the segment then refuses.
     *
     * Taken from an AGGREGATE-ONLY query -- asking for a metric with no
     * dimensions still comes back carrying the full related-dimension and
     * related-metric lists, and costs one aggregate rather than a group-by over
     * every visitor in the period.
     *
     * @return array {dimensions, metrics} in the shape the picker reads
     */
    public function options() {

        $empty = array( 'dimensions' => array(), 'metrics' => array() );

        /*
         * No period, no query. A report normally always has one, but its
         * controller is also constructed directly -- by the registry contract
         * test, and by anything checking a route -- and a picker is decoration:
         * it must never be the reason a report cannot render.
         */
        if ( ! $this->period && ! ( $this->startDate && $this->endDate ) ) {

            return $empty;
        }

        $rsm = new ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( 'visits' );
        $rsm->setSiteId( $this->siteId );
        $rsm->setTimePeriod( $this->period, $this->startDate, $this->endDate );
        $rsm->setLimit( 1 );

        try {

            $rs = $rsm->getResults();

        } catch ( \Throwable $e ) {

            \OWA\Core\CoreAPI::notice( 'Could not build segment filter options: ' . $e->getMessage() );

            return $empty;
        }

        $dimensions = (array) ( $rs->relatedDimensions ?? array() );

        foreach ( self::EXCLUDED_GROUPS as $group ) {

            unset( $dimensions[ $group ] );
        }

        return array(
            'dimensions' => $dimensions,
            'metrics'    => (array) ( $rs->relatedMetrics ?? array() ),
        );
    }

    /**
     * The subjects this segment selects.
     *
     * Three distinct answers, and a caller must tell them apart:
     *
     *   null     no segment was asked for -- do not restrict anything
     *   array()  a segment was asked for and selected nobody, OR was refused
     *            (getError() says which)
     *   ids      restrict to these
     *
     * The cap REFUSES rather than truncating. A silently shortened id list
     * would answer with a report that looks complete and covers a fraction of
     * the people in it, which is the wrong-but-plausible number that is worse
     * than an error.
     *
     * @param string $scope visitor|session
     * @return array|null
     */
    public function subjects( $scope ) {

        $this->error = null;

        if ( ! $this->isApplied() ) {

            return null;
        }

        $dimension = isset( self::SUBJECT_DIMENSIONS[ $scope ] )
                   ? self::SUBJECT_DIMENSIONS[ $scope ]
                   : self::SUBJECT_DIMENSIONS['visitor'];

        $rsm = new ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray( 'visits' );
        $rsm->setDimensions( $rsm->dimensionsStringToArray( $dimension ) );
        $rsm->setSiteId( $this->siteId );
        $rsm->setTimePeriod( $this->period, $this->startDate, $this->endDate );
        $rsm->setConstraints( $rsm->constraintsStringToArray( $this->constraints ) );
        $rsm->setLimit( self::LIMIT + 1 );

        $results = $rsm->getResults();

        // The constraint itself was refused -- an unknown dimension, a missing
        // value. Say so instead of quietly showing an unsegmented report.
        if ( ! empty( $results->request_errors ) ) {

            $this->error = implode( ' ', $results->request_errors );

            return array();
        }

        $ids = array();

        foreach ( (array) ( $results->resultsRows ?? array() ) as $row ) {

            if ( isset( $row[ $dimension ]['value'] ) ) {

                $ids[] = $row[ $dimension ]['value'];
            }
        }

        if ( count( $ids ) > self::LIMIT ) {

            $this->error = sprintf(
                'This segment selects more than %s %ss. Narrow it -- a report drawn from '
                . 'part of them would look complete and cover a fraction of the people in it.',
                number_format( self::LIMIT ), $scope
            );

            return array();
        }

        return $ids;
    }

    /**
     * The reporting period as yyyymmdd bounds.
     *
     * Resolved through timePeriod, the same class the result-set manager uses,
     * so a report and one beside it cannot disagree about what "last thirty
     * days" means.
     *
     * @return array|null
     */
    public function bounds() {

        $timePeriod = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );

        if ( $this->startDate && $this->endDate ) {

            $timePeriod->set( 'date_range', array(
                'startDate' => $this->startDate,
                'endDate'   => $this->endDate,
            ) );

        } elseif ( $this->period ) {

            $timePeriod->set( $this->period );

        } else {

            return null;
        }

        $start = $timePeriod->startDate->get( 'yyyymmdd' );
        $end   = $timePeriod->endDate->get( 'yyyymmdd' );

        return ( $start && $end ) ? array( 'start' => $start, 'end' => $end ) : null;
    }
}
