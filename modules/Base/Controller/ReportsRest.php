<?php
namespace OWA\Module\Base\Controller;



/**
 * Report REST Controller.
 *
 * @param propertyId	string		the Property the report is of
 * @param siteId		string		one of its Profiles, to filter to; names the
 *								Property when propertyId is absent
 * @param report_name	string		'clickstream'
 * @param metrics	 	string		'foo,bar'
 * @param dimensions 	string		'dim1,dim2,dim3'
 * @param period	 	string		'today'
 * @param startDate		string		'yyyymmdd'
 * @param endDate		string		'yyyymmdd'
 * @param startTime		timestamp	timestamp
 * @param endTime		timestamp	timestamp
 * @param constraints	string		'con1=foo, con2=bar'
 * @param page => 		int			1
 * @param offset	 	int			0
 * @param limit			int			10
 * @param sort			string		'dim1,dim2'
 */
class ReportsRest extends \OWA\Core\ReportController {

	/** A siteId filters to that Profile, so a client asking for one site's numbers gets them. */
	protected $filters_by_site = true;
	
	function __construct($params) {
		
        parent::__construct($params);
        $this->setRequiredCapability('view_reports');
    }
	
	function validate() {
		
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

		$range->setValues( array(
			'period'    => $this->getParam('period'),
			'startDate' => $this->getParam('startDate'),
			'endDate'   => $this->getParam('endDate'),
		) );

		$this->setValidation( 'dateRange', $range );

		$compare = new \OWA\Core\Validation\CompareRange();

		$compare->setValues( array(
			'compare'          => $this->getParam('compare'),
			'compareStartDate' => $this->getParam('compareStartDate'),
			'compareEndDate'   => $this->getParam('compareEndDate'),
		) );

		$this->setValidation( 'compareRange', $compare );

		// if no report name is specified do these validations necesary for generic resultSet.
		if ( ! $this->get('report_name') ) {
			
			// metrics are required for resultset queries		
			$this->addValidation( 'metrics', $this->getParam('metrics'), 'required', array('stopOnError'	=> true) );
			
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
				$period = \OWA\Core\CoreAPI::supportClassFactory('base', 'timePeriod');
				$this->addValidation('period', $this->getParam('period'), 'inArray', array('possible_values' => $period->getValidPeriods(), 'stopOnError' => true) );
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
				array( 'possible_values' => array(), 'stopOnError' => true ) );
		}
	}
	
	function action() {

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

			$response = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'paginatedResultSet' );
			$response->notReady = $readiness;

			$this->set( 'response', $response );

			return;
		}

		$this->set( 'response', $this->getResultSet() );
	}
	
	function success() {

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

		if ( is_object( $response ) && ! empty( $response->request_errors ) ) {

			http_response_code(422);

		} elseif ( is_object( $response ) && ! empty( $response->notReady ) ) {

			/*
			 * 409, not 503: the server is fine, and the request is valid; what
			 * it asks about is not in a state to answer yet. Not 201 with no
			 * rows, which is how a real zero looks.
			 */
			http_response_code(409);

		} else {

			http_response_code(201);
		}

		$this->setView( 'base.reportsRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.restApi' );

	}
	
	/** The Property the request names, or else the one its siteId belongs to; '' for neither. */
	private function propertyOfRequest() {

		return (string) ( $this->get( 'propertyId' )
			?: \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( (string) $this->get( 'siteId' ) ) );
	}

	/**
     * Generates a data result set using metrics and dimension
     *
     * @return paginatedResultSet obj
     */
    function getResultSet() {

        $rsm = $this->resultSetManager();

        if ( ! $rsm ) {

            return false;
        }

        return $this->withComparison( $rsm, $rsm->getResults() );
    }

    /** The most rows a comparison query reads to match a page of rows. */
    const COMPARE_ROW_LIMIT = 1000;

    /**
     * The most rows a request may ask for a page of. The query fetches ten
     * pages ahead to know whether there is a next one, into PHP, so an uncapped
     * resultsPerPage let any reader of reports pull a table of any size through
     * a small server. The widgets that need most ask for 1000.
     */
    const MAX_RESULTS_PER_PAGE = 1000;

    /** A request's page number or page size as a whole number from 1 to $max. */
    public static function wholeNumber( $value, int $max = PHP_INT_MAX ): int {

        return max( 1, min( $max, (int) ( is_numeric( $value ) ? $value : 1 ) ) );
    }

    /**
     * The result set with the comparison period's numbers beside its own,
     * when the request names one (`compare`). See CompareMerger.
     *
     * The comparison is the same query over the comparison's dates. A grid is
     * a page of rows, and the comparison's own top rows need not be the same
     * values, so its query is restricted to the values on the page (an `in`
     * constraint per dimension, except a time-axis one, which is matched by
     * position and is complete for the period anyway).
     *
     * @return object the result set, with ->compare when one was asked for
     */
    public function withComparison( $rsm, $rs ) {

        $compare = is_object( $rs ) && empty( $rs->request_errors ) && empty( $rs->notReady ) && $rsm->timePeriod
            ? $rsm->timePeriod->getCompareFromMap( $this->params ) : null;

        if ( ! $compare ) {

            return $rs;
        }

        $over = array(
            'period'         => 'date_range',
            'startDate'      => $compare['startDate'],
            'endDate'        => $compare['endDate'],
            'resultsPerPage' => self::COMPARE_ROW_LIMIT,
            'page'           => 1,
            'offset'         => null,
        );

        $dims = (array) $rsm->dimensions;

        /*
         * The totals from a query of their own, with no dimensions and so no
         * restriction to the page: a total of the rows on the page is not the
         * period's total.
         */
        $totals = $this->resultSetManager( $over + array( 'dimensions' => '', 'sort' => '' ) )->getResults();

        if ( ! $dims || empty( $rs->resultsRows ) ) {

            return \OWA\Module\Base\Classes\CompareMerger::merge( $rs, $totals, array(),
                array( $rsm->timePeriod->getStartDate()->getYyyymmdd(), $rsm->timePeriod->getEndDate()->getYyyymmdd() ),
                array( $compare['startDate'], $compare['endDate'] ), $compare['compare'] );
        }

        $crsm = $this->resultSetManager( $over );

        foreach ( \OWA\Module\Base\Classes\CompareMerger::restrictable( $dims ) as $dim ) {

            $values = array();

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

        $main = array( $rsm->timePeriod->getStartDate()->getYyyymmdd(), $rsm->timePeriod->getEndDate()->getYyyymmdd() );

        $crs = $crsm->getResults();
        $crs->aggregates = $totals->aggregates;

        return \OWA\Module\Base\Classes\CompareMerger::merge( $rs, $crs, $dims, $main,
            array( $compare['startDate'], $compare['endDate'] ), $compare['compare'] );
    }

    /**
     * The query this request asks for, set up and not yet run.
     *
     * @param array $overrides request parameters to use instead of the
     *                         request's own: how the comparison's query is
     *                         the same query over other dates
     * @return \OWA\Module\Base\Classes\ResultSetManager|false  false without metrics
     */
    public function resultSetManager( array $overrides = array() ) {

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

            foreach ( array( 'compare', 'compareStartDate', 'compareEndDate', 'rowValues' ) as $key ) {

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
            }, is_array( $values ) ? $values : array() ), 'strlen' ) ) );

            if ( ! $values || count( $values ) > self::ROW_VALUES_LIMIT ) {

                $rsm->addRequestError( sprintf( \OWA\Core\CoreAPI::t( 'rowValues needs 1 to %d values for %s.' ), self::ROW_VALUES_LIMIT, $dim ) );

                continue;
            }

            $rsm->setConstraint( (string) $dim, $values, 'in' );
        }
    }
}
