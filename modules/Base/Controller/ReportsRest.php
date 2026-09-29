<?php
namespace OWA\Module\Base\Controller;



/**
 * Report REST Controller.
 *
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
		 * Reporting not ready for this Profile: its Property has no cube yet.
		 * Answered before any result set is built, so nothing queries a table
		 * that does not exist, and with the reason rather than empty rows -- a
		 * client reading rows alone could not tell "not built yet" from zero.
		 */
		$readiness = $this->get( 'siteId' )
			? \OWA\Module\Base\Classes\Cube\Status::readiness( $this->get( 'siteId' ) ) : null;

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
	
	/**
     * Generates a data result set using metrics and dimension
     *
     * @return paginatedResultSet obj
     */
    function getResultSet() {

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        if ( $this->getParam('metrics') ) {
	        
            $rsm->metrics = $rsm->metricsStringToArray( $this->getParam('metrics') );
            
        } else {
	        
            return false;
        }

        // set dimensions
        if ( $this->getParam('dimensions') ) {
	        
            $rsm->setDimensions($rsm->dimensionsStringToArray( $this->getParam('dimensions') ));
        }

        if ( $this->getParam('segment') ) {
	        
            $rsm->setSegment( $this->getParam('segment') );
        }

        // set period
        if ( ! $this->getParam('period') ) {
	        
            $this->setParam('period', 'today');
        }

        $rsm->setTimePeriod(
        	$this->get( 'period' ),
            $this->get( 'startDate' ),
            $this->get( 'endDate' ),
            $this->get( 'startTime' ),
            $this->get( 'endTime' )
        );

        // set constraints
        if ( $this->get( 'constraints' ) ) {

            $rsm->setConstraints( $rsm->constraintsStringToArray( $this->get( 'constraints' ) ) );
        }

        //site_id
        if ( $this->get('siteId') ) {
	
            $rsm->setSiteId( $this->get('siteId' ) );
        }

        // set sort order
        if ( $this->get('sort') ) {
            
            $rsm->setSorts($rsm->sortStringToArray( $this->get('sort') ) );
        }

        // set limit
        if ( $this->get('resultsPerPage') ) {
            
            $rsm->setLimit( $this->get('resultsPerPage') );
        }

        // set page
        if ( $this->get('page') ) {
            
            $rsm->setPage( $this->get('page') );
        }

        // set offset
        if ( $this->get('offset') ) {
            
            $rsm->setOffset( $this->get('offset') );
        }

        // get results
        return  $rsm->getResults();
    }
}
