<?php

/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */

require_once(OWA_DIR.'owa_adminController.php');
require_once(OWA_BASE_CLASS_DIR.'resultSetManager.php');

/**
 * Domstreams Controller
 *
 * Lists available domstreams for a document
 * 
 */
class owa_domstreamsRestController extends owa_adminController {
	
	function __construct($params) {
		
        parent::__construct($params);
        $this->setRequiredCapability('view_reports');
    }

	
	function validate() {
		
		$this->addValidation('siteId', $this->getParam('siteId'), 'required', array('stopOnError'	=> true));
	    //$this->addValidation('document_id', $this->getParam('document_id'), 'required', array('stopOnError'	=> true));
	}
	
    function action() {
		
		
		// this should really be broken out into its own REST endpoint and not bundled here as it's an entirely
		// different database query
		if ( $this->get( 'domstream_guid' ) ) {
			
            return $this->getDomstream( $this->get( 'domstream_guid' ) );
        }
		 
        // get resultSet Manager instance
		$rsm = new owa_resultSetManager;
 
        $rsm->db->selectFrom('owa_domstream');
       
        $rsm->db->selectColumn("domstream_guid, max(timestamp) as timestamp, page_url, duration, id as domstream_id, page_height, page_width");
      
        $rsm->db->selectColumn('document_id');
       
        $rsm->db->groupby('domstream_guid');        
        
        // get domstreams for a particular document/page
        if ($this->get('document_id')) {
	        
            $rsm->db->where('document_id', $this->get('document_id'));
            $rsm->setQueryStringParam('document_id', $document_id);
        }
		
		$rsm->db->orderBy('timestamp', 'DESC');
        
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
        $resultsPerPage = $this->get( 'resultsPerPage' ) ?: 50;    
        $rsm->setLimit( $resultsPerPage );
		
		// set pagination
        $page = $this->get( 'page' ) ?: 1;
        $rsm->setPage( $this->get('page') );
		
		// fetch results
		$rs = $rsm->queryResults();
		
        $rs->setLabels(array('id' => 'Domstream ID', 'page_url' => 'Page Url', 'duration' => 'Duration', 'timestamp' => 'Timestamp'));
	
        
        $this->set('response', $rs);
        
    }
    
    function success() {
	    
	    http_response_code(201);
	    
	    $this->setView( 'domstream.domstreamsRest' );
    }
    
    function errorAction() {
	    
	    http_response_code(422);
	    
	    $this->setView( 'domstream.domstreamsRest' );
    }
    
    // api method callback gets an individual domstream
    function getDomstream( $domstream_guid ) {

       
        // Fetch document object
        $d = owa_coreAPI::entityFactory('base.domstream');

        $db = owa_coreAPI::dbSingleton();
        $db->select('*');
        $db->from( $d->getTableName() );
        $db->where( 'domstream_guid', $domstream_guid );
        $db->orderBy('timestamp', 'ASC');
        $ret = $db->getAllRows();
        //print_r($ret);
        $combined = '';

        if ( $ret ) {
            // if rows then combine the events
            foreach ($ret as $row) {
                $combined = $this->mergeStreamEvents( htmlspecialchars_decode( $row['events'] ), $combined );
            }

            $row['events'] = json_decode( $combined  );
        } else {
            // no rows found for some reason?..
            $error = 'No domstream rows found for domstream_guid: ' . $domstream_guid;
            owa_coreAPI::debug( $error );
        }

        $this->set('response', $row);
    }
    
    function mergeStreamEvents($new, $old = '') {

        if ( $old) {
            $old = json_decode($old);
        } else {
            $old = array();
        }
        //owa_coreAPI::debug('old: '.print_r($old, true));
        $new = json_decode($new);
        //owa_coreAPI::debug('new: '.print_r($new, true));

        foreach ($new as $v) {
            $old[] = $v;
        }
        
        $combined = $old;
        //owa_coreAPI::debug('combined: '.print_r($combined, true));
        //owa_coreAPI::debug('combined count: '.count($combined));
        $combined = json_encode($combined);
        return $combined;
    }

}


require_once(OWA_DIR.'owa_view.php');

/**
 * View
 * 
 */
class owa_domstreamsRestView extends owa_restApiView {
        
    function render() {
        
        $this->setResponseData( $this->get('response') );
    }
}

?>