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

require_once(OWA_BASE_CLASS_DIR.'pagination.php');
require_once(OWA_BASE_CLASS_DIR.'timePeriod.php');
require_once(OWA_DIR.'owa_template.php');

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
 * @version		$Revision$	      
 * @since		owa 1.3.0
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
	var $page;
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
	var $errors = array();
	var $formatters = array();
	
	function __construct($db = '') {
		
		if ($db) {
			$this->db = $db;	
		} else {
			$this->db = owa_coreAPI::dbSingleton();
		}
		
		$this->formatters = array(
			//'yyyymmdd' => array($this, 'dateFormatter'),
			'timestamp'		=> array($this, 'formatSeconds'),
			'percentage' 	=> array($this, 'formatPercentage'), 
			'integer' 		=> array($this, 'numberFormatter'),
			'currency'		=> array($this, 'formatCurrency')
		);
		
		return parent::__construct();
	}
	
		
	function setConstraint($name, $value, $operator = '') {
		
		if (empty($operator)) {
			$operator = '=';
		}
		
		if (!empty($value)) {
			$this->params['constraints'][] = array('operator' => $operator, 'value' => $value, 'name' => $name);
		}
	}
	
	function setConstraints($array) {
		
		if (is_array($array)) {
			
			if (is_array($this->params['constraints'])) {
				$this->params['constraints'] = array_merge($array, $this->params['constraints']);
			} else {
				$this->params['constraints'] = $array;
			}
		}
	}
	
	function constraintsStringToArray($string) {
		
		if ($string) {
			//print_r($string);
			// add string to query params array for use in URLs.
			$this->query_params['constraints'] = $string;
			
			$constraints = explode(',', $string);
			//print_r($constraints);
			$constraint_array = array();
			
			foreach($constraints as $constraint) {
				
				foreach ($this->constraint_operators as $operator) {
					
					if (strpos($constraint, $operator)) {
						list ($name, $value) = split($operator, $constraint);
						
						$constraint_array[] = array('name' => $name, 'value' => $value, 'operator' => $operator);
					

						break;
					}
				}
			}
			//print_r($constraint_array);
			return $constraint_array;
		}
	}
	
	function getConstraints() {
	
		return $this->params['constraints'];
	}
	
	function applyConstraints() {
		
		$nconstraints = array();
		
		foreach ($this->getConstraints() as $k => $constraint) {
			
			$dim = $this->lookupDimension($constraint['name'], $this->baseEntity);
			
			//$dimEntity = owa_coreAPI::entityFactory($dim['entity']);
			
			
			$col = $dim['column'];
			$constraint['name'] = $col;
			$nconstraints[$col] = $constraint;
			$this->db->multiWhere($nconstraints);
			//print_r($nconstraints);
		
		}
		
	}
	
	
	function chooseBaseEntity() {
		
		$metric_imps = array();
		
		// load metric implementations
		foreach ($this->metrics as $metric_name) {
			
			$metric_imps = array_merge($this->getMetricEntities($metric_name), $metric_imps);
			
			
		}
		//print_r($metric_imps);
		owa_coreAPI::debug('pre-reduce set of entities to choose from: '.print_r($metric_imps, true));
		
		$entities = array();
		// reduce entities	
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
		//sort by summary level
		arsort($niceness);
		
		owa_coreAPI::debug('Entities summary levels: '.print_r($niceness, true));
		
		$entity_count = count($niceness);
		$i = 1;
		//check entities for dimension relations
		foreach ($niceness as $entity_name => $summary_level) {
			
			$error = false;
			
			//cycle through each dimension frm dim list and those found in constraints.
			$dims = array_unique(array_merge($this->dimensions, $this->getDimensionsFromConstraints()));
			
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
	
	function getDimensionsFromConstraints() {
		
		$dims = array();
		
		$constraints = $this->getConstraints();
		//print_r($constraints);
		if (!empty($constraints)) {
				
			foreach ($constraints as $carray) {
				
				$dims[] = $carray['name'];
			}
		}
		
		return $dims;
	}
		
	function isDimensionRelated($dimension_name, $entity_name) {
		
		$entity = owa_coreAPI::entityFactory($entity_name);
		
		$dimension = $this->lookupDimension($dimension_name, $entity);
		
		if ($dimension['denormalized'] === true) {
			$this->related_dimensions[$dimension['name']] = $dimension;
			owa_coreAPI::debug("Dimension: $dimension_name is denormalized into $entity_name");
			return true;
		} else {
		
			$fk = $this->getDimensionForeignKey($dimension, $entity);
			
			if ($fk) {
				owa_coreAPI::debug("Dimension: $dimension_name is related to $entity_name");
				$this->related_dimensions[$dimension['name']] = $dimension;
				return true;
			} else {
				owa_coreAPI::debug("Could not find a foreign key for $dimension_name in $entity_name");
			}
		}
	}
	
	function getMetricEntities($metric_name) {
		
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
			if (array_key_exists('foreign_key_name', $dim) && !empty($dim['foreign_key_name'])) {
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
		
	function lookupDimension($name, $entity) {
		
		// check dimensions
		if (array_key_exists($name, $this->related_dimensions)) {
			//return $this->related_dimensions[$name];
		}
		//print_r($this->metrics[0]);
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
		
		$p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
		
		$p->set($period_name, $map);
		
		$this->setPeriod($p);
		
		$start = $p->startDate->get($format);
		$end = $p->endDate->get($format);
		
		$this->setConstraint($dimension_name, array('start' => $start, 'end' => $end), 'BETWEEN');

		
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
		
	function applyMetaDataToResults($results) {
		
		$new_rows = array();
		
		foreach ($results as $row) {
			
			$new_rows[] = $this->applyMetaDataToSingleResultRow($row);
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
			
			
			
			$new_row[$k] = array(
				'result_type' => $type, 
				'name' 		  => $k, 
				'value' 	  => $v,
				'formatted_value' => $this->formatValue($data_type, $v),
				'label' => $this->getLabel($k), 'data_type' => $data_type);	
		}
		
		return $new_row;
	}
	
	function formatValue($type, $value) {
		
		if (array_key_exists($type, $this->formatters)) {
			
			$formatter = $this->formatters[$type];
			
			if (!empty($formatter)) {
				
				$value = call_user_func($formatter, $value);
			}
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
	
		return owa_lib::formatCurrency( $value, owa_coreAPI::getSetting( 'base', 'currencyLocal' ) );
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
		
	function addRelation($dim) {
			
			// if denomalized, skip
			if ($dim['denormalized'] === true) {
				return;
			}
			
			// have already determined base enttiy at this point so use that.
			$fk = $this->getDimensionForeignKey($dim, $this->baseEntity);
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
				$this->db->join(OWA_SQL_JOIN, $dimEntity->getTableName(), $alias, $fk['entity']->getTableAlias().'.'.$fk['col'], $alias.'.'.$fpk[1]);
				
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
	
	function getSelects() {
	
		if (array_key_exists('selects', $this->params)) {
			return $this->params['selects'];
		}
	}
	
	function applySelects() {
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
	 */
	function addColumn($name, $col) {
		
		$this->all_columns[$name] = $col;
	}
	
	function addError($msg) {
		
		$this->errors[] = $msg;
		owa_coreAPI::debug($msg);
	}
	
	/**
	 * Generates a result set for the metric
	 *
	 */
	function getResults() {
		
		// get paginated result set object
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		
		$bm = $this->chooseBaseEntity();
				
		if ($bm) {
			
			$bname = $bm->getName();
		
			owa_coreAPI::debug("Using $bname as base entity for making result set.");
	
			// set constraints
			$this->applyJoins();
			$this->applyConstraints();
			$this->applySelects();
		
			$this->db->selectFrom($bm->getTableName(), $bm->getTableAlias());
			// generate aggregate results
			$results = $this->db->getOneRow();
			// merge into result set
			if ($results) {
				$rs->aggregates = array_merge($this->applyMetaDataToSingleResultRow($results), $rs->aggregates);
			}
			
			// setup dimensional query
			if (!empty($this->dimensions)) {
				$this->applyJoins();
				// apply dimensional SQL
				$this->applyDimensions();
				
				$this->applySelects();
			
				$this->db->selectFrom($bm->getTableName(), $bm->getTableAlias());
				
				// pass limit to db object if one exists
				if (!empty($this->limit)) {
					$rs->setLimit($this->limit);
				}
				// pass limit to db object if one exists
				if (!empty($this->page)) {
					$rs->setPage($this->page);
				}
				
				$this->applyConstraints();
				
				if (array_key_exists('orderby', $this->params)) {
					$sorts = $this->params['orderby'];
					// apply sort by
					if ($sorts) {
						foreach ($sorts as $sort) {
							$this->db->orderBy($sort[0], $sort[1]);
							$rs->sortColumn = $sort[0];
							if (isset($sort[1])){
								$rs->sortOrder = strtolower($sort[1]);
							} else {
								$rs->sortOrder = 'asc';
							}
						}
					}
				}				
				
				// add labels
				$rs->setLabels($this->getLabels());	
			
				// generate dimensonal results
				$results = $rs->generate($this->db);
				
				$rs->resultsRows = $this->applyMetaDataToResults($results);
			}
			
			// add labels
			$rs->setLabels($this->getLabels());
			
			// add period info
			
			$rs->setPeriodInfo($this->params['period']->getAllInfo());
			
			$rs = $this->computeCalculatedMetrics($rs);
			
			// add urls
			$urls = $this->makeResultSetUrls();
			$rs->self = $urls['self'];
			
			if ($rs->more) {
			
				$rs->next = $urls['next'];
			}
			
			if ($this->page >=2) {
				$rs->previous = $urls['previous'];
			}
			
			$rs->createResultSetHash();
		}
		
		$rs->errors = $this->errors;
				
		return $rs;
	}
	
	function computeCalculatedMetrics($rs) {
		
		foreach ($this->calculatedMetrics as $cm) {
			
			// add aggregate metric
			$formula = $cm->getFormula();
			$div_by_zero = false;
			
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
			
			foreach ($this->childMetrics as $metric_name) {
				
				$rs->removeMetric($metric_name);
			}
			
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
	
	function getMetric($name) {
		
		if (in_array($name, $this->metrics)) {
			return $this->metricObjectsByEntityMap[$this->baseEntity->getName()][$name];
		} 
	}
	
	function setQueryStringParam($name, $string) {
		
			$this->query_params[$name] = $string;
	}
	
	function makeResultSetUrls() {
		
		$urls = array();
		// get api url
		$api_url = owa_coreAPI::getSetting('base', 'api_url');
		// get base query params
		$query_params = $this->query_params;
		// add api command
		$query_params['do'] = 'getResultSet';
		//add format
		if ($this->format) {
			$query_params['format'] = $this->format;
		} else {
			$query_params['format'] = 'json';
		}
		// add current page if any
		if ($this->page) {
			$query_params['page'] = $this->page;
		}
		// add limit
		if ($this->limit) {
			$query_params['resultsPerPage'] = $this->limit;
		}
		
		// build url for this result set
		$link_template = owa_coreAPI::getSetting('base', 'link_template');
		$q = $this->buildQueryString($query_params);
		$urls['self'] = sprintf($link_template, $api_url, $q);
		
		// build url for next page of result set
		$next_query_params = $query_params;
		if ($this->page) {
			$next_query_params['page'] = $query_params['page'] + 1;
		} else {
			$next_query_params['page'] = 2;
		} 
		
		$nq = $this->buildQueryString($next_query_params);
		$urls['next'] = sprintf($link_template, $api_url, $nq);
		
		// build previous url if page is greater than 2	
		if ($this->page >= 2) {
			$previous_query_params = $query_params;
			$previous_query_params['page'] = $query_params['page'] - 1;
			$pq = $this->buildQueryString($previous_query_params);
			$urls['previous'] = sprintf($link_template, $api_url, $pq);
		}
		
		$base_query_params = $this->query_params;
		$base_query_params['format'] = $this->format;
		
		// build pagination url template for use in constructing 
		$q = $this->buildQueryString($base_query_params);
		$url['base_url'] = sprintf($link_template, $api_url, $q);
		
		return $urls;
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

}

?>