<?php

require_once(OWA_DIR.'owa_reportController.php');

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
class owa_reportsRestController extends owa_reportController {
	
	function validate() {
		
		// if no report name is specified
		
		if ( ! $this->get('report_name') ) {
			
			// metrics are required for resultset queries		
			$this->addValidation( 'metrics', $this->getParam('metrics'), 'required', array('stopOnError'	=> true) );
			
			// make sure period string is valid
			if ( $this->get( 'period' ) ) {
				
				$period = owa_coreAPI::supportClassFactory('base', 'timePeriod');	
				$lables = array_keys($period->getPeriodLabels() );
				$this->addValidation('period', $this->getParam('period'), 'inArray', array('possible_values' => $lables, 'stopOnError' => true) );				
			}
		}
	}
	
	function action() {
		
		$results = '';
		
		if ( $this->getParam('report_name') ) {
			
			$results = $this->getReport( $this->getParam('report_name') );		
		
		} else {
			
			$results = $this->getResultSet();
		}
		
		$this->set('response', $results );
		
	}
	
	function success() {
		
		http_response_code(201);
		
		$this->setView( 'base.reportsRest' );
	}
	
	function errorAction() {
		
		http_response_code(422);
		
		$this->setView( 'base.restApi' );

	}
	
	function getReport( $report_name ) {
		
		$method = 'get_'.$report_name;
		
		return $this->$method();
	}
	
	/**
     * Generates a data result set
     *
     * @return paginatedResultSet obj
     */
    function getResultSet() {

        //print_r(func_get_args());
        // create the metric obj for the first metric
        require_once(OWA_BASE_CLASS_DIR.'resultSetManager.php');
        $rsm = new owa_resultSetManager;

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
            $this->get('startDate'),
            $this->get('endDate'),
            $this->get('startTime'),
            $this->get('endTime')
        );

        // set constraints
        if ( $this->get( 'constraints' ) ) {

            $rsm->setConstraints( $rsm->constraintsStringToArray( $this->get( 'constraints' ) ) );
        }

        //site_id
        if ( $this->get('siteId') ) {
            $rsm->setSiteId( $this->get(' siteId' ) );
        }

        // set sort order
        if ( $this->get('sort') ) {
            $rsm->setSorts($rsm->sortStringToArray( $this->get('sort') ) );
        }

        // set limit
        if ( $this->get('resultsPerPage') ) {
            $rsm->setLimit( $this->get('resultsPerPage') );
        }

        // set limit  (alt key)
        //if ($resultsPerPage) {
        //    $rsm->setLimit($resultsPerPage);
        //}

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

require_once(OWA_DIR.'owa_view.php');

class owa_reportsRestView extends owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('response') );
	}
}

?>