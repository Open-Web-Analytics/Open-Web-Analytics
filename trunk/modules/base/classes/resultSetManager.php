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
	
	function __construct($db = '') {
		
		if ($db) {
			$this->db = $db;	
		} else {
			$this->db = owa_coreAPI::dbSingleton();
		}
		
		return parent::__construct();
	}
	
		
	function setConstraint($name, $value, $operator = '') {
		
		if (empty($operator)) {
			$operator = '=';
		}
		
		if (!empty($value)) {
			$this->params['constraints'][$name] = array('operator' => $operator, 'value' => $value, 'name' => $name);
		}
	}
	
	// remove
	function setConstraints($array) {
	
		if (is_array($array)) {
			
			if (is_array($this->params['constraints'])) {
				$this->params['constraints'] = array_merge($array, $this->params['constraints']);
			} else {
				$this->params['constraints'] = $array;
			}
		}
	}
	
	function getDimensionForeignKey($dim) {
		
		if ($dim) {
			$bm = $this->getBaseMetric();
			// check for foreign key column by name if dimension specifies one
			if (array_key_exists('foreign_key_name', $dim) && !empty($dim['foreign_key_name'])) {
				// get foreign key col by 
				$fk = $bm->entity->isForeignKey($dim['foreign_key_name']);
			} else {
				// if not check for foreign key by entity name
			    //check to see if the metric's entity has a foreign key to the dimenesion table.
				$fk = $bm->entity->getForeignKeyColumn($dim['entity']);
			}

			return $fk;
		}
	}
	
	function constraintsStringToArray($string) {
		
		if ($string) {
			
			// add string to query params array for use in URLs.
			$this->query_params['constraints'] = $string;
			
			$constraints = explode(',', $string);
		
			$constraint_array = array();
			
			foreach($constraints as $constraint) {
				
				foreach ($this->constraint_operators as $operator) {
					
					if (strpos($constraint, $operator)) {
						list ($name, $value) = split($operator, $constraint);
						
						$dim = $this->lookupDimension($name);
						//print_r($dim);
						$constraint_array[$dim['column']] = array('name' => $dim['column'], 'value' => $value, 'operator' => $operator);
						//print_r($constraint_array);
						break;
					}
				}
			}
			
			return $constraint_array;
		}
	}
	
	function getConstraints() {
	
		return $this->params['constraints'];
	}
	
		
	function lookupDimension($name) {
		
		// check dimensions
		if (array_key_exists($name, $this->dimensions)) {
			return $this->dimensions[$name];
		}
		//print_r($this->metrics[0]);
		// check for denormalized 
		$bm = $this->getBaseMetric();
		$service = owa_coreAPI::serviceSingleton();
		$dim = $service->getDenormalizedDimension($name, $bm->entity->getName());
		
		if ($dim) {
			//apply table aliasing to dimension column
			$dim['column'] = $bm->entity->getTableAlias().'.'.$dim['column'];
		} else {
		
			// check for normalized dim
			if (array_key_exists($name, $this->related_dimensions)) {
				$dim = $this->related_dimensions[$name];
			} else {
				
				$dim = $service->getDimension($name);
				
				if ($dim) {
				
					$fk = $this->getDimensionForeignKey($dim);
					
					// if a foreign 
					if ($fk) {
						// create dimension entity
						$dimEntity = owa_coreAPI::entityFactory($dim['entity']);
						$dim['column'] = $dimEntity->getTableAlias().'.'.$dim['column'];
				
						// add to local dim map
						$this->related_dimensions[$name] = $dim;
					} else {
						$this->addError("The dimension $name cannot be combined with these metrics.");
					}
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
				$col_name = $column;
				if ($col_name) {
					$sort_array[$sort][0] = $col_name;
					$sort_array[$sort][1] = $order;
					return $sort_array;
				} else {
					$this->addError("$column is not a valid column to sort on");
				}		
			}
		}
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
		
		if ($startDate && $endDate) {
			$period_name = 'date_range';
			$map = array('startDate' => $startDate, 'endDate' => $endDate);
			$col = 'yyyymmdd';
		} elseif ($startTime && $endTime) {
			$period_name = 'time_range';
			$map = array('startTime' => $startTime, 'endTime' => $endTime);
			$col = 'timestamp';
		} else {
			owa_coreAPI::debug('no period params passed to owa_metric::setTimePeriod');
			$col = 'timestamp';
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
		
		$bm = $this->getBaseMetric();
		$start = $p->startDate->get($col);
		$end = $p->endDate->get($col);
		$col = $bm->entity->getTableAlias().'.'.$col;
		
		$this->setConstraint($col, array('start' => $start, 'end' => $end), 'BETWEEN');
		
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
				
			if (array_key_exists($k, $this->dimensions)) {
				$type = 'dimension';
				$data_type =$this->dimensions[$k]['data_type'];
			} elseif (array_key_exists($k, $this->metrics)){
				$type = 'metric';
				$data_type = $this->getMetric($k)->getDataType();
			}
			
			$new_row[$k] = array('result_type' => $type, 'name' => $k, 'value' => $v, 'label' => $this->getLabel($k), 'data_type' => $data_type);	
		}
		
		return $new_row;
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
				
		return $this->labels[$key];
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
			
			$dim = $this->lookupDimension($name);
			
			if ($dim) {
				// add to dimension map
				$this->dimensions[$dim['name']] = $dim;
				// add label
				$this->addLabel($dim['name'], $dim['label']);
			} else {
				
				$this->addError("$name is not a registered dimension");
			}
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
		
		foreach ($this->dimensions as $dim) {
							
			// add column name to select statement
			$this->db->selectColumn($dim['column'], $dim['name']);
			// add groupby
			$this->db->groupBy($dim['column']);
		}
	}
	
	function addFactTableRelation($metric) {
		
		$bm = $this->getBaseMetric();
		if ($metric->entity->getTableName() != $bm->entity->getTableName()) {
	
			if (!in_array($metric->getEntityName(), $this->related_entities)) {
				$fk = $bm->entity->getForeignKeyColumn($metric->getEntityName());
				
				if ($fk) {
					$fpk_col = $bm->entity->getProperty($fk);
					$fpk = $fpk_col->getForeignKey();
					$this->db->join(OWA_SQL_JOIN, $metric->entity->getTableName(), $metric->entity->getTableAlias(), $bm->entity->getTableAlias().'.'.$fk, $metric->entity->getTableAlias().'.'.$fpk[1]);
				} else {
					// try the other way
					$fk = $metric->entity->getForeignKeyColumn($bm->getEntityName());
					
					if ($fk) {
						$fpk_col = $metric->entity->getProperty($fk);
						$fpk = $fpk_col->getForeignKey();
						$this->db->join(OWA_SQL_JOIN, $metric->entity->getTableName(), $metric->entity->getTableAlias(), $metric->entity->getTableAlias().'.'.$fk, $bm->entity->getTableAlias().'.'.$fpk[1]);
						
					} else {
						$this->addError(sprintf('Cannot find relation betwwen  %s or %s', $metric->getEntityName(), $bm->getEntityName()));
					// no fact table realtion found
					}
				}
			}
		}
	}
	
	function checkForFactTableRelation($metric) {
		
		if (!empty($this->related_metrics)) {
			// foreach related metric
			foreach ($this->related_metrics as $rmetric) {
				// check for foreign key
				$fk = $rmetric->entity->getForeignKeyColumn($metric->getEntityName());
				
				if ($fk) {
					return true;
				} else {
					// try the other way
					$fk = $metric->entity->getForeignKeyColumn($rmetric->getEntityName());
					
					if ($fk) {
						return true;						
					} else {
						$this->addError(sprintf('Cannot find relation between  %s or %s', $metric->getEntityName(), $bm->getEntityName()));
					// no fact table realtion found
					}
				}
			}
			
		} else {
			return true;
		}
	}
	
	function applyJoins() {
			
		foreach($this->related_dimensions as $dim) {
		
				$this->addRelation($dim);
		}
		
		foreach($this->metrics as $metric) {
		
				$this->addFactTableRelation($metric);
		}
		
	}
	
	function getBaseMetric() {
		$keys = array_keys($this->metrics);
		return $this->metrics[$keys[0]];	
	}
	
	function addRelation($dim) {
		
		if (!in_array($dim['entity'], $this->related_entities)) {
			
			$fk = $this->getDimensionForeignKey($dim);
			//print_r($dim);
			//print $fk;
			if ($fk) {
				// create dimension entity
				$dimEntity = owa_coreAPI::entityFactory($dim['entity']);
				// get foreign key column
				$bm = $this->getBaseMetric();
				$fpk_col = $bm->entity->getProperty($fk);
				$fpk = $fpk_col->getForeignKey();
				// add join
				//print_r($fpk);	
				$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, $dimEntity->getTableName(), $dimEntity->getTableAlias(), $bm->entity->getTableAlias().'.'.$fk, $dimEntity->getTableAlias().'.'.$fpk[1]);
				//$this->related_entities[] = $dim['entity'];
				$this->addColumn($dim['name'], $dimEntity->getTableAlias().'.'.$dim['column']);
			} else {
				// add error result set
				owa_coreAPI::debug(sprintf('%s metric does not have relation to dimension %s', $bm->getName(), $dim['name'])); 
			}
		}
	}
	
	/**
	 * Sets a denormalized dimension
	 *
	 * Denormalized dimensions are looked up in this map by key
	 * and always begin with "denorm" (e.g. "denorm.key")
	 */
	function setDenormalizedDimension($name, $column) {
	
		$bm = $this->getBaseMetric();
		$this->denormalizedDimensions[$name] = array('name' => $name, 'entity' => $bm->entity->getName(), 'column' => $column);
		
	}
	
	function getDenormalizedDimension($name) {
	
		if (array_key_exists($name, $this->denormalizedDimensions)) {
			return $this->denormalizedDimensions[$name];
		}
	}
	
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
		
		foreach ($calc_metric_obj->getChildMetrics() as $metric_name) {
			
			$ret = $this->addMetric($metric_name, true);
			
			if ($ret) {
				// add child metrics to child metric maps
				// check to see if it wasn't already added as a non-child metric.
				//if (!array_key_exists($metric_name, $this->metrics)){
				//	$this->childMetrics[$metric_name] = $metric_name;
				//}
			} else {
				$error = true;
			}
			
		}
		
		if (!$error) {
			// add label of calculated metric obj
			$this->addLabel($calc_metric_obj->getName(),$calc_metric_obj->getLabel());
			// add to calculated metric map
			$this->calculatedMetrics[$calc_metric_obj->getName()] = $calc_metric_obj; 
		}
	}
	
	function addSelect($select_array) {
	
		$this->params['selects'][] = $select_array; 
	}
	
	function getSelects() {
	
		if (array_key_exists('selects', $this->params)) {
			return $this->params['selects'];
		}
	}
	
	// passes all select arrays to the db layer
	function applySelects() {
		
		$selects = $this->getSelects();
		
		if (!empty($selects)) {
		
			foreach ($selects as $select) {
				$this->db->selectColumn($select[0], $select[1]);
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
		
		$bm = $this->getBaseMetric();
		// set constraints
		$this->applyJoins();
		$this->db->multiWhere($this->getConstraints());
		$this->applySelects();
	
		$this->db->selectFrom($bm->entity->getTableName(), $bm->entity->getTableAlias());
		// get paginated result set object
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		// generate aggregate results
		$results = $this->db->getOneRow();
		// merge into result set
		if ($results) {
			$rs->aggregates = array_merge($this->applyMetaDataToSingleResultRow($results), $rs->aggregates);
		}
		
		// setup dimensional query
		if (!empty($this->dimensions)) {
			
			// apply dimensional SQL
			$this->applyDimensions();
			
			$this->applySelects();
		
			$this->db->selectFrom($bm->entity->getTableName(), $bm->entity->getTableAlias());
			
			// pass limit to db object if one exists
			if (!empty($this->limit)) {
				$rs->setLimit($this->limit);
			}
			// pass limit to db object if one exists
			if (!empty($this->page)) {
				$rs->setPage($this->page);
			}
			
			$this->applyJoins();
			$this->db->multiWhere($this->getConstraints());
			
			$sorts = $this->params['orderby'];
			// apply sort by
			if ($sorts) {
				foreach ($sorts as $sort) {
					$this->db->orderBy($sort[0], $sort[1]);
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
		
		return $rs;
	}
	
	function computeCalculatedMetrics($rs) {
		
		foreach ($this->calculatedMetrics as $cm) {
			
			// add aggregate metric
			$formula = $cm->getFormula();
			
			foreach ($cm->getChildMetrics() as $metric_name) {
				
				$ag_value = $rs->getAggregateMetric($metric_name);
				
				if (empty($ag_value)) {
					$ag_value = 0;
				}
				
				$formula = str_replace($metric_name, $ag_value, $formula);
			}
			
			$value = $this->evalFormula($formula);
			
			$rs->setAggregateMetric($cm->getName(), $value, $cm->getLabel(), $cm->getDataType());
			
			// add dimensional metric
			
			if ($rs->getRowCount() > 0) {
				
				foreach ($rs->resultsRows as $k => $row) {
					
					// add aggregate metric
					$formula = $cm->getFormula();
						
					foreach ($cm->getChildMetrics() as $metric_name) {
						
						$row_value = $row[$metric_name]['value'];
						
						if (empty($row_value)) {
							$row_value = 0;
						}
					
						$formula = str_replace($metric_name, $row_value, $formula);	
					
					}
					
					$value = $this->evalFormula($formula);
				
					$rs->appendRow($k, 'metric', $cm->getName(), $value, $cm->getLabel(), $cm->getDataType());
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
			$formula = '$value = @('.$formula.');';
			//print $formula;
			// calc
			eval($formula);
			
			if (!$value) {
				$value = 0;
			}
			
			return $value;
	}
	
	function getMetric($name) {
		
		if (array_key_exists($name, $this->metrics)) {
			return $this->metrics[$name];
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
		$q = $this->buildQueryString($query_params);
		$urls['self'] = sprintf("%s?%s", $api_url, $q);
		
		// build url for next page of result set
		$next_query_params = $query_params;
		if ($this->page) {
			$next_query_params['page'] = $query_params['page'] + 1;
		} else {
			$next_query_params['page'] = 2;
		} 
		
		$nq = $this->buildQueryString($next_query_params);
		$urls['next'] = sprintf("%s?%s", $api_url, $nq);
		
		// build previous url if page is greater than 2	
		if ($this->page >= 2) {
			$previous_query_params = $query_params;
			$previous_query_params['page'] = $query_params['page'] - 1;
			$pq = $this->buildQueryString($previous_query_params);
			$urls['previous'] = sprintf("%s?%s", $api_url, $pq);
		}
		
		$base_query_params = $this->query_params;
		$base_query_params['format'] = $this->format;
		
		// build pagination url template for use in constructing 
		$q = $this->buildQueryString($base_query_params);
		$url['base_url'] = sprintf("%s?%s", $api_url, $q);
		
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