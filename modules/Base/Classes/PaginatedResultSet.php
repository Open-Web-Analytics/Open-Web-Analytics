<?php
namespace OWA\Module\Base\Classes;

/**
 * Pagination
 * 
 *
 * @phpstan-import-type PeriodInfo from \OWA\Module\Base\Classes\TimePeriod
 * @phpstan-import-type Readiness from \OWA\Module\Base\Classes\Cube\Status
 * @phpstan-type Cell array<string, mixed>
 * @phpstan-type ResultRow array<string, Cell>
 * @phpstan-type RelatedDimensions array<string, list<array{name: string, label: string, description: string, group_label: string, data_type: string}>>
 * @phpstan-type RelatedMetrics array<string, list<array{name: string, label: string, description: string, group: string, group_label: string, metric_type: string}>>
 */
class PaginatedResultSet {

    /**
     * Unique hash of result set used by front end
     * to see if there are any changes.
     *
     * @var string|null
     */
    public $guid;
    
    /**
     * Time period of the results
     *
     * @var PeriodInfo|null
     */
    public $timePeriod;
    
    /** @var int|null */
    public $resultsPerPage = 25;
    
    /**
     * The total number of result rows available
     * in the database
     *
     * @var int
     */
    public $resultsTotal = 0;
    
    /**
     * The total number of result rows
     * contained in this result set
     *
     * @var int|null
     */
    public $resultsReturned;
    
    /** @var string|null */
    public $sortColumn;
    
    /** @var string|null asc or desc */
    public $sortOrder;

    /**
     * Aggregate values for metrics
     *
     * @var ResultRow name => cell
     */
    public $aggregates = [];
    
    /**
     * Data set rows
     *
     * @var array<int, ResultRow> each row name => cell
     */
    public $resultsRows = [];
    
    /**
     * Labels for metrics and dimensions
     *
     * @var array<string, string|null>|null
     */
    public $labels;
    
    /**
     * Convienence flag set when there are
     * additional pages of results
     *
     * @var bool|null
     */
    public $more;
    
    /** @var int|null */
    public $page = 1;
    
    /**
     * Total number of pages of results available
     *
     * @var float|null
     */
    public $total_pages;

    /**
     * The API URL that produces the results
     *
     * @var string|null
     */
    public $self;

    /**
     * The API URL that produces the next page of results
     *
     * @var string|null
     */
    public $next;

    /**
     * The API URL that produces the previous page of results
     *
     * @var string|null
     */
    public $previous;

    /**
     * The base API URL that is used to construct client side pagination links.
     * Does not contain any 'page' params.
     *
     * @var string|null
     */
    public $base_url;

    /**
     * The list of related dimensions that can be added to the result set
     *
     * @var RelatedDimensions family => dimensions
     */
    public $relatedDimensions = [];

    /**
     * The list of related metrics that can be added to the result set
     *
     * @var RelatedMetrics group => metrics
     */
    public $relatedMetrics = [];
    
    /**
     * The list of params that make up the query
     *
     * @var array<string, mixed>|null
     */
    public $queryParams;
    
    /**
     * When the newest data this result reads was built, as unix seconds: the
     * cube moves only when a build runs, so this is what the numbers are "as
     * of". Null with no rows, or where it does not apply.
     *
     * @var int|null
     */
    public $asOf = null;

    /**
     * Why there is no data to query, when there is none: the Property's cube
     * does not exist yet. Classes\Cube\Status::readinessOfProperty(). Null otherwise.
     *
     * Set instead of querying, so a client can tell "not ready" from a real
     * zero -- an empty result set alone cannot say which.
     *
     * @var Readiness|null
     */
    public $notReady = null;

    /**
     * The comparison period, when the request asked for one (`compare`): its
     * key, startDate, endDate and aggregates. Each metric cell then carries
     * compare_value, compare_formatted_value and change. See CompareMerger.
     *
     * @var array<string, mixed>|null
     */
    public $compare = null;

    /** @var list<string>|null */
    public $errors;

    /**
     * The subset of $errors that mean the REQUEST was malformed.
     *
     * Separate because $errors can hold routine entries, such as an unknown
     * sort column. Only these should turn a response into a failure.
     *
     * @var list<string>
     */
    public $request_errors = [];


    public function __construct() {

    }

    /**
     * @param  int|null $limit
     * @return void
     */
    public function setLimit($limit) {

        $this->resultsPerPage = $limit;
    }

    /**
     * @param  int|null $page
     * @return void
     */
    public function setPage($page) {

        $this->page = $page;
    }

    /**
     * @return void
     */
    public function setMorePages() {

        $this->more = true;
    }

    /**
     * @return int
     */
    public function calculateOffset() {

        return $this->resultsPerPage * ($this->page - 1);
    }

    /**
     * @param  array<mixed>|null $results
     * @return void
     */
    public function countResults( $results = [] ) {
        
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

    /**
     * Rows from another query after this page's own: as many as the page has
     * room for. $fetched is how many that query read, within the read-ahead,
     * and is counted with this page's (countResults()), so the pager and the
     * next link reach them.
     *
     * @param array<ResultRow> $rows
     * @param int   $fetched
     * @return void
     */
    public function appendRows( array $rows, $fetched ) {

        $room = max( 0, (int) $this->resultsPerPage - count( (array) $this->resultsRows ) );

        $this->resultsRows     = array_merge( array_values( (array) $this->resultsRows ), array_slice( array_values( $rows ), 0, $room ) );
        $this->resultsReturned = count( $this->resultsRows );
        $this->resultsTotal    = (int) $this->resultsTotal + (int) $fetched;

        if ( $this->resultsPerPage ) {

            $this->total_pages = ceil( ( $this->resultsTotal + $this->calculateOffset() ) / $this->resultsPerPage );

            if ( $this->resultsTotal > $this->resultsPerPage ) {

                $this->setMorePages();
            }
        }

        $this->setResultSetUrls( (array) $this->queryParams );
        $this->createResultSetHash();
    }

    /**
     * @return int
     */
    public function getRowCount() {

        return $this->resultsTotal;
    }
    
    /**
     * @param  array<string, mixed> $params
     * @return void
     */
    public function setQueryParams( $params ) {
        
        $this->queryParams = $params;
    }

    /**
     * @param  array<int, ResultRow> $results
     * @param  array<string, mixed>  $query_params
     * @param  array{resultsPerPage?: int|null, page?: int|null} $options
     * @return array<int, ResultRow> the rows of this page
     */
    public function generate( $results, $query_params, $options = []) {
        
        $defaults = [
            
            'resultsPerPage'    => 10,
            'page'              => 1
        ];
        
        if ( ! empty( $results ) ) {
            
            $options = \OWA\Core\Lib::setDefaultParams( $defaults, $options );
            
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
     *
     * @param  array<string, mixed> $query_params
     * @return void
     */
    public function setResultSetUrls( $query_params ) {
        
        $urls = [];
        $auth = \OWA\Core\Auth::get_instance();
        $api_url = \OWA\Core\CoreAPI::getSetting('base', 'rest_api_url');
        
        $this->base_url = $api_url;
        
        // add query params
        $query_params['do']         = 'reports';
        $query_params['module']     = 'base';
        $query_params['version']    = 'v1';
        
        // if the REST API request was authorized using cookies then
        // we need to add a nonce to the urls
        if ( $auth->getAuthMethod() === 'cookies' ) {
            $query_params['nonce'] = \OWA\Core\CoreAPI::createRestApiNonce( $query_params['version'], $query_params['module'], $query_params['do'], 'GET' );
        }
        
        // add current page if any
        if ( $this->page ) {
            
            $query_params['page'] = $this->page;
        }
        
        // add limit
        if ($this->resultsPerPage) {
            $query_params['resultsPerPage'] = $this->resultsPerPage;
        }

        // build url for this result set
        $link_template = \OWA\Core\CoreAPI::getSetting('base', 'link_template');
        
        $q = $this->buildQueryString($query_params);
        
        $urls['self'] = sprintf($link_template, $api_url, $q);
        
            
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
            
            
            $this->next = $urls['next'];
        }
        
        // build previous url if page is greater than 2
        if ( $this->page >=2 ) {
            
            $previous_query_params = $query_params;
            
            $previous_query_params['page'] = $query_params['page'] - 1;
            
            $pq = $this->buildQueryString($previous_query_params);
            
            $urls['previous'] = sprintf($link_template, $api_url, $pq);
            
            
            $this->previous = $urls['previous'];
        }
        
        // add query params array to result set
        $this->setQueryParams( $query_params );
    }
    
    /**
     * @param  array<string, mixed> $params
     * @param  string $seperator
     * @return string
     */
    public function buildQueryString($params, $seperator = '&') {

        $new = [];
        // OWA's own API URLs carry no prefix. The wire namespace ('ns') still
        // governs cookies and tracker params.
        foreach ($params as $k => $v) {
            
            if ($v) {
                $new[$k] = $v;
            }
        }

        return http_build_query($new,'', $seperator);
    }
    
    /**
     * @param  array<string, string|null> $labels
     * @return void
     */
    public function setLabels($labels) {

        $this->labels = $labels;
    }

    /**
     * @param  PeriodInfo $info
     * @return void
     */
    public function setPeriodInfo($info) {
        $this->timePeriod = $info;
    }

    /**
     * @param  string $key
     * @return string|null|void
     */
    public function getLabel($key) {

        if (array_key_exists($key, $this->labels)) {
            return $this->labels[$key];
        }
    }

    /**
     * @return array<int, ResultRow>
     */
    public function getDataRows() {
        return $this->resultsRows;
    }

    /**
     * @return array<int, ResultRow>
     */
    public function getResultsRows() {
        return $this->resultsRows;
    }

    /**
     * @param  string $name
     * @return mixed|void
     */
    public function getAggregateMetric($name) {

        if ( array_key_exists( $name, $this->aggregates ) ) {
            return $this->aggregates[$name]['value'];
        } else {
            \OWA\Core\CoreAPI::debug( "No aggregate metric called $name found." );
        }
    }

    /**
     * @param  string      $name
     * @param  mixed       $value
     * @param  string|null $label
     * @param  string|null $data_type
     * @param  mixed       $formatted_value
     * @return void
     */
    public function setAggregateMetric($name, $value, $label, $data_type, $formatted_value = '') {

        $this->aggregates[$name] = ['result_type' => 'metric', 'name' => $name, 'value' => $value, 'label' => $label, 'data_type' => $data_type, 'formatted_value' => $formatted_value];
    }

    /**
     * @param  int         $row_num
     * @param  string      $type      metric or dimension
     * @param  string      $name
     * @param  mixed       $value
     * @param  string|null $label
     * @param  string|null $data_type
     * @param  mixed       $formatted_value
     * @return void
     */
    public function appendRow($row_num, $type, $name, $value, $label, $data_type, $formatted_value = '') {

        $this->resultsRows[$row_num][$name] = [
            'result_type'         => $type,
            'name'                 => $name,
            'value'             => $value,
            'label'             => $label,
            'data_type'         => $data_type,
            'formatted_value'     => $formatted_value
        ];
    }

    /**
     * @param  string $name
     * @return void
     */
    public function removeMetric($name) {

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

    /**
     * @return void
     */
    public function createResultSetHash() {

        $this->guid = md5(serialize($this));
    }

    /**
     * @param  RelatedDimensions|'' $dims
     * @return void
     */
    public function setRelatedDimensions( $dims = '' ) {

        if ( $dims ) {
            $this->relatedDimensions = $dims;
        }
    }

    /**
     * @param  RelatedMetrics|'' $metrics
     * @return void
     */
    public function setRelatedMetrics( $metrics = '' ) {

        if ( $metrics ) {
            $this->relatedMetrics = $metrics;
        }
    }
}
