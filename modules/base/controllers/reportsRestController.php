<?php

require_once(OWA_DIR.'owa_reportController.php');
require_once(OWA_BASE_CLASS_DIR.'resultSetManager.php');

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
	
	function __construct($params) {
		
        parent::__construct($params);
        $this->setRequiredCapability('view_reports');
    }
	
	function validate() {
		
		// if no report name is specified do these validations necesary for generic resultSet.
		if ( ! $this->get('report_name') ) {
			
			// metrics are required for resultset queries		
			$this->addValidation( 'metrics', $this->getParam('metrics'), 'required', array('stopOnError'	=> true) );
			
			// make sure period string is valid
			if ( $this->get( 'period' ) ) {
				
				$period = owa_coreAPI::supportClassFactory('base', 'timePeriod');	
				$lables = array_keys($period->getPeriodLabels() );
				$this->addValidation('period', $this->getParam('period'), 'inArray', array('possible_values' => $lables, 'stopOnError' => true) );				
			}
		} else {
			
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
	
	function getReport( $report_name ) {
		
		$method = 'report_'.$report_name;
		
		return $this->$method();
	}
	
	/**
     * Generates a data result set using metrics and dimension
     *
     * @return paginatedResultSet obj
     */
    function getResultSet() {

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
		$rsm = new owa_resultSetManager;

        $s = owa_coreAPI::entityFactory('base.session');
        $h = owa_coreAPI::entityFactory('base.host');
        $l = owa_coreAPI::entityFactory('base.location_dim');
        $ua = owa_coreAPI::entityFactory('base.ua');
        $d = owa_coreAPI::entityFactory('base.document');
        $v = owa_coreAPI::entityFactory('base.visitor');
        $r = owa_coreAPI::entityFactory('base.referer');
        $sr = owa_coreAPI::entityFactory('base.source_dim');
        $st = owa_coreAPI::entityFactory('base.search_term_dim');

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
		$rsm = new owa_resultSetManager;

        $a = owa_coreAPI::entityFactory('base.action_fact');
        $d = owa_coreAPI::entityFactory('base.document');

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
		$rsm = new owa_resultSetManager;
		
        $rsm->db->selectFrom('owa_request', 'request');
        
        $rsm->db->selectColumn("*");
        
        $rsm->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_document', 'document', 'document_id', 'document.id');

        if ($this->get( 'sessionId' ) ) {
            $rsm->db->where('session_id', $this->get( 'sessionId' ) );
            $rsm->setQueryStringParam('sessionId', $this->get( 'sessionId' ) );
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

        $t = owa_coreAPI::entityFactory( 'base.commerce_transaction_fact' );
        $t->getbyColumn('order_id', $this->get( 'transactionId' ) );
        $trans_detail = array();

        $id = $t->get( 'id' );
        if ( $id ) {
            $trans_detail = $t->_getProperties();
            // fetch line items
            $db = owa_coreAPI::dbSingleton();

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
		$rsm = new owa_resultSetManager;
		
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
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_commerce_transaction_fact');
        $db->selectColumn("*");
        $db->where('order_id', $this->get('transactionId'));

        $transaction = $db->getOneRow();
        unset($db);
        
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_commerce_line_item_fact');
        $db->selectColumn("*");
        $db->where('order_id', $this->get('transactionId'));

        $transaction['line_items'] = $db->getAllRows();
        unset($db);
        
        return $transaction;
    }

function report_clicks() {
	
        // Fetch document object
        $d = owa_coreAPI::entityFactory('base.document');

        if ( ! $this->get('document_id') ) {

            $eq = owa_coreAPI::getEventDispatch();
            $document_id = $d->generateId( $eq->filter('page_url',  urldecode( $this->get('pageUrl') ), $this->get('siteId') ) ) ;
            
        } else {
	        
	        $document_id = $this->get('document_id');
        }

        $d->getByColumn('id', $document_id);

		// get resultSet Manager instance
		$rsm = new owa_resultSetManager;
		
        $rsm->db->selectFrom('owa_click');
        $rsm->db->selectColumn("click_x as x,
                            click_y as y,
                            page_width,
                            page_height,
                            dom_element_x,
                            dom_element_y,
                            position");


        $rsm->db->orderBy('click_y', 'ASC');
        
        $rsm->db->where('document_id', $document_id);
        // designate document_id a query param for result set urls
        $rsm->setQueryStringParam( 'document_id', $document_id );
        // designate report_name a query param for result set urls
        $rsm->setQueryStringParam( 'report_name', 'clicks' );
      	
		// set site id
		//$rsm->setSiteId( $this->get('siteId') );
		$rsm->db->where('site_id',  $this->get('siteId') );
		$rsm->setQueryStringParam('siteId', $this->get('siteId') );
     
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

	
}	

require_once(OWA_DIR.'owa_view.php');

class owa_reportsRestView extends owa_restApiView {
	
	function render() {
		
		$this->setResponseData( $this->get('response') );
	}
}

?>