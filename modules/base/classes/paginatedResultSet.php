<?php

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
// $Id$
//

/**
 * Pagination
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_paginatedResultSet {

    /**
     * Unique hash of result set used by front end
     * to see if there are any changes.
     */
    var $guid;
	
	/**
     * Time period of the results
     * @object
     */
    var $timePeriod;
    
    var $resultsPerPage = 25;
    
    /**
     * The total number of result rows available
     * in the database
     */
    var $resultsTotal = 0;
    
    /**
     * The total number of result rows
     * contained in this result set
     */
    var $resultsReturned;
    
    var $sortColumn;
    
    var $sortOrder;

    /**
     * Aggregate values for metrics
     */
    var $aggregates = [];
	
	/**
     * Data set rows
     */
	var $resultsRows = [];
	
	/**
     * Labels for metrics and dimensions
     */
    var $labels;
	
	/**
     * Convienence flag set when there are 
     * additional pages of results 
     */
    var $more;
    
    var $page = 1;
    
    /**
     * Total number of pages of results available
     */
    var $total_pages;

    /**
     * The API URL that produces the results
     */
    var $self;

    /**
     * The API URL that produces the next page of results
     */
    var $next;

    /**
     * The API URL that produces the previous page of results
     */
    var $previous;

    /**
     * The base API URL that is used to construct client side pagination links.
     * Does not contain any 'page' params.
     */
    var $base_url;

    /**
     * The list of related dimensions that can be added to the result set
     *
     */
    var $relatedDimensions = [];

    /**
     * The list of related metrics that can be added to the result set
     *
     */
    var $relatedMetrics = [];
    
    /**
     * The list of params that make up the query
     *
     */ 
    var $queryParams;
    
    var $errors;


    function __construct() {

    }

    function setLimit($limit) {

        $this->resultsPerPage = $limit;
    }

    function setPage($page) {

        $this->page = $page;
    }

    function setMorePages() {

        $this->more = true;
    }

    function calculateOffset() {

        return $this->resultsPerPage * ($this->page - 1);
    }

    function countResults( $results = [] ) {
		
		$results = $results ?: [];
		
        $this->resultsTotal = count( $results );

        if ($this->resultsPerPage) {
	        
            $this->total_pages = ceil( ( $this->resultsTotal + $this->calculateOffset() ) / $this->resultsPerPage );
		
            if ( $this->resultsTotal <= $this->resultsPerPage ) {
            // no more pages
            } else {
                // more pages
                $this->setMorePages();

            }
        }
    }

    function getRowCount() {

        return $this->resultsTotal;
    }
    
    function setQueryParams( $params ) {
	    
	    $this->queryParams = $params;
    }

    function generate( $results, $query_params, $options = []) {
		
		$defaults = [
			
			'resultsPerPage'	=> 10,
			'page'				=> 1
		];
		
        if ( ! empty( $results ) ) {
	        
	        $options = owa_lib::setDefaultParams( $defaults, $options );
			
			$this->setPage( $options['page'] );
		
			$this->setLimit( $options['resultsPerPage'] );
        
            $this->countResults( $results );

            if ( $options['resultsPerPage'] ) {
        
                $this->resultsRows = array_slice($results, 0, $options['resultsPerPage'], true);
                
            } else {
        
                $this->resultsRows = $results;
            }

            $this->resultsReturned = count( $this->resultsRows );
        } 
        
        // add REST request urls
        $this->setResultSetUrls( $query_params );
        
        // geenrated a unique hash of the results
		$this->createResultSetHash();

        return $this->resultsRows;
    }
    
    /**
	 * Constructs REST API request urls for the result set 
	 * (base, self, next and previous, etc.)
	 */
    function setResultSetUrls( $query_params ) {
		
		//owa_coreAPI::debug('result set urls query params: ' . $query_params);
		
        $urls = [];
        
        // base url
		$api_url = owa_coreAPI::getSetting('base', 'rest_api_url'); 
		$apiKey = owa_coreAPI::getCurrentUserApiKey();
		$this->base_url = $api_url;
		
		// add query params
		$query_params['do'] 		= 'reports';
		$query_params['module'] 	= 'base';
		$query_params['version'] 	= 'v1';
		$query_params['apiKey']	= $apiKey;
		
        // add current page if any
        if ( $this->page ) {
	        
            $query_params['page'] = $this->page;
        }
        
        // add limit
        if ($this->resultsPerPage) {
            $query_params['resultsPerPage'] = $this->resultsPerPage;
        }

        // build url for this result set
        $link_template = owa_coreAPI::getSetting('base', 'link_template');
        
        $q = $this->buildQueryString($query_params);
        
        $urls['self'] = sprintf($link_template, $api_url, $q);
        $urls['self'] = owa_coreAPI::signRequestUrl( $urls['self'], $apiKey );
        
        $this->self = $urls['self'];

		// build url for next page of result set
        if ( $this->more ) {
	        
	        $next_query_params = $query_params;
	        
	        if ($this->page) {
		        
	            $next_query_params['page'] = $query_params['page'] + 1;
	            
	        } else {
		        
	            $next_query_params['page'] = 2;
	        }
	
	        $nq = $this->buildQueryString($next_query_params);
	        
	        $urls['next'] = sprintf($link_template, $api_url, $nq);
	        $urls['next'] = owa_coreAPI::signRequestUrl( $urls['next'], $apiKey );

            $this->next = $urls['next'];
        }
		
		// build previous url if page is greater than 2
        if ( $this->page >=2 ) {
	        
	        $previous_query_params = $query_params;
            
            $previous_query_params['page'] = $query_params['page'] - 1;
            
            $pq = $this->buildQueryString($previous_query_params);
            
            $urls['previous'] = sprintf($link_template, $api_url, $pq);
            $urls['previous'] = owa_coreAPI::signRequestUrl( $urls['previous'], $apiKey );
            
            $this->previous = $urls['previous'];
        }
        
        // add query params array to result set
        $this->setQueryParams( $query_params );
    }
    
    function buildQueryString($params, $seperator = '&') {

        $new = array();
        //get namespace
        $ns = owa_coreAPI::getSetting('base', 'ns');
        foreach ($params as $k => $v) {

            $new[$ns.$k] = $v;
        }

        return http_build_query($new,'', $seperator);
    }
    
    function getResultSetAsArray() {

        $set = array();

        $set['labels'] = $this->labels;
        $set['resultsRows'] = $this->resultsRows;
        $set['count'] = $this->resultsTotal;
        $set['page'] = $this->page;
        $set['total_pages'] = $this->total_pages;
        $set['more'] = $this->more;
        $set['period'] = $this->getPeriodInfo();
        return $set;
    }

    function setLabels($labels) {

        $this->labels = $labels;
    }

    function displayPagination() {


    }

    function getPeriodInfo() {
        return $this->periodInfo;
    }

    function setPeriodInfo($info) {
        $this->timePeriod = $info;
    }

    function getLabel($key) {

        if (array_key_exists($key, $this->labels)) {
            return $this->labels[$key];
        }
    }

    function getAllLabels() {

        return $this->labels;
    }


    function formatResults( $format ) {

        $formats = array('html' => 'resultSetToHtml',
                         'json'    =>    'resultSetToJson',
                         'jsonp' => 'resultSetToJsonp',
                         'xml'    =>    'resultSetToXml',
                         'php'    =>    'resultSetToSerializedPhp',
                         'csv'    =>    'resultSetToCsv',
                         'debug' => 'resultSetToDebug');

        if ( array_key_exists( $format, $formats ) ) {

            $method = $formats[ $format ];

            return $this->$method();

        } else {

            owa_coreAPI::debug("Format '$format' is not supported.");
            return $this;
        }
    }

    // @todo move this to a proper xml view
    function resultSetToXml() {

        $t = new owa_template;

        $t->set_template('resultSetXml.php');
        $t->set('rs', $this);

        return $t->fetch();
    }

    //json formatting has been moved to owa_jsonView
    function resultSetToJson() {

        return $this;
    }

    //json formatting has been moved to owa_jsonView
    function resultSetToJsonp($callback = '') {

        return $this;
    }

    function resultSetToDebug() {

        return print_r($this, true);
    }

    function resultSetToSerializedPhp() {
        return serialize($this);
    }

    function resultSetToHtml($class = 'dimensionalResultSet') {
        $t = new owa_template;

        $t->set_template('resultSetHtml.php');
        $t->set('rs', $this);
        $t->set('class', $class);

        return $t->fetch();
    }

    function getDataRows() {
        return $this->resultsRows;
    }

    function getResultsRows() {
        return $this->resultsRows;
    }

    function addLinkToRowItem($item_name, $template, $subs) {


        foreach ($this->resultsRows as $k => $row) {

            $sub_array = array();

            foreach ($subs as $sub) {
                $sub_array[] = urlencode($this->resultsRows[$k][$sub]['value']);
            }

            $this->resultsRows[$k][$item_name]['link'] = vsprintf($template, $sub_array);
        }

    }

    function getSeries($name) {

        $rows = $this->getDataRows();

        if ($rows) {
            $series = array();
            foreach ($rows as $row) {
                foreach($row as $item) {
                    if ($item['name'] === $name) {
                        $series[] = $item['value'];
                    }
                }
            }
            return $series;
        } else {
            return false;
        }
    }

    function getAggregateMetric($name) {

        if ( array_key_exists( $name, $this->aggregates ) ) {
            return $this->aggregates[$name]['value'];
        } else {
            owa_coreAPI::debug( "No aggregate metric called $name found." );
        }
    }

    function setAggregateMetric($name, $value, $label, $data_type, $formatted_value = '') {

        $this->aggregates[$name] = array('result_type' => 'metric', 'name' => $name, 'value' => $value, 'label' => $label, 'data_type' => $data_type, 'formatted_value' => $formatted_value);
    }

    function appendRow($row_num, $type, $name, $value, $label, $data_type, $formatted_value = '') {

        $this->resultsRows[$row_num][$name] = array(
            'result_type'         => $type,
            'name'                 => $name,
            'value'             => $value,
            'label'             => $label,
            'data_type'         => $data_type,
            'formatted_value'     => $formatted_value
        );
    }

    function removeMetric($name) {

        if (array_key_exists($name, $this->aggregates)) {

            unset($this->aggregates[$name]);
        }

        if ($this->getRowCount() > 0) {

            foreach ($this->resultsRows as $k => $row) {

                if (array_key_exists($name, $row)) {

                    unset($this->resultsRows[$k][$name]);
                }
            }
        }
    }

    function createResultSetHash() {

        $this->guid = md5(serialize($this));
    }

    function setRelatedDimensions( $dims = '' ) {

        if ( $dims ) {
            $this->relatedDimensions = $dims;
        }
    }

    function setRelatedMetrics( $metrics = '' ) {

        if ( $metrics ) {
            $this->relatedMetrics = $metrics;
        }
    }
}

?>