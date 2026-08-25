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

			// report_name selects a report_<name>() method by concatenation, so
			// it has to name a report this controller actually implements. The
			// switch below only adds each report's own required params; it never
			// rejected an unrecognised name, which then reached the dispatch.
			$this->addValidation(
				'report_name',
				$this->get('report_name'),
				'inArray',
				array( 'possible_values' => self::getReportNames(), 'stopOnError' => true )
			);

			switch( $this->get('report_name') ) {

				case 'visit':
				case 'clickstream':
				
					$this->addValidation('sessionId', $this->getParam('sessionId'), 'required', [] );
					break;
			
			
				case 'latest_visits':
					
					//$this->addValidation('siteId', $this->getParam('siteId'), 'required', [] );
					break;
					
				case 'latest_actions':
			
					$this->addValidation('startDate', $this->getParam('startDate'), 'required', [] );
					$this->addValidation('endDate', $this->getParam('endDate'), 'required', [] );
					$this->addValidation('siteId', $this->getParam('siteId'), 'required', [] );
					break;
					
				case 'transaction_items':
					$this->addValidation('transactionId', $this->getParam('transactionId'), 'required', [] );

					break;
					
				case 'transactions':
				
					$this->addValidation('siteId', $this->getParam('siteId'), 'required', [] );

					break;
				
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
	
	/**
	 * The reports this controller can serve.
	 *
	 * Derived from the report_<name>() methods it defines rather than listed by
	 * hand, so the set cannot drift out of step with the implementations.
	 *
	 * @return array
	 */
	public static function getReportNames() {

		$names = array();

		$class = new \ReflectionClass( self::class );

		foreach ( $class->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {

			if ( strpos( $method->name, 'report_' ) === 0 ) {

				$names[] = substr( $method->name, 7 );
			}
		}

		sort( $names );

		return $names;
	}

	function getReport( $report_name ) {


		// validate() rejects an unknown report before the action runs, so this is
		// a backstop: the name is concatenated into a method call, and an
		// unhandled one used to be a fatal rather than a response.
		// The type is checked BEFORE the method name is built -- concatenating
		// first raises a warning for anything that is not a string, which is one
		// of the shapes this exists to turn away quietly.
		if ( ! is_string( $report_name ) ) {

			\OWA\Core\CoreAPI::notice( 'Refusing to run report: name is not a string.' );

			return '';
		}

		$method = 'report_'.$report_name;

		if ( ! method_exists( $this, $method ) ) {

			\OWA\Core\CoreAPI::notice(
				sprintf( 'Refusing to run report: %s is not a known report.', print_r( $report_name, true ) )
			);

			return '';
		}

		return $this->$method();
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

	function report_visit() {

		return $this->report_latest_visits();
    }
    
    function report_latest_visits() {

        // get resultSet Manager instance
		$rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $s = \OWA\Core\CoreAPI::entityFactory('base.session');
        $h = \OWA\Core\CoreAPI::entityFactory('base.host');
        $l = \OWA\Core\CoreAPI::entityFactory('base.location_dim');
        $ua = \OWA\Core\CoreAPI::entityFactory('base.ua');
        $d = \OWA\Core\CoreAPI::entityFactory('base.document');
        $v = \OWA\Core\CoreAPI::entityFactory('base.visitor');
        $r = \OWA\Core\CoreAPI::entityFactory('base.referer');
        $sr = \OWA\Core\CoreAPI::entityFactory('base.source_dim');
        $st = \OWA\Core\CoreAPI::entityFactory('base.search_term_dim');

        $rsm->db->selectFrom($s->getTableName(), 'session');

        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $l->getTableName(), 'location', 'location_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), 'host', 'host_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), 'ua', 'ua_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'first_page_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), 'visitor', 'visitor_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), 'referer', 'referer_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $sr->getTableName(), 'source', 'source_id');
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $st->getTableName(), 'search_term', 'referring_search_term_id');

        $rsm->db->selectColumn('session.timestamp as session_timestamp, session.is_new_visitor as session_is_new_visitor, session.num_prior_sessions as session_num_prior_visits, session.num_pageviews as session_num_pageviews, session.last_req as session_last_req, session.id as session_id, session.user_name as session_user_name, session.site_id as site_id, session.visitor_id as visitor_id, session.medium as medium, session.ip_address as ip_address');

        $rsm->db->selectColumn('host.host as host_host');
        $rsm->db->selectColumn('location.city as location_city, location.country as location_country');
        $rsm->db->selectColumn('ua.browser_type as browser_type, ua.ua as browser_user_agent');
        $rsm->db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');
        $rsm->db->selectColumn('visitor.user_email as visitor_user_email');
        $rsm->db->selectColumn('source.source_domain as source');
        $rsm->db->selectColumn('referer.url as referer_url, referer.page_title as referer_page_title, referer.snippet as referer_snippet');
        $rsm->db->selectColumn('search_term.terms as search_term');

        if ( $this->get('visitorId') ) {
            $rsm->db->where('visitor_id', $this->get('visitorId'));
            $rsm->setQueryStringParam('visitorId', $this->get( 'visitorId' ) );
        }
        
        if ( $this->get( 'sessionId' ) ) {
	        
	        $rsm->db->where( 'session.id', $this->get( 'sessionId' ) );
	        $rsm->setQueryStringParam( 'sessionId', $this->get( 'sessionId' ) );
        }

        if ( $this->get('siteId') ) {
	        
            //$rsm->setSiteId( $this->get('siteId') );
			$rsm->db->where('site_id',  $this->get('siteId') );
			$rsm->setQueryStringParam('siteId', $this->get('siteId') );
        }
        
		// set time period
        $rsm->setTimePeriod(
        	$this->get( 'period' ),
            $this->get('startDate'),
            $this->get('endDate'),
            $this->get('startTime'),
            $this->get('endTime')
        );

        $rsm->db->orderBy('timestamp', 'DESC');

        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 20;
        $rsm->setLimit( $resultsPerPage );
		
		// set pagination
        $page = $this->get( 'page' ) ?: 1;
        $rsm->setPage( $this->get('page') );
		
		// fetch results
		$rs = $rsm->queryResults();

        return $rs;
    }

	function report_latest_actions() {

        // get resultSet Manager instance
		$rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $a = \OWA\Core\CoreAPI::entityFactory('base.action_fact');
        $d = \OWA\Core\CoreAPI::entityFactory('base.document');

        $rsm->db->selectFrom($a->getTableName(), 'action');

        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'document_id');


        $rsm->db->selectColumn('action.timestamp, action.action_name, action.action_label, action.action_group, action.numeric_value');
        $rsm->db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');

        if ( $this->get( 'visitorId' ) ) {
            $rsm->db->where('action.visitor_id', $this->get( 'visitorId' ) );
            $rsm->setQueryStringParam('visitorId', $this->get( 'visitorId' ) );
        }

        if ( $this->get( 'sessionId' ) ) {
            $rsm->db->where('action.session_id', $this->get( 'sessionId' ) );
            $rsm->setQueryStringParam('sessionId', $this->get( 'sessionId' ) );
        }
		
		$rsm->db->orderBy('action.timestamp', 'DESC');
		
        // set site id
		$rsm->db->where('site_id', $this->get('siteId') );
		$rsm->setQueryStringParam( 'siteId', $this->get('siteId') );
		
        // set time period
        $rsm->setTimePeriod(
        	$this->get( 'period' ),
            $this->get('startDate'),
            $this->get('endDate'),
            $this->get('startTime'),
            $this->get('endTime')
        );
        
		// set limit
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 100;
        $rsm->setLimit( $resultsPerPage );
		
		// set pagination
        $page = $this->get( 'page' ) ?: 1;
        $rsm->setPage( $this->get('page') );
		
		// fetch results
		$rs = $rsm->queryResults();

        return $rs;
    }
    
    function report_clickstream() {

        // get resultSet Manager instance
		$rsm = new \OWA\Module\Base\Classes\ResultSetManager;
		
        $rsm->db->selectFrom('owa_request', 'request');
        
        $rsm->db->selectColumn("*");
        
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_document', 'document', 'document_id', 'document.id');

        // Narrow to the days the session can have run, so a partitioned
        // owa_request is pruned rather than every partition being visited and
        // then sorted. There is no date on this request to use, so the range
        // comes from the timestamp the session id carries -- which the tracker
        // mints from the browser's clock, making it a hint only. Hence the
        // retry below.
        $range = null;

        if ($this->get( 'sessionId' ) ) {
            $rsm->db->where('session_id', $this->get( 'sessionId' ) );
            $rsm->setQueryStringParam('sessionId', $this->get( 'sessionId' ) );

            $range = \OWA\Core\Db::factDateRangeFromId( $this->get( 'sessionId' ) );

            if ( $range ) {
                $rsm->db->where('yyyymmdd', $range, 'between');
            }
        }

        $rsm->db->orderBy('timestamp','DESC');

        // set limit
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 100;
        $rsm->setLimit( $resultsPerPage );
		
		// set pagination
        $page = $this->get( 'page' ) ?: 1;
        $rsm->setPage( $this->get('page') );
		
		// fetch results
		$rs = $rsm->queryResults();

        // A clock set to the wrong decade puts the session outside the window,
        // and the visit would come back empty rather than merely slow. Repeat
        // without it. First page only: further in, no rows is a real answer.
        if ( $range && $page <= 1 && empty( $rs->resultsRows ) ) {

            $retry = new \OWA\Module\Base\Classes\ResultSetManager;

            $retry->db->selectFrom('owa_request', 'request');
            $retry->db->selectColumn("*");
            $retry->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_document', 'document', 'document_id', 'document.id');
            $retry->db->where('session_id', $this->get( 'sessionId' ) );
            $retry->db->orderBy('timestamp','DESC');
            $retry->setQueryStringParam('sessionId', $this->get( 'sessionId' ) );
            $retry->setLimit( $resultsPerPage );
            $retry->setPage( $this->get('page') );

            $rs = $retry->queryResults();
        }

        return $rs;
    }
    
    /**
     * Retrieves full detail of an ecommerce transaction
     *
     * @param    $transactionId    string the id of the transaction you want
     * @param    $format            string the format you want returned
     * @return
     */
    function report_transaction_items() {

        $t = \OWA\Core\CoreAPI::entityFactory( 'base.commerce_transaction_fact' );
        $t->getbyColumn('order_id', $this->get( 'transactionId' ) );
        $trans_detail = array();

        $id = $t->get( 'id' );
        if ( $id ) {
            $trans_detail = $t->_getProperties();
            // fetch line items
            $db = \OWA\Core\CoreAPI::dbSingleton();

            $db->selectFrom( 'owa_commerce_line_item_fact' );
            $db->selectColumn( '*' );
            $db->where( 'order_id', $this->get( 'transactionId' ) );
            $lis = $db->getAllRows();
            $trans_detail['line_items'] = $lis;
        }

        return $trans_detail;
    }

	function report_transactions() {
		
		// get resultSet Manager instance
		$rsm = new \OWA\Module\Base\Classes\ResultSetManager;
		
		$sort = $this->get('sort') ?: 'desc';
	
        //$db = owa_coreAPI::dbSingleton();
        $rsm->db->selectFrom('owa_commerce_transaction_fact');
        $rsm->db->selectColumn("*");
        $rsm->db->orderBy('timestamp', $sort);
        
        //$rsm->setSiteId( $this->get( 'siteId' ) );
        $rsm->db->where('site_id',  $this->get('siteId') );
		$rsm->setQueryStringParam( 'siteId', $this->get('siteId') );

       // set time period
        $rsm->setTimePeriod(
        	$this->get( 'period' ),
            $this->get('startDate'),
            $this->get('endDate'),
            $this->get('startTime'),
            $this->get('endTime')
        );
        
		// set limit
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 25;
        $rsm->setLimit( $resultsPerPage );
		
		// set pagination
        $page = $this->get( 'page' ) ?: 1;
        $rsm->setPage( $this->get('page') );
		
		// fetch results
		$rs = $rsm->queryResults();
		
        return $rs;
    }

    function report_transaction()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_commerce_transaction_fact');
        $db->selectColumn("*");
        $db->where('order_id', $this->get('transactionId'));

        $transaction = $db->getOneRow();
        unset($db);
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_commerce_line_item_fact');
        $db->selectColumn("*");
        $db->where('order_id', $this->get('transactionId'));

        $transaction['line_items'] = $db->getAllRows();
        unset($db);
        
        return $transaction;
    }

    /*
     * report_clicks is GONE.
     *
     * A heatmap is now an ordinary dimensional query:
     *
     *   metrics=domClicks&dimensions=clickX,clickY&constraints=pagePath==/x
     *
     * which the resolver joins click->document on its own, because pagePath is
     * registered against document_id. That deletes ~80 lines of hand-built SQL,
     * a second pageUrl-to-document_id resolution that had to re-implement the
     * ingestion canonicalisation to stay correct, and the bespoke overlay token
     * resource key it needed.
     *
     * It also fixes what that query could not express: identical coordinates
     * now GROUP, so one page's 345,620 clicks arrive as distinct points with a
     * count instead of 345,620 rows paged through a few hundred at a time.
     */

	
}	
