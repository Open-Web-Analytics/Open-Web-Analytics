<?php
namespace OWA\Module\Base\Controller;



/**
 * Report REST Controller.
 *
 * Request parameters:
 *
 *   propertyId   string     the Property the report is of
 *   siteId       string     one of its Profiles, to filter to; names the
 *                           Property when propertyId is absent
 *   report_name  string     'clickstream'
 *   metrics      string     'foo,bar'
 *   dimensions   string     'dim1,dim2,dim3'
 *   period       string     'today'
 *   startDate    string     'yyyymmdd'
 *   endDate      string     'yyyymmdd'
 *   startTime    timestamp
 *   endTime      timestamp
 *   constraints  string     'con1=foo, con2=bar'
 *   page         int        1
 *   offset       int        0
 *   limit        int        10
 *   sort         string     'dim1,dim2'
 */
class ReportsRest extends \OWA\Core\ReportController {

    /** A siteId filters to that Profile, so a client asking for one site's numbers gets them. */
    protected $filters_by_site = true;
    
    public function __construct($params) {
        
        parent::__construct($params);
        $this->setRequiredCapability('view_reports');

        // An overlay (heatmap, recording player) reads this route with its
        // scoped token; no other route accepts one.
        $this->setAuthMethods( [ 'overlay_token', 'app_password', 'cookies' ] );
    }
    
    public function validate() {
        
        /*
         * The same range rule the web path enforces, from the same place, so the
         * two cannot drift on what a date range is -- as they had drifted on
         * what a period is until both were pointed at getValidPeriods().
         *
         * Applied to every report this controller serves, not just the generic
         * resultset branch: the named reports below take the same startDate and
         * endDate parameters and had the same inverted-window behaviour.
         */
        $range = new \OWA\Core\Validation\DateRange();

        $range->setValues( [
            'period'    => $this->getParam('period'),
            'startDate' => $this->getParam('startDate'),
            'endDate'   => $this->getParam('endDate'),
        ] );

        $this->setValidation( 'dateRange', $range );

        $compare = new \OWA\Core\Validation\CompareRange();

        $compare->setValues( [
            'compare'          => $this->getParam('compare'),
            'compareStartDate' => $this->getParam('compareStartDate'),
            'compareEndDate'   => $this->getParam('compareEndDate'),
        ] );

        $this->setValidation( 'compareRange', $compare );

        // if no report name is specified do these validations necesary for generic resultSet.
        if ( ! $this->get('report_name') ) {
            
            // metrics are required for resultset queries		
            $this->addValidation( 'metrics', $this->getParam('metrics'), 'required', ['stopOnError'	=> true] );
            
            // make sure period string is valid
            if ( $this->get( 'period' ) ) {
                
                /*
                 * The same list the web path validates against, rather than a
                 * second one built here. These had drifted: this controller
                 * used the dropdown's labels alone, so `period=date_range` --
                 * accepted everywhere else -- was rejected over the API, and
                 * the only way to ask for a custom range was to omit the
                 * parameter and let it be inferred.
                 *
                 * That inference stays. Naming the period is now permitted, not
                 * required; a caller sending two dates and nothing else is
                 * still doing the normal thing.
                 */
                $period = new \OWA\Module\Base\Classes\TimePeriod();
                $this->addValidation('period', $this->getParam('period'), 'inArray', ['possible_values' => $period->getValidPeriods(), 'stopOnError' => true] );
            }
        } else {

            /*
             * The named reports -- visit, clickstream, latest visits and
             * actions, transactions -- read v1's tables and went with them in
             * 2.0. Nothing in the UI called them. A name is refused rather than
             * ignored, so a caller still using one learns it is gone instead of
             * receiving an unrelated result set.
             */
            $this->addValidation( 'report_name', $this->get('report_name'), 'inArray',
                [ 'possible_values' => [], 'stopOnError' => true ] );
        }
    }
    
    /** What a request signed in by an overlay token may not ask for: its token does not bind them. */
    const NOT_FOR_OVERLAY_TOKENS = [ 'segment', 'rowValues' ];

    public function action() {

        if ( \OWA\Core\Auth::get_instance()->getAuthMethod() === 'overlay_token' ) {

            foreach ( self::NOT_FOR_OVERLAY_TOKENS as $key ) {

                if ( ! in_array( $this->getParam( $key ), [ null, '' ], true ) ) {

                    $response = new \OWA\Module\Base\Classes\PaginatedResultSet();
                    $response->request_errors = [
                        sprintf( \OWA\Core\CoreAPI::t( 'An overlay link cannot ask for %s.' ), $key ) ];

                    $this->set( 'response', $response );

                    return;
                }
            }
        }

        /*
         * Reporting not ready for this Property: it has no cube yet.
         * Answered before any result set is built, so nothing queries a table
         * that does not exist, and with the reason rather than empty rows -- a
         * client reading rows alone could not tell "not built yet" from zero.
         */
        $propertyId = $this->propertyOfRequest();

        $readiness = $this->get( 'propertyId' ) || $this->get( 'siteId' )
            ? \OWA\Module\Base\Classes\Cube\Status::readinessOfProperty( $propertyId ) : null;

        if ( $readiness ) {

            $response = new \OWA\Module\Base\Classes\PaginatedResultSet();
            $response->notReady = $readiness;

            $this->set( 'response', $response );

            return;
        }

        $this->set( 'response', $this->getResultSet() );
    }
    
    public function success() {

        /*
         * A malformed request is not a success, whatever the envelope contains.
         *
         * This answered 201 unconditionally, so a query refused for naming a
         * dimension that does not exist -- or for a constraint whose value went
         * missing -- came back as success carrying an `errors` array that a
         * client had no reason to read. The status is the only part most callers
         * check.
         *
         * 422 rather than 400: the request is well-formed HTTP and the
         * parameters are the right shape, they just cannot be honoured. It is
         * the code this controller already answers with for a failed validation.
         */
        /*
         * $this->data, not $this->get().
         *
         * On a controller set() writes the data the VIEW is handed while get()
         * reads a REQUEST PARAMETER -- they are not a pair. Reading the response
         * back with get('response') returns null on every request, so this
         * answered 201 unconditionally and looked like it worked.
         */
        $response = $this->data['response'] ?? null;

        $status = 201;

        if ( is_object( $response ) && ! empty( $response->request_errors ) ) {

            $status = 422;

        } elseif ( is_object( $response ) && ! empty( $response->notReady ) ) {

            /*
             * 409, not 503: the server is fine, and the request is valid; what
             * it asks about is not in a state to answer yet. Not 201 with no
             * rows, which is how a real zero looks.
             */
            $status = 409;
        }

        $this->restResponse( $status, 'response' );
    }
    
    public function errorAction() {

        $this->restResponse( 422 );
    }
    
    /**
     * The Property the request names, or else the one its siteId belongs to; '' for neither.
     *
     * @return string
     */
    private function propertyOfRequest() {

        return (string) ( $this->get( 'propertyId' )
            ?: \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( (string) $this->get( 'siteId' ) ) );
    }

    /**
     * Generates a data result set using metrics and dimension
     *
     * @return object|false the result set, as withComparison() answers it; false without metrics
     */
    public function getResultSet() {

        $rsm = $this->resultSetManager();

        if ( ! $rsm ) {

            return false;
        }

        $this->refused_before = \OWA\Core\CoreAPI::dbSingleton()->queryErrorCount();

        return $this->withComparison( $rsm, $rsm->getResults() );
    }

    /** The most rows a trend's comparison query reads. */
    const COMPARE_ROW_LIMIT = 1000;

    /**
     * The most rows a request may ask for a page of. The query fetches ten
     * pages ahead to know whether there is a next one, into PHP, so an uncapped
     * resultsPerPage let any reader of reports pull a table of any size through
     * a small server. The widgets that need most ask for 1000.
     */
    const MAX_RESULTS_PER_PAGE = 1000;

    /**
     * A request's page number or page size as a whole number from 1 to $max.
     *
     * @param  mixed $value as the request carried it
     */
    public static function wholeNumber( $value, int $max = PHP_INT_MAX ): int {

        return max( 1, min( $max, (int) ( is_numeric( $value ) ? $value : 1 ) ) );
    }

    /**
     * The result set with the comparison period's numbers beside its own,
     * when the request names one (`compare`). See CompareMerger.
     *
     * The comparison is the same query over the comparison's dates.
     *
     * A TREND's comparison is a line of its own: the comparison's rows,
     * restricted to the values of its other dimensions on the page (an `in`
     * constraint per dimension), at most COMPARE_ROW_LIMIT of them.
     *
     * A GRID's rows are those of both periods. The page's own rows are
     * compared with the same groups in the comparison (restrictToGroups()).
     * Then come the groups only the comparison has (compareOnlyRows()).
     *
     * @param \OWA\Module\Base\Classes\ResultSetManager $rsm the period's query
     * @param mixed $rs its result set
     * @return object the result set, with ->compare when one was asked for
     */
    public function withComparison( $rsm, $rs ) {

        $compare = is_object( $rs ) && empty( $rs->request_errors ) && empty( $rs->notReady ) && $rsm->timePeriod
            ? $rsm->timePeriod->getCompareFromMap( $this->params ) : null;

        if ( ! $compare ) {

            return $rs;
        }

        $over = [
            'period'         => 'date_range',
            'startDate'      => $compare['startDate'],
            'endDate'        => $compare['endDate'],
            'resultsPerPage' => self::COMPARE_ROW_LIMIT,
            'page'           => 1,
            'offset'         => null,
        ];

        $dims = (array) $rsm->dimensions;
        $main = [ $rsm->timePeriod->getStartDate()->getYyyymmdd(), $rsm->timePeriod->getEndDate()->getYyyymmdd() ];
        $cmp  = [ $compare['startDate'], $compare['endDate'] ];

        /*
         * The totals from a query of their own, with no dimensions and so no
         * restriction to the page: a total of the rows on the page is not the
         * period's total.
         */
        $totals = $this->resultSetManager( $over + [ 'dimensions' => '', 'sort' => '' ] )->getResults();

        if ( ! $dims ) {

            return \OWA\Module\Base\Classes\CompareMerger::merge( $rs, $totals, [], $main, $cmp, $compare['compare'] );
        }

        /*
         * A trend is compared even with no rows of its own: "this week" on a
         * quiet Sunday morning still draws last week.
         */
        if ( array_intersect( $dims, array_keys( \OWA\Module\Base\Classes\CompareMerger::TIME_AXIS ) ) ) {

            $crsm = $this->resultSetManager( $over );

            foreach ( \OWA\Module\Base\Classes\CompareMerger::restrictable( $dims ) as $dim ) {

                $values = [];

                foreach ( (array) $rs->resultsRows as $row ) {

                    $values[] = isset( $row[ $dim ] ) && is_array( $row[ $dim ] ) ? $row[ $dim ]['value'] : null;
                }

                /*
                 * Not when the request already filters this dimension (one
                 * constraint per name: this would replace it), or when a value is
                 * missing -- IN never matches NULL, and a "(not set)" row would
                 * lose its comparison. The row limit bounds the query instead.
                 */
                if ( $values && ! $crsm->getConstraint( $dim )
                        && ! in_array( null, $values, true ) && ! in_array( '', $values, true ) ) {

                    $crsm->setConstraint( $dim, array_values( array_unique( $values ) ), 'in' );
                }
            }

            $crs = $crsm->getResults();
            $crs->aggregates = $totals->aggregates;

            return \OWA\Module\Base\Classes\CompareMerger::merge( $rs, $crs, $dims, $main, $cmp, $compare['compare'] );
        }

        $crs = new \OWA\Module\Base\Classes\PaginatedResultSet();

        if ( $rs->resultsRows ) {

            $crsm = $this->resultSetManager( $over );
            $crsm->setRowsOnly();
            $crsm->restrictToGroups( array_map( function ( $row ) use ( $dims ) {
                return \OWA\Module\Base\Classes\ResultSetManager::groupKeyOf( $row, $dims );
            }, array_values( (array) $rs->resultsRows ) ) );

            $crs = $crsm->getResults();
        }

        $crs->aggregates = $totals->aggregates;

        \OWA\Module\Base\Classes\CompareMerger::merge( $rs, $crs, $dims, $main, $cmp, $compare['compare'] );

        return $this->compareOnlyRows( $rsm, $rs, $over );
    }

    /** @var int|null statements the database had refused when this request's query began; see getResultSet() */
    private $refused_before = null;

    /**
     * A grid's rows of groups only the comparison has, after the period's own
     * rows: ordered by the grid's sort over the comparison's numbers, and
     * paged with them. One query: the comparison's groups NOT IN the period's
     * (excludeGroupsOf()), from the row where this page's place falls past
     * the period's rows (setRowWindow()).
     *
     * The period's rows are read ten pages ahead (ResultSetManager); short of
     * that, where they end is known, and the rest of the read-ahead is filled
     * from these. A page past them counts them (countGroups()).
     *
     * None when the request filters on a metric: that selects rows by the
     * period's numbers, which these do not have. None either when a query of
     * this request was refused: the period's rows may be missing rather than
     * none.
     *
     * @param \OWA\Module\Base\Classes\ResultSetManager $rsm the period's query
     * @param object $rs its result set
     * @param array<string, mixed> $over the comparison's request parameters, as resultSetManager() takes them
     * @return object $rs
     */
    private function compareOnlyRows( $rsm, $rs, array $over ) {

        $per     = (int) $rsm->getLimit();
        $offset  = $per * ( max( 1, (int) $rsm->getPage() ) - 1 );
        $ahead   = $per * 10;
        $fetched = (int) $rs->resultsTotal;

        if ( $per < 1 || $fetched >= $ahead || $rsm->metricConstraints()
                || ( $this->refused_before !== null
                     && \OWA\Core\CoreAPI::dbSingleton()->queryErrorCount() > $this->refused_before ) ) {

            return $rs;
        }

        $ends = $fetched > 0 ? $offset + $fetched : ( $offset > 0 ? $rsm->countGroups() : 0 );

        $only = $this->resultSetManager( $over );
        $only->setRowsOnly();
        $only->excludeGroupsOf( $rsm );
        $only->setRowWindow( max( 0, $offset - $ends ), $ahead - $fetched );

        $ors = $only->getResults();

        if ( ! empty( $ors->request_errors ) || empty( $ors->resultsRows ) ) {

            return $rs;
        }

        $format = function ( $data_type, $value ) use ( $rsm ) {
            return $rsm->formatValue( $data_type, $value );
        };

        $rows = [];

        foreach ( (array) $ors->resultsRows as $row ) {

            $rows[] = \OWA\Module\Base\Classes\CompareMerger::compareOnlyRow( $row, $format );
        }

        $rs->appendRows( $rows, count( $rows ) );

        return $rs;
    }

    /**
     * The query this request asks for, set up and not yet run.
     *
     * @param array<string, mixed> $overrides request parameters to use instead of the
     *                         request's own: how the comparison's query is
     *                         the same query over other dates
     * @return \OWA\Module\Base\Classes\ResultSetManager|false  false without metrics
     */
    public function resultSetManager( array $overrides = [] ) {

        $get = function ( $name ) use ( $overrides ) {
            return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $this->get( $name );
        };

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        if ( $this->getParam('metrics') ) {
            
            $rsm->metrics = $rsm->metricsStringToArray( $this->getParam('metrics') );
            
        } else {
            
            return false;
        }

        /*
         * Whose dimensions the request's names are, before the segment and the
         * constraints are checked against them: a custom dimension is one
         * Property's. For the vocabulary only (setVocabularyProperty()): the
         * Property and the siteId filter are still set after the constraints,
         * so the siteId param replaces a siteId constraint the request carried.
         */
        $rsm->setVocabularyProperty( $this->propertyOfRequest() );

        // set dimensions
        if ( $get('dimensions') ) {
            
            $rsm->setDimensions($rsm->dimensionsStringToArray( $get('dimensions') ));
        }

        if ( $this->getParam('segment') ) {
            
            $rsm->setSegment( $this->getParam('segment') );
        }

        // set period
        if ( ! $this->getParam('period') ) {
            
            $this->setParam('period', 'today');
        }

        $rsm->setTimePeriod(
            $get( 'period' ),
            $get( 'startDate' ),
            $get( 'endDate' ),
            $get( 'startTime' ),
            $get( 'endTime' )
        );

        // set constraints
        if ( $this->get( 'constraints' ) ) {

            $rsm->setConstraints( $rsm->constraintsStringToArray( $this->get( 'constraints' ) ) );
        }

        if ( $this->get('propertyId') ) {

            $rsm->setPropertyId( $this->get('propertyId') );
        }

        if ( $this->get('siteId') ) {
    
            $rsm->setSiteId( $this->get('siteId' ) );
        }

        $this->restrictToRowValues( $rsm, $this->get( 'rowValues' ) );

        /*
         * The comparison and the row restriction travel on the result set's
         * own links (self, next, previous), so a refetch from one -- a grid's
         * next page, a trend changed from days to months -- is still compared
         * and still of those rows. Not on the comparison's own query: that is
         * the one without overrides.
         */
        if ( ! $overrides ) {

            foreach ( [ 'compare', 'compareStartDate', 'compareEndDate', 'rowValues' ] as $key ) {

                $value = $this->get( $key );

                if ( $value !== null && $value !== '' ) {

                    $rsm->query_params[ $key ] = $value;
                }
            }
        }

        // set sort order
        if ( $get('sort') ) {
            
            $rsm->setSorts($rsm->sortStringToArray( $get('sort') ) );
        }

        // set limit: a whole number, at most MAX_RESULTS_PER_PAGE. Text here
        // reached arithmetic in the result set and ended in a TypeError.
        if ( $get('resultsPerPage') ) {

            $rsm->setLimit( self::wholeNumber( $get('resultsPerPage'), self::MAX_RESULTS_PER_PAGE ) );
        }

        // set page
        if ( $get('page') ) {

            $rsm->setPage( self::wholeNumber( $get('page') ) );
        }

        // set offset
        if ( $get('offset') ) {
            
            $rsm->setOffset( max( 0, (int) ( is_numeric( $get('offset') ) ? $get('offset') : 0 ) ) );
        }

        return $rsm;
    }

    /** The most values `rowValues` may name for one dimension. */
    const ROW_VALUES_LIMIT = 25;

    /**
     * Only the rows a grid is showing: `rowValues` is a JSON object of
     * dimension name to a list of its values, and each becomes an `in`
     * constraint. How a trend draws a line per row of the grid under it.
     *
     * Not a constraint string: those are split on commas, and a value such as
     * a page path may contain one. A comparison's query is set up here too, so
     * it is restricted to the same rows.
     *
     * Empty values are dropped: IN never matches NULL.
     *
     * @param  \OWA\Module\Base\Classes\ResultSetManager $rsm
     * @param  mixed $json the request's rowValues
     * @return void
     */
    private function restrictToRowValues( $rsm, $json ) {

        if ( $json === null || $json === '' || $json === false ) {

            return;
        }

        $map = is_string( $json ) ? json_decode( $json, true ) : null;

        if ( ! is_array( $map ) || ! $map ) {

            $rsm->addRequestError( \OWA\Core\CoreAPI::t( 'rowValues must be a JSON object of dimension names to lists of values.' ) );

            return;
        }

        $restrictable = \OWA\Module\Base\Classes\CompareMerger::restrictable( (array) $rsm->dimensions );

        foreach ( $map as $dim => $values ) {

            if ( ! in_array( (string) $dim, $restrictable, true ) ) {

                $rsm->addRequestError( sprintf( \OWA\Core\CoreAPI::t( 'rowValues names %s, which is not one of the query\'s dimensions.' ), $dim ) );

                continue;
            }

            $values = array_values( array_unique( array_filter( array_map( function ( $v ) {
                return is_scalar( $v ) ? (string) $v : '';
            }, is_array( $values ) ? $values : [] ), static fn ( $value ) => $value !== '' ) ) );

            if ( ! $values || count( $values ) > self::ROW_VALUES_LIMIT ) {

                $rsm->addRequestError( sprintf( \OWA\Core\CoreAPI::t( 'rowValues needs 1 to %d values for %s.' ), self::ROW_VALUES_LIMIT, $dim ) );

                continue;
            }

            $rsm->setConstraint( (string) $dim, $values, 'in' );
        }
    }
}
