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

	function report_visit() {

		return $this->report_latest_visits();
    }
    
    function report_latest_visits() {

        $rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
        $db = owa_coreAPI::dbSingleton();

        $s = owa_coreAPI::entityFactory('base.session');
        $h = owa_coreAPI::entityFactory('base.host');
        $l = owa_coreAPI::entityFactory('base.location_dim');
        $ua = owa_coreAPI::entityFactory('base.ua');
        $d = owa_coreAPI::entityFactory('base.document');
        $v = owa_coreAPI::entityFactory('base.visitor');
        $r = owa_coreAPI::entityFactory('base.referer');
        $sr = owa_coreAPI::entityFactory('base.source_dim');
        $st = owa_coreAPI::entityFactory('base.search_term_dim');

        $db->selectFrom($s->getTableName(), 'session');

        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $l->getTableName(), 'location', 'location_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), 'host', 'host_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), 'ua', 'ua_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'first_page_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), 'visitor', 'visitor_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), 'referer', 'referer_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $sr->getTableName(), 'source', 'source_id');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $st->getTableName(), 'search_term', 'referring_search_term_id');

        $db->selectColumn('session.timestamp as session_timestamp, session.is_new_visitor as session_is_new_visitor, session.num_prior_sessions as session_num_prior_visits, session.num_pageviews as session_num_pageviews, session.last_req as session_last_req, session.id as session_id, session.user_name as session_user_name, session.site_id as site_id, session.visitor_id as visitor_id, session.medium as medium, session.ip_address as ip_address');

        $db->selectColumn('host.host as host_host');
        $db->selectColumn('location.city as location_city, location.country as location_country');
        $db->selectColumn('ua.browser_type as browser_type, ua.ua as browser_user_agent');
        $db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');
        $db->selectColumn('visitor.user_email as visitor_user_email');
        $db->selectColumn('source.source_domain as source');
        $db->selectColumn('referer.url as referer_url, referer.page_title as referer_page_title, referer.snippet as referer_snippet');
        $db->selectColumn('search_term.terms as search_term');

        if ( $this->get('visitorId') ) {
            $db->where('visitor_id', $this->get('visitorId'));
        }
        
        if ( $this->get( 'sessionId' ) ) {
	        
	        $db->where( 'session.id', $this->get( 'sessionId' ) );
        }

        if ( $this->get('siteId') ) {
            $db->where('site_id', $this->get('siteId'));
        }

        if ( $this->get('startDate') && $this->get('endDate') ) {
            $db->where('session.yyyymmdd', array('start' => $this->get('startDate'), 'end' => $this->get('endDate') ), 'BETWEEN');
        }

        $db->orderBy('timestamp', 'DESC');

         // pass limit to rs object if one exists
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 20; 
        $rs->setLimit( $resultsPerPage );
		$page = $this->get( 'page' ) ?: 1;
        // pass page to rs object if one exists
        $rs->setPage( $page );

        $results = $rs->generate($db);
        $rs->resultsRows = $results;

        return $rs;
    }

	function report_latest_actions() {

        $rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
        $db = owa_coreAPI::dbSingleton();

        $a = owa_coreAPI::entityFactory('base.action_fact');
        $d = owa_coreAPI::entityFactory('base.document');

        $db->selectFrom($a->getTableName(), 'action');

        $db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'document_id');


        $db->selectColumn('action.timestamp, action.action_name, action.action_label, action.action_group, action.numeric_value');
        $db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');

        if ( $this->get( 'visitorId' ) ) {
            $db->where('action.visitor_id', $this->get( 'visitorId' ) );
        }

        if ( $this->get( 'sessionId' ) ) {
            $db->where('action.session_id', $this->get( 'sessionId' ) );
        }

        if ( $this->get( 'siteId' ) ) {
            $db->where('site_id', $this->get( 'siteId' ) );
        }

        if ( $this->get( 'startDate' ) && $this->get( 'endDate' ) ) {
            $db->where('action.yyyymmdd', array('start' => $this->get( 'startDate' ), 'end' => $this->get( 'endDate' ) ), 'BETWEEN');
        }

        $db->orderBy('action.timestamp', 'DESC');

        // pass limit to rs object if one exists
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 20; 
        $rs->setLimit( $resultsPerPage );
		$page = $this->get( 'page' ) ?: 1;
        // pass page to rs object if one exists
        $rs->setPage( $page );

        $results = $rs->generate($db);
        $rs->resultsRows = $results;

        return $rs;

    }
    
    function report_clickstream() {

        $rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_request', 'request');
        $db->selectColumn("*");
        // pass constraints into where clause
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_document', 'document', 'document_id', 'document.id');

        if ($this->get( 'sessionId' ) ) {
            $db->where('session_id', $this->get( 'sessionId' ) );
        }

        $db->orderBy('timestamp','DESC');

        // pass limit to rs object if one exists
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 100; 
        $rs->setLimit( $resultsPerPage );
		$page = $this->get( 'page' ) ?: 1;
        // pass page to rs object if one exists
        $rs->setPage( $page );

        $results = $rs->generate($db);
        $rs->resultsRows = $results;

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
		
		$sort = $this->get('sort') ?: 'desc';
		$resultsPerPage = $this->get( 'resultsPerPage' ) ?: 25;
		$page = $this->get( 'page' ) ?: 1;
		 
		
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_commerce_transaction_fact');
        $db->selectColumn("*");
        $db->orderBy('timestamp', $sort);
        $db->where( 'site_id', $this->get( 'siteId' ) );

        if ( $period ) {

            $p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
            $p->set( $this->get( 'period' ) );
            $startDate = $p->startDate->get('yyyymmdd');
            $endDate = $p->endDate->get('yyyymmdd');
            
        } else {
	        
	        $startDate = $this->get( 'startDate' );
            $endDate = $this->get( 'endDate' );
        }

        if ( $startDate && $endDate ) {
            $db->where('yyyymmdd', array('start' => $startDate, 'end' => $endDate), 'BETWEEN');
        }

        $rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');

        // pass limit to rs object if one exists
        $rs->setLimit($resultsPerPage);

        // pass page to rs object if one exists
        $rs->setPage($page);

        $results = $rs->generate($db);

        return $rs;
    }

function report_clicks() {
		
		$resultsPerPage = $this->get( 'resultsPerPage' ) ?: 100;
		$page = $this->get( 'page' ) ?: 1;
		
        // Fetch document object
        $d = owa_coreAPI::entityFactory('base.document');

        if ( ! $this->get('document_id') ) {

            $eq = owa_coreAPI::getEventDispatch();
            $document_id = $d->generateId( $eq->filter('page_url',  urldecode( $this->get('pageUrl') ), $this->get('siteId') ) ) ;
            
        } else {
	        
	        $document_id = $this->get('document_id');
        }

        $d->getByColumn('id', $document_id);


        $rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_click');
        $db->selectColumn("click_x as x,
                            click_y as y,
                            page_width,
                            page_height,
                            dom_element_x,
                            dom_element_y,
                            position");


        $db->orderBy('click_y', 'ASC');
        $db->where('document_id', $document_id);
        $db->where('site_id', $this->get('siteId'));


        if ( $this->get('period') ) {

            $p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
            $p->set($this->get('period'));
            $startDate = $p->startDate->get('yyyymmdd');
            $endDate = $p->endDate->get('yyyymmdd');
        }

        if ( $this->get('startDate') && $this->get('endDate') ) {
            
            $startDate = $this->get('startDate');
            $endDate = $this->get('endDate');
        }
		
		$db->where('yyyymmdd', array( 'start' => $startDate, 'end' => $endDate ), 'BETWEEN');
		
        // pass limit to rs object if one exists
        $rs->setLimit($resultsPerPage);

        // pass page to rs object if one exists
        $rs->setPage($page);

        $results = $rs->generate($db);

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