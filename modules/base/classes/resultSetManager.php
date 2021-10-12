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
 * Result Set Manager
 *
 * Responsible for creating a data result set from various metrics and dimensions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

class owa_resultSetManager extends owa_base {

    /**
     * The params of the caller, either a report or graph
     *
     * @var array
     */
    var $params = array();

    /**
     * The lables for calculated measures
     *
     * @var array
     */
    var $labels = array();

    /**
     * Data Access Object
     *
     * @var object
     */
    var $db;

    /**
     * The dimensions to groupby
     *
     * @var array
     */
    var $dimensions = array();

    /**
     * The Number of Dimensions to groupby
     *
     * @var integer
     */
    var $dimensionCount;

    /**
     * The table/column or denormalized dimensions
     * associated with this metric
     *
     * @var array
     */
    var $denormalizedDimensions = array();

    var $_default_offset = 0;
    var $page = 1;
    var $limit;
    var $order;
    var $format;
    var $constraint_operators = array('==','!=','>=', '<=', '>', '<', '=~', '!~', '=@','!@');
    var $related_entities = array();
    var $related_dimensions = array();
    var $related_metrics = array();
    var $resultSet;
    var $base_table;
    var $metrics = array();
    var $metricsByTable = array();
    var $childMetrics = array();
    var $calculatedMetrics = array();
    var $query_params = array();
    var $baseEntity;
    var $metricObjectsByEntityMap = array();
    var $metricObjectsCache = array();
    var $errors = array();
    var $formatters = array();
    var $segment;
    var $pagination;
    

    function __construct($db = '') {

        if ($db) {
            $this->db = $db;
        } else {
            $this->db = owa_coreAPI::dbSingleton();
        }

        $this->formatters = array(
            //'yyyymmdd' => array($this, 'dateFormatter'),
            'timestamp'        => array($this, 'formatSeconds'),
            'percentage'     => array($this, 'formatPercentage'),
            'integer'         => array($this, 'numberFormatter'),
            'currency'        => array($this, 'formatCurrency')
        );
        
        $this->resultSet = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');

        return parent::__construct();
    }


    function setConstraint($name, $value, $operator = '') {

        if (empty($operator)) {
            $operator = '=';
        }

        if ( ! owa_lib::isEmpty( $value ) ) {
            $this->params['constraints'][$name] = array('operator' => $operator, 'value' => $value, 'name' => $name);
        }
    }

    function setConstraints($array) {

        if (is_array($array)) {

            if ( ! isset($this->params['constraints']) ) {
                $this->params['constraints'] = array();
            }

            foreach ($array as $constraint) {
                $this->setConstraint($constraint['name'], $constraint['value'], $constraint['operator']);
            }
        }
    }

    function setSiteId($siteId) {

        //used for urls
        $this->query_params['siteId'] = $siteId;
        $this->setConstraint('siteId', $siteId);
    }

    function getSiteId() {

        if ( isset( $this->params['siteId'] ) ) {

            return $this->params['siteId'];
        }
    }

    function constraintsStringToArray($string) {

        if ($string) {
            //print_r($string);
            // add string to query params array for use in URLs.
            $this->query_params['constraints'] = $string;

            return $this->parseConstraintsString($string);
        }
    }
	
	/**
     * Transforms a comma separated string of constraints into array
     * The string format is used in REST API calls.
     */
    function parseConstraintsString($string) {

        $constraints = explode(',', $string);
        $constraint_array = array();

        foreach($constraints as $constraint) {

            foreach ($this->constraint_operators as $operator) {

                if (strpos($constraint, $operator)) {

                    list ($name, $value) = explode($operator, $constraint);
                    $constraint_array[] = array('name' => $name, 'value' => html_entity_decode($value), 'operator' => $operator);
                    break;
                }
            }
        }

        return $constraint_array;
    }

    function getConstraints() {

        return $this->params['constraints'];
    }

    function getConstraint( $key ) {

        if ( isset( $this->params['constraints'][$key] ) ) {
            return $this->params['constraints'][$key];
        }
    }

    function applyConstraints( $constraints = '', $db = '', $entity = '') {

        if ( !$db ) {
            $db = $this->db;
        }

        if ( ! $constraints ) {
            $constraints = $this->getConstraints();
        }
        //owa_coreAPI::debug(print_r($constraints, true));
        foreach ($constraints as $k => $constraint) {

            $this->applyConstraint($constraint, $db, $entity);
        }
    }
	
	/**
     * Generate constraint clause using metrics and dimensions
     */
    function applyConstraint( $constraint, $db = '', $entity= '') {

        if ( ! $entity ) {
            $entity = $this->baseEntity;
        }

        if ( $this->isDimension( $constraint['name'] ) ) {

            $dim = $this->lookupDimension($constraint['name'], $entity);

            $col = $dim['column'];
            $constraint['name'] = $col;

            $db->where($constraint['name'], $constraint['value'], $constraint['operator']);
        }

        if ( $this->isMetric( $constraint['name'] ) ) {

            // get metric object
            $m = $this->getMetricImplementation( $constraint['name'] );
            // if not calculated
            if ( ! $m->isCalculated() ) {
                $col = $m->getSelectWithNoAlias();
                $db->having($col, $constraint['value'], $constraint['operator']);
            } else {

                $this->addError( 'Cannot add a calculated metric to a constraint.' );
            }
        }
    }

    function setSegment($segment) {

        $this->query_params['segment'] = $segment;

        if ( substr($segment, 0, 9) === 'dynamic::') {

            $segment = substr($segment, 9);

        } elseif ( substr($segment, 0, 4) === 'id::') {
            // look up segment from db
        }

        $parsed = $this->parseConstraintsString( $segment );
        $metrics = array();
        $dimensions = array();

        foreach ( $parsed as $item ) {

            if ( $this->isMetric( $item['name'] ) ) {
                $metrics[$item['name']] = $item;

                // add to all metrics or dimensions array - needed to determin base entity
                /*
if ( ! in_array($item['name'], $this->allMetrics) ) {
                    $this->allMetrics[] = $item['name'];
                }

                if ( ! in_array($item['name'], $this->allDimensions) ) {
                    $this->allDimensions[] = $item['name'];
                }
*/

            } elseif ($this->isDimension( $item['name'] ) ) {
                $dimensions[$item['name']] = $item;
            }
        }

        $this->segment = array('metrics' => $metrics, 'dimensions' => $dimensions);
    }

    function getSegment() {

        return $this->segment;
    }

    function getMetricNamesFromSegment() {

        if ( isset($this->segment['metrics'] ) ) {

            return array_keys($this->segment['metrics']);
        } else {

            return array();
        }
    }

    function getDimensionNamesFromSegment() {

        if ( isset( $this->segment['dimensions'] ) ) {

            return array_keys( $this->segment['dimensions'] );
        } else {
            return array();
        }
    }

    function chooseBaseEntity() {

        $metric_imps = array();

        // load metric implementations
        $all_metrics = $this->metrics;

        // add in metrics from segment if present
        if ( isset($this->segment['metrics'] ) ) {

            //$all_metrics = array_unique( array_merge( $this->metrics, $this->getMetricNamesFromSegment() ) );
        }

        // add metrics from constraints
        $all_metrics = array_unique( array_merge( $this->metrics, $this->getMetricNamesFromConstraints() ) );

        // get all metric implmentations so we can see what entities we have to choose from
        foreach ($all_metrics as $metric_name) {

            $metric_imps = array_merge($this->getMetricEntities($metric_name), $metric_imps);
        }

        owa_coreAPI::debug('pre-reduce set of entities to choose from: '.print_r($metric_imps, true));

        $entities = array();

        // reduce metric entities. this will give us the fact tables to choose from.
        foreach ($metric_imps as $mimp) {

            if (empty($entities)) {
                $entities = $mimp;
            }

            $entities = $this->reduceTables($mimp, $entities);

            if (empty($entities)) {
                return $this->addError('illegal metric combination');
            }
        }

        owa_coreAPI::debug('post-reduce set of entities to choose from: '.print_r($entities, true));

        // check summary level of entities
        $niceness = array();

        foreach ($entities as $entity) {

            $niceness[$entity] = owa_coreAPI::entityFactory($entity)->getSummaryLevel();
        }

        // sort the fact table list by summary level
        arsort($niceness);

        owa_coreAPI::debug('Entities summary levels: '.print_r($niceness, true));

        $entity_count = count($niceness);
        $i = 1;
        //check entities for dimension relations
        foreach ($niceness as $entity_name => $summary_level) {

            $error = false;

            // check dimensions in segment for relation to base entity.
            if ( isset( $this->segment['dimensions'] ) ) {

                //$dims = array_unique( array_merge( $this->dimensions, $this->getDimensionNamesFromSegment() ) );
                $segment_dims = $this->getDimensionNamesFromSegment();

                foreach ($segment_dims as $segment_dim) {

                    $check = $this->isDimensionRelated($segment_dim, $entity_name);

                    // is the realtionship check fails then move onto the next entity.
                    if (!$check) {
                        $error = true;
                        owa_coreAPI::debug("Segment dimension $dimension is not related to $entity_name. Moving on to next entity...");
                        break;
                    } else {
                        // set related dimensions. this is needed for joins.
                        owa_coreAPI::debug("Segment Dimension: $segment_dim is related to $entity_name.");
                    }
                }
            }

            //cycle through each dimension from dim list and those found in constraints.
            $dims = array_unique( array_merge( $this->dimensions, $this->getDimensionsFromConstraints() ) );

            owa_coreAPI::debug(sprintf('Dimensions: %s',print_r($this->dimensions, true)));

            owa_coreAPI::debug(sprintf('Checking the following dimensions for relation to %s: %s',$entity_name, print_r($dims, true)));

            foreach ($dims as $dimension) {

                $check = $this->isDimensionRelated($dimension, $entity_name);

                // is the realtionship check fails then move onto the next entity.
                if (!$check) {
                    $error = true;
                    owa_coreAPI::debug("$dimension is not related to $entity_name. Moving on to next entity...");
                    break;
                } else {
                    // set related dimensions. this is needed for joins.
                    $dim_array = $this->getDimensionByEntityName($dimension, $entity_name);

                    $this->setRelatedDimension( $dim_array );
                    owa_coreAPI::debug("Dimension: $dimension is related to $entity_name.");
                }
            }

            // is no error then everythig is related and we are good to go.
            if (!$error) {
                owa_coreAPI::debug('optimal base entity is: '.$entity_name);
                $this->baseEntity = owa_coreAPI::entityFactory($entity_name);
                return $this->baseEntity;
            }

            if ($i === $entity_count) {
                $this->addError('illegal dimension combination: '.$dimension);
            } else {
                $i++;
            }
        }
    }

    function setRelatedDimension($dimension) {

        $this->related_dimensions[$dimension['name']] = $dimension;
    }

    function getDimensionsFromConstraints() {

        $dims = array();

        $constraints = $this->getConstraints();
        //print_r($constraints);
        if (!empty($constraints)) {

            foreach ($constraints as $carray) {

                if ($this->isDimension( $carray['name'] ) ) {
                    $dims[] = $carray['name'];
                }
            }
        }

        return $dims;
    }

    function getMetricNamesFromConstraints() {

        $metrics = array();
        foreach ($this->getConstraints() as $k => $constraint) {

            if ( $this->isMetric( $constraint['name'] ) ) {
                $metrics[] = $constraint['name'];
            }
        }

        return $metrics;
    }

    function isDimensionRelated($dimension_name, $entity_name) {

        $entity = owa_coreAPI::entityFactory($entity_name);

        $dimension = $this->lookupDimension($dimension_name, $entity);

        if ($dimension['denormalized'] === true) {
            //$this->related_dimensions[$dimension['name']] = $dimension;
            owa_coreAPI::debug("Dimension: $dimension_name is denormalized into $entity_name");
            return true;
        } else {

            $fk = $this->getDimensionForeignKey($dimension, $entity);

            if ($fk) {
                owa_coreAPI::debug("Dimension: $dimension_name is related to $entity_name");
                //$this->related_dimensions[$dimension['name']] = $dimension;
                return true;
            } else {
                owa_coreAPI::debug("Could not find a foreign key for $dimension_name in $entity_name");
            }
        }
    }

    function getMetricEntities($metric_name) {
        owa_coreAPI::debug("getting metric entities for $metric_name");

        //get the class implementations
        $s = owa_coreAPI::serviceSingleton();
        $classes = $s->getMetricClasses($metric_name);

        $entities = array();

        // cycles through metric classes and get their entity names
        foreach ($classes as $name => $map) {
            $m = owa_coreAPI::metricFactory($map['class'], $map['params']);

            // check to see if this is a calculated metric
            if ($m->isCalculated()) {

                foreach ($m->getChildMetrics() as $cmetric_name) {
                    $this->addCalculatedMetric($m);
                    $entities = array_merge($this->getMetricEntities($cmetric_name), $entities);
                }

            } else {
                $this->metricObjectsByEntityMap[$m->getEntityName()][$metric_name] = $m;
                $entities[$metric_name][] = $m->getEntityName();
            }

        }

        return $entities;
    }

    function reduceTables($new, $old) {

        return array_intersect($new, $old);
    }

    function getDimensionForeignKey($dimension, $entity) {

        if ($dimension) {
            //$entity = ;
            $dim = $dimension;
            $fk = array();
            // check for foreign key column by name if dimension specifies one
            //print_r($dim);
            if ( isset($dim['foreign_key_name']) && ! empty($dim['foreign_key_name'])) {
                // get foreign key col by
                if ($entity->isForeignKeyColumn($dim['foreign_key_name'])){
                    $fk = array('col' => $dim['foreign_key_name'], 'entity' => $entity);
                }

            } else {
                // if not check for foreign key by entity name
                //check to see if the metric's entity has a foreign key to the dimenesion table.
                $fk = array();

                $fkcol = $entity->getForeignKeyColumn($dim['entity']);
                owa_coreAPI::debug("Foreign Key check: ". print_r($fkcol, true));
                if ($fkcol) {
                    $fk['col'] = $fkcol;
                    $fk['entity'] = $entity;
                }
            }

            return $fk;
        }
    }

    function isDimension( $name ) {

        $dims = owa_coreAPI::getAllDimensions();
        //print_r($dims);
        return in_array( $name, array_keys( $dims ) );
    }

    function isMetric( $name ) {

        $metrics = owa_coreAPI::getAllMetrics();
        return in_array( $name, array_keys( $metrics ) );
    }

    function getDimensionByEntityName($dim_name, $entity_name) {

        $entity = owa_coreAPI::entityFactory($entity_name);
        return $this->lookupDimension($dim_name, $entity);
    }

    /**
     * Retrieves dimension given a name and associated fact table entity.
     *
     * @param $name string the name of the dimenson
     * @param $entity    object    the entity object
     * @return array
     */
    function lookupDimension($name, $entity) {

        // check for denormalized
        $service = owa_coreAPI::serviceSingleton();
        $dim = $service->getDenormalizedDimension($name, $entity->getName());

        if ($dim) {
            //apply table aliasing to dimension column
            $dim['column'] = $entity->getTableAlias().'.'.$dim['column'];
        } else {

            // check for normalized dim
            if (array_key_exists($name, $this->related_dimensions)) {
                $dim = $this->related_dimensions[$name];
            } else {

                $dim = $service->getDimension($name);

                if ($dim) {
                    $dimEntity = owa_coreAPI::entityFactory($dim['entity']);
                    // alias needs to use fk name in case there are two joins on the
                    // same table. This is also used in addRelation method
                    $alias = $dimEntity->getTableAlias().'_via_'.$dim['foreign_key_name'];
                    //$dim['column'] = $dimEntity->getTableAlias().'.'.$dim['column'];
                    $dim['column'] = $alias.'.'.$dim['column'];
                } else {
                    $msg = "$name is not a registered dimension.";
                    owa_coreAPI::debug($msg);
                    $this->addError($msg);
                }

            }
        }

        return $dim;
    }

    function setLimit($value) {

        if (!empty($value)) {

            $this->limit = $value;
        }
    }

    function setOrder($value) {

        if (!empty($value)) {
            $this->params['order'] = $value;
        }
    }

    function getOrder() {

        if (array_key_exists('order', $this->params)) {
            return $this->params['order'];
        }
    }

    function setSort($column, $order) {

        //$this->params['orderby'][] = array($this->getColumnName($column), $order);
    }

    function setSorts($array) {

        if (is_array($array)) {

            if (!empty($this->params['orderby'])) {
                $this->params['orderby'] = array_merge($array, $this->params['orderby']);

            } else {
                $this->params['orderby'] = $array;
            }
        }
    }

    function applySorts() {

        $sorts = $this->params['orderby'];

        if ($sorts) {

            foreach ($sorts as $sort) {

                $sort_col = $sort[0];

                if ( $this->isMetric( $sort[0] ) ) {
                    $sort_metric = $this->getMetricImplementation($sort[0]);
                    if ( $sort_metric->isCalculated() ) {

                        $child_metrics = $sort_metric->getChildMetrics();
                        $formula = $sort_metric->getFormula();

                        // replace metric names with unique identifiers
                        // so that follow on replacement doesn't clobber anything.
                        foreach ($child_metrics as $child) {

                            $formula = str_replace($child, '__'.$child, $formula);
                        }

                        // now replace the names with seldct statements.
                        foreach ($child_metrics as $child) {
                            $child_metric = $this->getMetricImplementation( $child );
                            $select = $child_metric->getSelect();
                            $formula = str_replace('__'.$child, $select[0], $formula);
                        }

                        $sort_col = $formula;
                    }
                }

                $this->db->orderBy($sort_col, $sort[1]);
            }
         }
    }

    function sortStringToArray($string) {

        if ($string) {

            // add string to query params array for use in URLs.
            $this->query_params['sort'] = $string;

            $sorts = explode(',', $string);

            $sort_array = array();

            foreach ($sorts as $sort) {

                if (strpos($sort, '-')) {
                    $column = substr($sort, 0, -1);
                    $order = 'DESC';
                } else {
                    $column = $sort;
                    $order = 'ASC';
                }

                //$col_name = $this->getColumnName($column);
                $check = $this->isSortValid($column);

                if ($check) {

                    $col_name = $column;

                    if ($col_name) {
                        $sort_array[$sort][0] = $col_name;
                        $sort_array[$sort][1] = $order;

                    } else {
                        $this->addError("$column is not a valid column to sort on");
                    }
                }
            }

            return $sort_array;
        }
    }

    function isSortValid($needle) {

        $haystack = array_merge($this->metrics, $this->dimensions);
        return in_array($needle, $haystack);
    }

    function setPage($value) {

        if (!empty($value)) {

            $this->page = $value;

            if (!empty($this->pagination)) {
                $this->pagination->setPage($value);
            }
        }
    }

    function setOffset($value) {

        if (!empty($value)) {
            $this->params['offset'] = $value;
        }
    }

    function setFormat($value) {
        if (!empty($value)) {
            $this->format;
            $this->params['result_format'] = $value;
        }
    }

    function setPeriod($value) {
        if (!empty($value)) {
            $this->params['period'] = $value;
        }
    }

    function setTimePeriod($period_name = '', $startDate = null, $endDate = null, $startTime = null, $endTime = null) {

        $map = false;

        if ($startDate && $endDate) {
            $period_name = 'date_range';
            $map = array('startDate' => $startDate, 'endDate' => $endDate);
            $dimension_name = 'date';
            $format = 'yyyymmdd';
        } elseif ($startTime && $endTime) {
            $period_name = 'time_range';
            $map = array('startTime' => $startTime, 'endTime' => $endTime);
            $dimension_name = 'timestamp';
            $format = 'timestamp';
        } else {
            owa_coreAPI::debug('no start/end params passed to owa_metric::setTimePeriod');
            $dimension_name = 'date';
            $format = 'yyyymmdd';
        }

        // add to query params array for use in URL construction
        if ($map) {
            $this->query_params = array_merge($map, $this->query_params);
        } else {
            $this->query_params['period'] = $period_name;
        }
        
        if ( $period_name ) {

	        $p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
	
	        $p->set($period_name, $map);
	
	        $this->setPeriod($p);
	
	        $start = $p->startDate->get($format);
	        $end = $p->endDate->get($format);
	
	        $this->setConstraint($dimension_name, array('start' => $start, 'end' => $end), 'BETWEEN');
		}

    }

    function setStartDate($date) {

        if (!empty($date)) {
            $this->params['startDate'] = $date;
        }
    }

    function setEndDate($date) {
        if (!empty($date)) {
            $this->params['endDate'] = $date;
        }
    }

    function applyMetaDataToResults( $results ) {

        $new_rows = array();
        
        if ( $results ) {

	        foreach ($results as $row) {
	
	            $new_rows[] = $this->applyMetaDataToSingleResultRow( $row );
	        }
        }

        return $new_rows;
    }

    function applyMetaDataToSingleResultRow($row) {

        $new_row = array();

        foreach ($row as $k => $v) {

            if (in_array($k, $this->dimensions)) {
                $type = 'dimension';
                $dim = $this->lookupDimension($k, $this->baseEntity);
                $data_type = $dim['data_type'];
            } elseif (in_array($k, $this->metrics)){
                $type = 'metric';
                $data_type = $this->getMetric($k)->getDataType();
            }
            else {
                // can't throw exception here as the metrics are sometimes used to geenrate calculated metrics
                // therefor no meta data is applied as this stage.
                //throw new Exception($k.' is not a metric or dimension. Check the configuration!');
            }



            $new_row[$k] = array(
                'result_type' => $type,
                'name'           => $k,
                'value'       => $v,
                'formatted_value' => $this->formatValue($data_type, $v),
                'label' => $this->getLabel($k), 'data_type' => $data_type);
        }

        return $new_row;
    }

    function formatValue($type, $value) {

        if (array_key_exists($type, $this->formatters)) {

            $formatter = $this->formatters[$type];

        } else {
            $s = owa_coreAPI::serviceSingleton();
            $formatter = $s->getFormatter($type);
        }

        // If we found a formatter, use it
        if (!empty($formatter)) {

            $value = call_user_func($formatter, $value);
        }

        return $value;
    }

    function numberFormatter($value) {

        return number_format($value);
    }

    function formatSeconds($value) {

        return date("G:i:s",mktime(0,0,($value)));
    }

    function formatPercentage($value) {

        return number_format($value * 100, 2).'%';
    }

    function formatCurrency($value) {

        return owa_lib::formatCurrency(
                $value,
                owa_coreAPI::getSetting( 'base', 'currencyLocal' ),
                owa_coreAPI::getSetting( 'base', 'currencyISO3' )
        );
    }

    /**
     * Sets an individual label
     * return the key so that it can be nested
     * @return $key string
     */
    function addLabel($key, $label) {

        $this->labels[$key] = $label;
        return $key;
    }

    function getLabel($key = '') {

        if (array_key_exists($key, $this->labels)) {
            return $this->labels[$key];
        } else {
            //owa_coreAPI::debug("No label found for $key.");
        }

    }

    /**
     * Retrieve the labels of the measures
     *
     */
    function getLabels() {

        return $this->labels;
    }

    /**
     * Sets an individual label
     * return the key so that it can be nested
     * @return $key string
     */
    function setLabel($label) {

        $this->labels[$this->getName()] = $label;
    }

    /**
     * Set the labels of the measures
     *
     */
    function setLabels($array) {

        $this->labels = $array;
    }

    function getPeriod() {

        return $this->params['period'];
    }

    function getLimit() {

        return $this->limit;
    }
    
    function getPage() {

        return $this->page;
    }

    /**
     * Adds a dimension to the dimension map
     *
     * Retrieves dimension info from service layer and checks to see if
     * dimension is denromalized or if it is a valid relation
     */
    function setDimension($name) {

        if ($name) {
            $this->dimensions[] = $name;
        }
    }

    function setDimensions($array) {

        if ($array) {

            foreach($array as $name) {

                $this->setDimension($name);
            }
        }
    }

    function dimensionsStringToArray($string) {

        // add string to query params array for use in URLs.
        $this->query_params['dimensions'] = $string;
        return explode(',', $string);
    }

    function metricsStringToArray($string) {

        // add string to query params array for use in URLs.
        $this->query_params['metrics'] = $string;
        return explode(',', $string);
    }


    function dimensionsArrayToString($array) {

        return implode(',', $array);
    }

    /**
     * Applies dimensional sql to dao object
     */
    function applyDimensions() {

        foreach ($this->dimensions as $dimension_name) {
            $dim = $this->lookupDimension($dimension_name, $this->baseEntity);
            // add column name to select statement
            $this->db->selectColumn($dim['column'], $dim['name']);
            // add groupby
            $this->db->groupBy($dim['column']);
            $this->addLabel($dim['name'], $dim['label']);
        }
    }

    function applyJoins() {

        foreach($this->related_dimensions as $dim) {
            $this->addRelation($dim);
        }
    }

    function getBaseEntity() {
        return $this->baseEntity;
    }

    function addRelation($dim, $db = '', $entity = '') {

            if ( ! $db ) {

                $db = $this->db;
            }

            if ( ! $entity ) {
                $entity = $this->getBaseEntity();
            }

            // if denomalized, skip
            if ($dim['denormalized'] === true) {
                return;
            }

            // have already determined base enttiy at this point so use that.
            $fk = $this->getDimensionForeignKey($dim, $entity);
            //print_r($fk);
            //print $fk;
            if ($fk) {

                // create dimension entity
                $dimEntity = owa_coreAPI::entityFactory($dim['entity']);
                // get foreign key column
                //$bm = $this->getBaseMetric();
                //$fpk_col = $bm->entity->getProperty($fk);
                $fpk_col = $fk['entity']->getProperty($fk['col']);
                //$fpk_col = $this->baseEntity->getProperty($fk['col']);

                //print_r($fk['col']);
                $fpk = $fpk_col->getForeignKey();
                // add join
                //print_r($fpk);
                // needed to make joins unique in cases where there are
                // two joins onthe same table using different foreign keys.
                $alias = $dimEntity->getTableAlias().'_via_'.$dim['foreign_key_name'];
                //$this->db->join(OWA_SQL_JOIN, $dimEntity->getTableName(), $dimEntity->getTableAlias(), $fk['entity']->getTableAlias().'.'.$fk['col'], $dimEntity->getTableAlias().'.'.$fpk[1]);
                $db->join(OWA_SQL_JOIN, $dimEntity->getTableName(), $alias, $fk['entity']->getTableAlias().'.'.$fk['col'], $alias.'.'.$fpk[1]);

                //$this->addColumn($dim['name'], $dimEntity->getTableAlias().'.'.$dim['column']);
                $this->addColumn($dim['name'], $alias.'.'.$dim['column']);

            } else {
                // add error result set
                owa_coreAPI::debug(sprintf('%s metric does not have relation to dimension %s', $fk['entity']->getName(), $dim['name']));
            }

    }

    // remove
    function addMetric($metric_name, $child = false) {

        $ret = false;

        $m = $this->getMetric($metric_name);

        if (!$m) {
            $m = owa_coreAPI::metricFactory($metric_name);

            if ($m) {


                // necessary if the metric was first added as a child but later added as a parent.
                if (!$child) {

                    if (array_key_exists($metric_name, $this->childMetrics)) {
                        unset ($this->childMetrics[$metric_name]);
                    }
                } else {
                    // add child metrics to child metric maps
                    // check to see if it wasn't already added as a non-child metric.
                    if (!array_key_exists($metric_name, $this->metrics)){
                        $this->childMetrics[$metric_name] = $metric_name;
                    }
                }

                // check to see if this is a calculated metric
                if ($m->isCalculated()) {

                    return $this->addCalculatedMetric($m);
                }

                if ($this->checkForFactTableRelation($m)) {

                    $this->metrics[$metric_name] = $m;
                    $this->metricsByTable[$m->getTableName()] = $metric_name;
                    $this->addSelect($m->getSelect());
                    $this->addLabel($m->getName(), $m->getLabel());

                    $ret = true;
                }

            } else {
                $this->addError("$metric_name is not a metric.");
            }
        } else {
            $ret =  true;
        }



        return $ret;
    }

    function addCalculatedMetric($calc_metric_obj) {

        // add label of calculated metric obj
        $this->addLabel($calc_metric_obj->getName(),$calc_metric_obj->getLabel());
        // add to calculated metric map
        $this->calculatedMetrics[$calc_metric_obj->getName()] = $calc_metric_obj;

    }

    function getCalculatedMetricByName($name) {

        return $this->calculatedMetrics[$name];
    }

    function addSelect($select_array) {

        $this->params['selects'][] = $select_array;
    }

    //depricated?
    function getSelects() {

        if (array_key_exists('selects', $this->params)) {
            return $this->params['selects'];
        }
    }

    // can only be called after base entity is determined.
    function getMetricImplementation($metric_name) {

        if (!array_key_exists($metric_name, $this->calculatedMetrics)) {

            return $this->metricObjectsByEntityMap[$this->baseEntity->getName()][$metric_name];

        } else {
            return $this->getCalculatedMetricByName($metric_name);
        }
    }
	
	// generates select statment from metrics
    function applyMetrics() {
        //print_r($this->metrics);
        foreach($this->metrics as $k => $metric_name) {

            if (!array_key_exists($metric_name, $this->calculatedMetrics)) {

                $m = $this->metricObjectsByEntityMap[$this->baseEntity->getName()][$metric_name];

                $select = $m->getSelect();
                //print_r ($select);
                $this->db->selectColumn($select[0], $select[1]);
            } else {
                $m = $this->getCalculatedMetricByName($metric_name);
            }

            $this->addLabel($m->getName(), $m->getLabel());
        }

        // add selects for calculated metrics
        if (!empty($this->calculatedMetrics)) {

            // loop through calculated metric objects
            foreach ($this->calculatedMetrics as $cmetric) {
                //create child metrics
                foreach( $cmetric->getChildMetrics() as $child_name) {
                    // check to see if the metric has already been added
                    if (!in_array($child_name, $this->metrics)) {

                        $child = $this->metricObjectsByEntityMap[$this->baseEntity->getName()][$child_name];
                        $select = $child->getSelect();
                        //print_r ($select[0]);
                        $this->db->selectColumn($select[0], $select[1]);
                        // needed so we can remove this temp metric later
                        $this->childMetrics[] = $child_name;
                        owa_coreAPI::debug("Added $child_name to ChildMetrics array");
                    }
                }
            }
        }
    }

    function getFormat() {

        if (array_key_exists('result_format', $this->params)) {
            return $this->params['result_format'];
        }
    }

    function getColumnName($string) {

        //$string = trim($string);
        if (array_key_exists($string, $this->related_dimensions)) {
            return $this->related_dimensions[$string]['column'];
        }

        if (array_key_exists($string, $this->related_metrics)) {
            return $string;
        }


        //return $string;

    }

    /**
     * Sets a metric's column name into the all_columns map
     *
     * this is needed when combining metrics so that sort and
     * constraint column names can be looked up fro ma single map.
     *
     * NEEDED???
     */
    function addColumn($name, $col) {

        $this->all_columns[$name] = $col;
    }

    function addError($msg) {

        $this->errors[] = $msg;
        owa_coreAPI::debug($msg);
    }
    
    function computeAggregates( $bm ) {
	    
	    // creates join statements to dim tables from dimension.
        $this->applyJoins();
        
        // generates where clause based on metrics and dimensions
        $this->applyConstraints();
        
        // generates select statement from metrics
        $this->applyMetrics();

        // generates from clause or a subselect if segment is specified
        if ( $this->segment ) {
            
            $this->db->selectFrom( $this->generateSegmentQuery( $bm ), $bm->getTableAlias() );
        
        } else {
        
            $this->db->selectFrom($bm->getTableName(), $bm->getTableAlias());
        }

        // generate aggregate results
        $results = $this->db->getOneRow();
        
        return $results;
    }
    
    function computeDimensionalRows( $bm ) {
	    
	    // creates join statements to dim tables from dimension.
        $this->applyJoins();
        
        // apply dimensional SQL
        $this->applyDimensions();

        $this->applyMetrics();
        
        $this->applyConstraints();

        // set from table
        if ( $this->segment ) {
            
            $this->db->selectFrom( $this->generateSegmentQuery( $bm ), $bm->getTableAlias() );
            
        } else {
            
            $this->db->selectFrom($bm->getTableName(), $bm->getTableAlias());
        }

        // pass limit and page to result set object if one exists
        // needed??
        if ( ! empty( $this->limit ) ) {
            
            $this->resultSet->setLimit( $this->limit );
        }
       
        if ( ! empty( $this->page ) ) {
            
            $this->resultSet->setPage( $this->page );
        }
        
        if ( ! empty( $this->limit ) ) {
	        
            // query for more than we need
            owa_coreAPI::debug('applying limit of: ' . $this->limit );
            
            $this->db->limit($this->limit * 10);
        }

        if ( ! empty( $this->page ) ) {

            $this->db->offset( $this->calculateOffset() );
            
        } else {
	        
            $this->page = 1;
        }

        $results = $this->db->getAllRows();
        
        return $results;
    }
    
    function calculateOffset() {
		
		if ( $this->page > 1 ) {
		
        	return $this->limit * ( $this->page - 1 );
        
        } else {
	        
	        return 0;
        } 
    }

   	/**
     * Generates a reporting result set using metrics and dimension
     *
     * @return paginatedResultSet obj
     */
    function getResults() {
		
		// determin the best fact table ot use forthe query based on
		// the metrics and dimensions requested
        $bm = $this->chooseBaseEntity();

        if ( $bm ) {

            $bname = $bm->getName();

            owa_coreAPI::debug("Using $bname as fact table entity for this result set.");

            // generate aggregate results
            $results = $this->computeAggregates( $bm );
            
            // merge into result set
            if ( $results ) {
	            
                $this->resultSet->aggregates = array_merge( $this->applyMetaDataToSingleResultRow( $results ), $this->resultSet->aggregates );
            }
			
			$dresults = [];
            // setup dimensional query if dimensions were specificed in query
            if ( ! empty( $this->dimensions ) ) {
				
				// Apply sorts
                if ( array_key_exists( 'orderby', $this->params ) ) {
                
                    $sorts = $this->params['orderby'];
                    
                    // apply sort by
                    if ($sorts) {
	                    
                        $this->applySorts();
                    
                        foreach ($sorts as $sort) {
                            //$this->db->orderBy($sort[0], $sort[1]);
                            $this->resultSet->sortColumn = $sort[0];
                            
                            if (isset($sort[1])){
	                            
                                $this->resultSet->sortOrder = strtolower($sort[1]);
                            } else {
	                            
                                $this->resultSet->sortOrder = 'asc';
                            }
                        }
                    }
                    
                    
                    // query for dimensional results
                $dresults = $this->computeDimensionalRows( $bm );
                
                // paginate the results
                $dresults = $this->applyMetaDataToResults( $dresults );
                    
                }
                
                
            
                // generate dimensonal results
                $this->resultSet->generate( $dresults, $this->query_params, [
	                
	                'resultsPerPage' => $this->getlimit(),
	                'page'			 => $this->getPage()
                ] );
                
            }

            // add labels
            $this->resultSet->setLabels( $this->getLabels() );

            // add period info
            $this->resultSet->setPeriodInfo( $this->params['period']->getAllInfo() );
              
            $this->resultSet = $this->computeCalculatedMetrics( $this->resultSet );
        }
		
		// set any metric/dimension combination errors
        $this->resultSet->errors = $this->errors;
		
		// set related dimensions
        $this->resultSet->setRelatedDimensions( $this->getAllRelatedDimensions( $bm ) );
        
        // set related metrics
        $this->resultSet->setRelatedMetrics( $this->getAllRelatedMetrics( $bm ) );

        return $this->resultSet;
    }
    
  	/**
     * Generates a data result set using DB object directly
     *
     * @return paginatedResultSet obj
     */
    function queryResults() {

        // get paginated result set object
	
        if (array_key_exists('orderby', $this->params)) {
            $sorts = $this->params['orderby'];
            // apply sort by
            if ($sorts) {
                $this->applySorts();
                foreach ($sorts as $sort) {
                    //$this->db->orderBy($sort[0], $sort[1]);
                    $this->resultSet->sortColumn = $sort[0];
                    if (isset($sort[1])){
                        $this->resultSet->sortOrder = strtolower($sort[1]);
                    } else {
                        $this->resultSet->sortOrder = 'asc';
                    }
                }
            }
        }

        // add period info
        if (array_key_exists('period', $this->params) && ! empty( $this->params['period'])) {
	       
	        $this->resultSet->setPeriodInfo($this->params['period']->getAllInfo());
		}
        
		// add any erorrs that should be returned in the result set
        $this->resultSet->errors = $this->errors;  
        
        if ( ! empty( $this->limit ) ) {
	        
            // query for more than we need
            owa_coreAPI::debug('applying limit of: ' . $this->limit );
            
            $this->db->limit( $this->limit * 10 );
        }

        if ( ! empty( $this->page ) ) {

            $this->db->offset( $this->calculateOffset() );
            
        }

        $results = $this->db->getAllRows();
        
        // generate dimensonal results
        $this->resultSet->generate( $results, $this->query_params, [
	                
	                'resultsPerPage' => $this->getlimit(),
	                'page'	=> $this->getPage()
                ] );
		
        return $this->resultSet;
    }

    function generateSegmentQuery( $base_entity ) {

        $segment = $this->getSegment();
        $segment_entity = owa_coreAPI::entityFactory($base_entity->getName());
        $segment_entity->setTableAlias( $segment_entity->getTableAlias() . '_segment');

        if ( $segment ) {
            // use a new data access object
            $db = owa_coreAPI::dbFactory();
            $db->select( $segment_entity->getTableAlias().'.*' );
            $db->from( $segment_entity->getTableName(), $segment_entity->getTableAlias() );

            if ( isset( $segment['metrics'] ) ) {

                //$this->applyConstraints( $segment['metrics'], $db);
            }

            if ( isset( $segment['dimensions'] ) ) {
                //print_r($segment);
                foreach ($segment['dimensions'] as $k => $dim) {

                    $check = $this->isDimensionRelated($dim['name'], $segment_entity->getName() );
                    if ( $check ) {
                        $dimension = $this->lookupDimension($dim['name'], $segment_entity);

                        if ( ! isset($dimension['denormalized'] ) || $dimension['denormalized'] != true ) {
                            $this->addRelation($dimension, $db, $segment_entity);
                        }
                    }
                }
                //print_r( $segment['dimensions'] );
                $this->applyConstraints( $segment['dimensions'], $db, $segment_entity);

                // apply siteId, startDate, and endDate constraints
                $constraint_names = array('siteId', 'date');
                $constraints_apply = array();
                //print_r($this->params['constraints']);
                foreach ( $constraint_names as $name ) {

                    $con = $this->getConstraint( $name );
                    if ( $con ) {
                        $constraints_apply[$name] = $con;
                    }
                }

                if ( $constraints_apply ) {
                    $this->applyConstraints( $constraints_apply, $db, $segment_entity);
                }
            }

            return sprintf('(%s)', $db->generateSelectQuerySql() );
        }

    }

    function computeCalculatedMetrics($rs) {

        foreach ($this->calculatedMetrics as $cm) {

            // add aggregate metric
            $formula = $cm->getFormula();
            $div_by_zero = false;

            //owa_coreAPI::debug( "checking calculated metrics..." );
            //owa_coreAPI::debug( $rs->aggregates );
            foreach ($cm->getChildMetrics() as $metric_name) {

                $ag_value = $rs->getAggregateMetric($metric_name);

                if (empty($ag_value) || $ag_value == 0) {
                    $ag_value = 0;
                    $div_by_zero = true;
                }

                $formula = str_replace($metric_name, $ag_value, $formula);
            }

            if ( ! $div_by_zero ) {
                $value = $this->evalFormula($formula);
            } else {
                $value = 0;
            }

            $rs->setAggregateMetric($cm->getName(), $value, $cm->getLabel(), $cm->getDataType(), $this->formatValue($cm->getDataType(), $value));

            // add dimensional metric

            if ($rs->getRowCount() > 0) {

                foreach ($rs->resultsRows as $k => $row) {

                    // add aggregate metric
                    $formula = $cm->getFormula();
                    $row_div_by_zero = false;
                    foreach ($cm->getChildMetrics() as $metric_name) {

                        if (array_key_exists($metric_name, $row)) {
                            $row_value = $row[$metric_name]['value'];
                        } else {
                            $row_value = '';
                        }
                        if (empty($row_value) || $row_value == 0) {
                            $row_value = 0;
                            $row_div_by_zero = true;
                        }

                        $formula = str_replace($metric_name, $row_value, $formula);

                    }

                    if ( ! $row_div_by_zero ) {
                        $value = $this->evalFormula($formula);
                    } else {
                        $value = 0;
                    }

                    $rs->appendRow($k, 'metric', $cm->getName(), $value, $cm->getLabel(), $cm->getDataType(), $this->formatValue($cm->getDataType(), $value));
                }
            }
        }

        // clean up by removing child metrics before returning the result set.
        foreach ($this->childMetrics as $metric_name) {

            $rs->removeMetric($metric_name);
        }

        return $rs;
    }

    function evalFormula($formula) {

        //safety first. should only be computing numbers.
            $formula = str_replace('$','', $formula);

            // need parens and @ to handle divsion by zero errors
            $formula = '$value = ('.$formula.');';
            //print $formula;
            // calc
            @ eval($formula);

            if (!$value) {
                $value = 0;
            }

            return $value;
    }

    /**
     * Return the approraite metric implementation for the baseEntity
     * Must be called after the base entity has been determined
     *
     * @param    string    $name    the name of the metric
     *
     */
    function getMetric($name) {

        // check to see if the entity object map is loaded
        if ( ! in_array( $name, $this->metrics ) ) {
            // if not load it forthat metric
            $this->getMetricEntities($name);
        }

        return $this->metricObjectsByEntityMap[$this->baseEntity->getName()][$name];

    }

    function setQueryStringParam($name, $string) {

            $this->query_params[$name] = $string;
    }

    function getAllRelatedDimensions($entity) {

        $s = owa_coreAPI::serviceSingleton();
        $dims = array();
        $denormalized_dims = $s->denormalizedDimensions;

        foreach ( $denormalized_dims as $ddim_imp) {

            foreach ( $ddim_imp as $k => $ddim) {

                if ($k === $entity->getName()) {
                    $dims[ $ddim['family'] ][] = array( 'name' => $ddim['name'], 'label' => $ddim['label'] );
                }
            }
        }

        $normalized_dims = $s->dimensions;

        foreach ( $normalized_dims as $k => $ndim ) {

            // check to see if realation exists with dim's speficied foreign key
            $fk = $ndim['foreign_key_name'];
            if ( $fk ) {

                $col_exists = $entity->getProperty($fk);

            } else {
                // check to see if there is any foreign key to the dim's entity
                $col_exists = $entity->getForeignKeyColumn( $ndim['entity'] );
            }

            if ( $col_exists ) {
                $dims[ $ndim['family'] ][] = array( 'name' => $ndim['name'], 'label' => $ndim['label'] );
            }
        }

        return $dims;
    }

    function getAllRelatedMetrics( $entity ) {

        $related_metrics = array();
        $s = owa_coreAPI::serviceSingleton();
        $all_metrics = $s->getAllMetrics();
        $entity_name = $entity->getName();
        $s = owa_coreAPI::serviceSingleton();
        $metricsByEntity = $s->getMap('metricsByEntity');
        foreach ($all_metrics as $metric_name => $implementations) {

            foreach ($implementations as $implementation) {

                $m = owa_coreAPI::metricFactory( $implementation['class'], $implementation['params'] );

                if ( $m->isCalculated() ) {

                    $children = $m->getChildMetrics();
                    $error = false;
                    foreach( $children as $child ) {

                        if ( ! isset($metricsByEntity[$entity_name][$child])) {

                            $error = true;
                        }
                    }

                    if ( ! $error ) {

                        $related_metrics[$implementation['group']][] = array(
                            'name'             => $metric_name,
                            'label'         => $implementation['label'],
                            'description'    => $implementation['description'],
                            'group'            => $implementation['group']
                        );

                        continue;
                    }


                } else {

                    if ( $entity_name === $m->getEntityName() ) {

                        $related_metrics[$implementation['group']][] = array(
                            'name'             => $metric_name,
                            'label'         => $implementation['label'],
                            'description'    => $implementation['description'],
                            'group'            => $implementation['group']
                        );

                        continue;
                    }
                }
            }
        }

        return $related_metrics;
    }

}

?>