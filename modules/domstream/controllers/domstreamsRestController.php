<?php

/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */

require_once(OWA_DIR.'owa_adminController.php');

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
		
		if ( $this->get( 'domstream_guid' ) ) {
            return $this->getDomstream( $this->get( 'domstream_guid' ) );
        }
		
        $rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_domstream');
        $db->selectColumn("domstream_guid, max(timestamp) as timestamp, page_url, duration, id as domstream_id, page_height, page_width");
        //$db->selectColumn('id');
        $db->selectColumn('document_id');
        $db->groupby('domstream_guid');
        //$db->selectColumn('events');
        $db->where('yyyymmdd', array('start' => $this->get('startDate'), 'end' => $this->get('endDate')), 'BETWEEN');
        if ($this->get('document_id')) {
            $db->where('document_id', $this->get('document_id'));
        }

        if ( $this->get( 'siteId' ) ) {
            
            $db->where( 'site_id', $this->get('siteId') );
        }

        $db->orderBy('timestamp', 'DESC');
		
		$resultsPerPage = $this->get('resultsPerPage') ?: 50;
        // pass limit to rs object if one exists
        $rs->setLimit($resultsPerPage);
		
		$page = $this->get('page') ?: 1;
        // pass page to rs object if one exists
        $rs->setPage($page);

        $results = $rs->generate($db);

        $rs->setLabels(array('id' => 'Domstream ID', 'page_url' => 'Page Url', 'duration' => 'Duration', 'timestamp' => 'Timestamp'));
		$rs->resultsRows = $results;
        
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