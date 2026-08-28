<?php
namespace OWA\Module\Base\Classes;


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

class ResultSetManager extends \OWA\Core\Base {

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

    /**
     * Errors that mean the REQUEST is malformed, as opposed to noise.
     *
     * Kept apart from $errors because most entries there are routine: a
     * denormalized dimension such as productName resolves only against certain
     * entities, so lookupDimension() records "not a registered dimension" during
     * perfectly ordinary product reports. Measured -- it fires twice in a clean
     * run of the suite with no bad input anywhere.
     *
     * So "any error" cannot gate the query; only these can.
     *
     * @var array
     */
    var $request_errors = array();
    var $formatters = array();
    var $segment;
    var $pagination;
    var $resolution = 'day';
    var $timePeriod;
    var $all_columns = [];
    

    function __construct($db = '') {

        if ($db) {
            $this->db = $db;
        } else {
            $this->db = \OWA\Core\CoreAPI::dbSingleton();
        }

        $this->formatters = array(
            //'yyyymmdd' => array($this, 'dateFormatter'),
            'timestamp'        => array($this, 'formatSeconds'),
            'percentage'     => array($this, 'formatPercentage'),
            'integer'         => array($this, 'numberFormatter'),
            'boolean'         => array($this, 'booleanFormatter'),
            'currency'        => array($this, 'formatCurrency')
        );
        
        $this->resultSet = \OWA\Core\CoreAPI::supportClassFactory('base', 'paginatedResultSet');

        return parent::__construct();
    }


    function setConstraint($name, $value, $operator = '') {

        if (empty($operator)) {
            $operator = '=';
        }

        if ( ! \OWA\Core\Lib::isEmpty( $value ) ) {
            $this->params['constraints'][$name] = array('operator' => $operator, 'value' => $value, 'name' => $name);
        }
    }

    /**
     * Apply a set of constraints a CALLER asked for.
     *
     * Unlike setConstraint(), an empty value here is an error rather than a
     * no-op. setConstraint() drops empty values silently, which is right for the
     * internal calls that pass an optional value -- but wrong for constraints
     * that arrived on a request, because there "no value" means the value went
     * missing, not that the caller wanted everything.
     *
     * Silently dropping them made a lost parameter indistinguishable from an
     * unfiltered request: "source==" produced the full unfiltered total, and the
     * Source Detail report showed the same visit count for every source with no
     * error anywhere. Reported the same way an unknown sort column is.
     */
    function setConstraints($array) {

        if (is_array($array)) {

            if ( ! isset($this->params['constraints']) ) {
                $this->params['constraints'] = array();
            }

            foreach ($array as $constraint) {

                if ( \OWA\Core\Lib::isEmpty( $constraint['value'] ) ) {

                    $this->addRequestError( sprintf(
                        'The "%s" constraint was given no value. Refusing to run the '
                        . 'query unconstrained -- a missing value is not a request for '
                        . 'everything.',
                        $constraint['name']
                    ) );

                    continue;
                }

                /*
                 * A name that resolves to nothing is refused for the same
                 * reason, and reported here rather than where the constraint is
                 * applied: applyConstraint() runs once for the result set and
                 * again for the aggregates, so the same misspelling would be
                 * reported twice.
                 *
                 * It used to fall through applyConstraint() doing nothing, so
                 * the query ran WITHOUT that filter and answered with the
                 * unconstrained total -- the identical failure the check above
                 * exists to stop, differing only in cause: a misspelled name
                 * rather than a lost value. Measured before this,
                 * `bogusDimension==direct` returned exactly what no constraint
                 * at all returned, while a valid name matching nothing
                 * correctly returned none.
                 */
                if ( ! $this->isDimension( $constraint['name'] )
                     && ! $this->isMetric( $constraint['name'] ) ) {

                    $this->addRequestError( sprintf(
                        '"%s" is not a dimension or a metric, so it cannot be constrained '
                        . 'on. Refusing to run the query unconstrained -- an unknown name '
                        . 'is not a request for everything.',
                        $constraint['name']
                    ) );

                    continue;
                }

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

        // A constraint with NO VALUE is refused, not quietly ignored.
        //
        // Db::where() skips a constraint whose value isEmpty(), so an empty one
        // used to mean "no filter" -- the query ran unconstrained and returned
        // the full total with no error anywhere. That is the worst possible
        // default for reporting: a lost parameter is indistinguishable from a
        // request for everything.
        //
        // It is not hypothetical. "source==" reached this method whenever
        // owa_source went missing from the request -- a cache layer was found
        // stripping it -- and the Source Detail report happily showed the same
        // unfiltered visit count for every source.
        //
        // Refused the same way an unknown sort column already is, so the caller
        // sees an error instead of plausible wrong numbers.
        if ( \OWA\Core\Lib::isEmpty( $constraint['value'] ) ) {

            $this->addError( sprintf(
                '%s constraint was given no value. Refusing to run the query '
                . 'unconstrained -- a missing value is not a request for everything.',
                $constraint['name']
            ) );

            return;
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

        \OWA\Core\CoreAPI::debug('pre-reduce set of entities to choose from: '.print_r($metric_imps, true));

        $entities = array();

        // reduce metric entities. this will give us the fact tables to choose from.
        $reconciled = array();

        foreach ($metric_imps as $metric_name => $mimp) {

            if (empty($entities)) {
                $entities = $mimp;
            }

            $entities = $this->reduceTables($mimp, $entities);

            if (empty($entities)) {

                /*
                 * A REQUEST error, and it names the metric that broke the set.
                 *
                 * Every metric can be computed from one or more fact tables,
                 * and a query is answered from ONE of them -- so a combination
                 * is only askable if the metrics share a table. `domClicks`
                 * comes from the click table alone; `visits` from the session
                 * or the request; there is no table that has both, so asking
                 * for them together is not a thin result, it is not a question.
                 *
                 * It used to be addError(), which puts it with the routine
                 * misses that reports swallow -- so an impossible set came back
                 * as an empty or nonsensical report with nothing said. A
                 * request error is refused and explained, like an unknown
                 * constraint name.
                 */
                return $this->addRequestError( self::incompatibleMessage(
                    $metric_name, $reconciled, 'metric' ) );
            }

            $reconciled[] = $metric_name;
        }

        \OWA\Core\CoreAPI::debug('post-reduce set of entities to choose from: '.print_r($entities, true));

        // check summary level of entities
        $niceness = array();

        foreach ($entities as $entity) {

            $niceness[$entity] = \OWA\Core\CoreAPI::entityFactory($entity)->getSummaryLevel();
        }

        // sort the fact table list by summary level
        arsort($niceness);

        \OWA\Core\CoreAPI::debug('Entities summary levels: '.print_r($niceness, true));

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
                        \OWA\Core\CoreAPI::debug("Segment dimension $dimension is not related to $entity_name. Moving on to next entity...");
                        break;
                    } else {
                        // set related dimensions. this is needed for joins.
                        \OWA\Core\CoreAPI::debug("Segment Dimension: $segment_dim is related to $entity_name.");
                    }
                }
            }

            //cycle through each dimension from dim list and those found in constraints.
            $dims = array_unique( array_merge( $this->dimensions, $this->getDimensionsFromConstraints() ) );

            \OWA\Core\CoreAPI::debug(sprintf('Dimensions: %s',print_r($this->dimensions, true)));

            \OWA\Core\CoreAPI::debug(sprintf('Checking the following dimensions for relation to %s: %s',$entity_name, print_r($dims, true)));

            foreach ($dims as $dimension) {

                $check = $this->isDimensionRelated($dimension, $entity_name);

                // is the realtionship check fails then move onto the next entity.
                if (!$check) {
                    $error = true;
                    \OWA\Core\CoreAPI::debug("$dimension is not related to $entity_name. Moving on to next entity...");
                    break;
                } else {
                    // set related dimensions. this is needed for joins.
                    $dim_array = $this->getDimensionByEntityName($dimension, $entity_name);

                    $this->setRelatedDimension( $dim_array );
                    \OWA\Core\CoreAPI::debug("Dimension: $dimension is related to $entity_name.");
                }
            }

            // is no error then everythig is related and we are good to go.
            if (!$error) {
                \OWA\Core\CoreAPI::debug('optimal base entity is: '.$entity_name);
                $this->baseEntity = \OWA\Core\CoreAPI::entityFactory($entity_name);
                return $this->baseEntity;
            }

            if ($i === $entity_count) {

                /*
                 * Same shape as the metric case above, and the same reason for
                 * being a REQUEST error: the base entity has to satisfy every
                 * metric AND every dimension, and constraints contribute their
                 * dimensions to this list too. When no fact table is related to
                 * all of them the question cannot be answered -- and answering
                 * it with an empty grid and a debug line meant the reader saw a
                 * report that looked merely uneventful.
                 */
                $this->addRequestError( self::incompatibleMessage(
                    $dimension,
                    array_merge( array_keys( (array) $metric_imps ),
                        array_diff( $dims, array( $dimension ) ) ),
                    'dimension' ) );
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

        $entity = \OWA\Core\CoreAPI::entityFactory($entity_name);

        $dimension = $this->lookupDimension($dimension_name, $entity);

        // lookupDimension() returns null when the name does not resolve AGAINST
        // THIS ENTITY. That is a routine outcome, not an error case: it looks
        // for a denormalized dimension on this entity, then the
        // related_dimensions cache, then the global (non-denormalized)
        // registry. A denormalized dimension such as productName lives only
        // under its own entity (base.commerce_line_item_fact), so checking it
        // against base.request finds nothing in any of the three.
        //
        // And the callers do exactly that on purpose -- they loop every
        // requested dimension against every candidate entity looking for one
        // that fits them all, so most pairings are expected to miss. Without
        // this guard each of those misses fell through to the array access
        // below and logged "Trying to access array offset on value of type
        // null", which is why the warning appeared on ordinary report
        // requests rather than only on bad input.
        //
        // Not related is the correct answer here, and it is what the callers
        // already assumed -- they test `if (!$check)`, which treated the
        // implicit null the same way. Returning false makes the contract match
        // the method name.
        //
        // Strictly this branch is redundant: the isset() below already stops
        // the warning and the trailing return already yields false, and a
        // mutation test confirms removing it changes nothing observable. It
        // stays for the DEBUG LOG. Without it an unregistered name falls
        // through to "Could not find a foreign key for productName in
        // base.request", which sends the reader looking for a missing foreign
        // key when the real problem is that the dimension does not exist.
        if ( ! $dimension ) {
            \OWA\Core\CoreAPI::debug("Dimension: $dimension_name did not resolve, so it is not related to $entity_name");
            return false;
        }

        // isset() guards a dimension array that has no 'denormalized' key at
        // all. The strict === true is kept deliberately: only a real boolean
        // true counts, exactly as before.
        if ( isset( $dimension['denormalized'] ) && $dimension['denormalized'] === true ) {
            //$this->related_dimensions[$dimension['name']] = $dimension;
            \OWA\Core\CoreAPI::debug("Dimension: $dimension_name is denormalized into $entity_name");
            return true;
        } else {

            $fk = $this->getDimensionForeignKey($dimension, $entity);

            if ($fk) {
                \OWA\Core\CoreAPI::debug("Dimension: $dimension_name is related to $entity_name");
                //$this->related_dimensions[$dimension['name']] = $dimension;
                return true;
            } else {
                \OWA\Core\CoreAPI::debug("Could not find a foreign key for $dimension_name in $entity_name");
            }
        }

        // Was an implicit null before. Every caller tests the result for
        // truthiness, so this changes no behaviour -- it just stops a method
        // named is...() from answering a question with null.
        return false;
    }

    function getMetricEntities($metric_name) {
        \OWA\Core\CoreAPI::debug("getting metric entities for $metric_name");

        //get the class implementations
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $classes = $s->getMetricClasses($metric_name);

        $entities = array();

        // cycles through metric classes and get their entity names
        foreach ($classes as $name => $map) {
            $m = \OWA\Core\CoreAPI::metricFactory($map['class'], $map['params']);

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

    /**
     * Why a field cannot join the ones already reconciled.
     *
     * NAMES BOTH SIDES. "This combination cannot be queried" tells a reader
     * nothing they can act on; "domClicks cannot be combined with visits,
     * uniqueVisitors" tells them which one to take out. The offender is the
     * field being added when the set of possible fact tables became empty, and
     * the others are what it has to be compatible with.
     *
     * @param string $offender
     * @param array  $with fields already reconciled
     * @param string $kind metric|dimension
     * @return string
     */
    public static function incompatibleMessage( $offender, array $with, $kind ) {

        $with = array_values( array_filter( array_unique( $with ) ) );

        if ( ! $with ) {

            return sprintf( '"%s" cannot be queried here.', $offender );
        }

        return sprintf(
            'Illegal combination: the %s "%s" cannot be combined with %s. Each of these is '
          . 'recorded in a different place, and one query is answered from one fact table -- '
          . 'so no query can return them together. Remove one side or put them in separate '
          . 'widgets.',
            $kind,
            $offender,
            '"' . implode( '", "', $with ) . '"' );
    }

    /**
     * The first field that makes a combination impossible, and what it clashes
     * with -- or null when the combination is fine.
     *
     * Fields are added in order, so the offender is the one being added when
     * the possible fact tables run out. That is the same order the query engine
     * reduces in, which is what makes the answer match what would actually
     * happen.
     *
     * @param array $metrics
     * @param array $dimensions
     * @return array|null {name, with, kind}
     */
    public function firstIncompatible( array $metrics, array $dimensions = array() ) {

        $seen = array();

        foreach ( array( 'metric' => $metrics, 'dimension' => $dimensions ) as $kind => $fields ) {

            foreach ( $fields as $field ) {

                $field = trim( (string) $field );

                if ( $field === '' ) {

                    continue;
                }

                $candidate = $seen;
                $candidate[ $kind ][] = $field;

                $ok = $this->compatibleEntities(
                    isset( $candidate['metric'] ) ? $candidate['metric'] : array(),
                    isset( $candidate['dimension'] ) ? $candidate['dimension'] : array() );

                if ( ! $ok ) {

                    return array(
                        'name' => $field,
                        'kind' => $kind,
                        'with' => array_merge(
                            isset( $seen['metric'] ) ? $seen['metric'] : array(),
                            isset( $seen['dimension'] ) ? $seen['dimension'] : array() ),
                    );
                }

                $seen = $candidate;
            }
        }

        return null;
    }

    /**
     * The fact tables that could answer for ALL of these metrics at once.
     *
     * Empty means the combination cannot be queried -- there is no one table
     * holding them, so no single query can return them together.
     *
     * The same reduction getResults() performs, exposed so it can be ASKED
     * rather than discovered by running a query and getting nothing. The
     * custom report builder uses it twice: to refuse an impossible set at save
     * time, and to stop offering metrics that would make one.
     *
     * Dimensions narrow it further, because the base entity has to be related
     * to every one of them as well -- which is the second reduction
     * getResults() performs, and the reason a dimension can be as impossible as
     * a metric. Constraint dimensions belong in the same list: the engine folds
     * them in with getDimensionsFromConstraints().
     *
     * @param array $names metric names
     * @param array $dimensions dimension names, including any from constraints
     * @return array entity names, empty when the combination is illegal
     */
    public function compatibleEntities( array $names, array $dimensions = array() ) {

        $imps = array();

        foreach ( $names as $name ) {

            $name = trim( (string) $name );

            if ( $name === '' ) {

                continue;
            }

            $imps = array_merge( $this->getMetricEntities( $name ), $imps );
        }

        $entities = array();

        foreach ( $imps as $mimp ) {

            if ( empty( $entities ) ) {

                $entities = $mimp;
            }

            $entities = $this->reduceTables( $mimp, $entities );

            if ( empty( $entities ) ) {

                return array();
            }
        }

        $entities = array_values( array_unique( $entities ) );

        foreach ( $dimensions as $dimension ) {

            $dimension = trim( (string) $dimension );

            if ( $dimension === '' ) {

                continue;
            }

            $entities = array_values( array_filter( $entities,
                function ( $entity ) use ( $dimension ) {

                    return (bool) $this->isDimensionRelated( $dimension, $entity );
                } ) );

            if ( empty( $entities ) ) {

                return array();
            }
        }

        return $entities;
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
                \OWA\Core\CoreAPI::debug("Foreign Key check: ". print_r($fkcol, true));
                if ($fkcol) {
                    $fk['col'] = $fkcol;
                    $fk['entity'] = $entity;
                }
            }

            return $fk;
        }
    }

    function isDimension( $name ) {

        $dims = \OWA\Core\CoreAPI::getAllDimensions();
        //print_r($dims);
        return in_array( $name, array_keys( $dims ) );
    }

    function isMetric( $name ) {

        $metrics = \OWA\Core\CoreAPI::getAllMetrics();
        return in_array( $name, array_keys( $metrics ) );
    }

    function getDimensionByEntityName($dim_name, $entity_name) {

        $entity = \OWA\Core\CoreAPI::entityFactory($entity_name);
        return $this->lookupDimension($dim_name, $entity);
    }

    /**
     * Retrieves dimension given a name and associated fact table entity.
     *
     * @param $name string the name of the dimension
     * @param $entity    object    the entity object
     * @return array
     */
    function lookupDimension($name, $entity) {

        // check for denormalized
        $service = \OWA\Core\CoreAPI::serviceSingleton();
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
                    $dimEntity = \OWA\Core\CoreAPI::entityFactory($dim['entity']);
                    // alias needs to use fk name in case there are two joins on the
                    // same table. This is also used in addRelation method
                    $alias = $dimEntity->getTableAlias().'_via_'.$dim['foreign_key_name'];
                    //$dim['column'] = $dimEntity->getTableAlias().'.'.$dim['column'];
                    $dim['column'] = $alias.'.'.$dim['column'];
                } else {
                    $msg = "$name is not a registered dimension.";
                    \OWA\Core\CoreAPI::debug($msg);
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

                $sort_col = null;

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

                        // now replace the names with select statements.
                        foreach ($child_metrics as $child) {
                            $child_metric = $this->getMetricImplementation( $child );
                            $select = $child_metric->getSelect();
                            $formula = str_replace('__'.$child, $select[0], $formula);
                        }

                        $sort_col = $formula;
                    } else {

                        $select = $sort_metric->getSelect();
                        $sort_col = $select[0];
                    }
                } elseif ( $this->isDimension( $sort[0] ) ) {

                    $dim = $this->lookupDimension( $sort[0], $this->baseEntity );
                    if ( ! empty( $dim['column'] ) ) {
                        $sort_col = $dim['column'];
                    }
                }

                if ( $sort_col === null ) {
                    $this->addError( $sort[0] . " is not a valid column to sort on" );
                    continue;
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
    
    function setTimeResolution( $value ) {
      
      $map = [
        'day',
        'month',
        'year'
      ];
      
      if ( in_array( $value, $map ) ) {
        
        $this->resolution = $value;
      }
    }
    
    function getTimeResolution() {
      
      return $this->resolution;
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
      
          \OWA\Core\CoreAPI::debug('no start/end params passed to owa_metric::setTimePeriod');
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

        // create timePeriod class
        $this->timePeriod = \OWA\Core\CoreAPI::supportClassFactory('base', 'timePeriod');

        $this->timePeriod->set($period_name, $map);
        
        // needed?
        $this->setPeriod($this->timePeriod);

        $start = $this->timePeriod->startDate->get($format);
        $end = $this->timePeriod->endDate->get($format);
      
        // set time period constraint for query
        $this->setConstraint($dimension_name, array('start' => $start, 'end' => $end), 'BETWEEN');

        // A TIME bound also gets a closed DATE bound, derived here.
        //
        // The fact tables are RANGE-partitioned on yyyymmdd, so a predicate that
        // names only `timestamp` cannot prune -- and it is unselective enough
        // that the optimiser abandons the timestamp index too. Measured on a
        // live table:
        //
        //   timestamp > X                          all partitions, no index, 405,217 rows
        //   yyyymmdd >= D AND timestamp > X        all from D,     no index, 351,937 rows
        //   yyyymmdd BETWEEN D-1 AND D AND ts > X  1 partition,    yyyymmdd,   4,080 rows
        //
        // Note the middle row: an OPEN lower bound still reads everything from D
        // onwards. The bound has to be closed at both ends to prune to a span.
        //
        // Derived in the query builder rather than left to whoever writes the
        // report, because a report author has no reason to know the physical
        // partitioning -- and because this is the seam a non-SQL reporting
        // backend would override with its own pruning predicate. Putting it in
        // the reports instead would tie it to the star schema.
        //
        // The date is computed from the same timestamps in the same timezone the
        // yyyymmdd column was written in, so a range spanning midnight yields
        // two days and still prunes to those two partitions.
        if ( $dimension_name === 'timestamp' && $start && $end ) {

            $this->setConstraint(
                'date',
                array( 'start' => date( 'Ymd', (int) $start ), 'end' => date( 'Ymd', (int) $end ) ),
                'BETWEEN'
            );
        }

        // And the reverse: a period covering PART of a day needs a time bound as
        // well, or it silently widens to the whole day.
        //
        // last_half_hour and last_hour are allowlisted periods that produced
        // exactly the same constraint as `today` -- date BETWEEN D AND D, with no
        // timestamp bound at all. The period knew it meant 22:15-22:45; the query
        // asked for all 1,440 minutes. Wrong answers, not merely slow ones, and
        // nothing reported it.
        //
        // Only for periods that do NOT sit on day boundaries. today, yesterday,
        // this_week and the rest already align, and adding a redundant timestamp
        // bound to them would risk excluding rows whose yyyymmdd and timestamp
        // disagree at a timezone edge.
        if ( $dimension_name === 'date' && $this->timePeriod ) {

            $startTs = $this->timePeriod->startDate->getTimestamp();
            $endTs   = $this->timePeriod->endDate->getTimestamp();

            // Sub-day means SPAN, not clock alignment. last_seven_days runs
            // 23:59:59 to 23:59:59, so an alignment test flags it as partial and
            // would narrow it from seven whole days to a rolling 7x24h window --
            // quietly changing numbers users already read. A span under a day
            // isolates exactly the windows that need a time bound: currently
            // last_half_hour and last_hour.
            // Two conditions, and both are needed.
            //
            // Span alone is not enough: `today` runs 00:00:00 to 23:59:59, which
            // is 86,399 seconds and slips under a day. Start-of-day alone is not
            // enough either: `last_seven_days` starts at 23:59:59, so an
            // alignment test alone would flag it and narrow seven whole days to a
            // rolling 7x24h window -- quietly changing numbers users already
            // read. Together they select exactly the partial-day windows:
            // currently last_half_hour and last_hour.
            if ( ( $endTs - $startTs ) < 86400 && date( 'His', $startTs ) !== '000000' ) {

                $this->setConstraint(
                    'timestamp',
                    array( 'start' => $startTs, 'end' => $endTs ),
                    'BETWEEN'
                );
            }
        }
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
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            $formatter = $s->getFormatter($type);
        }

        // If we found a formatter, use it
        if (!empty($formatter)) {

            $value = call_user_func($formatter, $value);
        }

        return $value;
    }

    /**
     * A boolean dimension reads as Yes or No, never as 1 and null.
     *
     * NULL is the important half. These columns store 1 for true and leave the
     * row NULL for false rather than writing 0, so an unformatted pie slice is
     * labelled with an empty string and a grid cell shows nothing at all --
     * which reads as missing data rather than as "no".
     */
    function booleanFormatter($value) {

        return ! empty( $value ) ? 'Yes' : 'No';
    }

    function numberFormatter($value) {
	if(is_null($value)){
            return $value;
        }
        return number_format($value);
    }

    function formatSeconds($value) {

        return date("G:i:s",mktime(0,0,($value)));
    }

    function formatPercentage($value) {

        return number_format($value * 100, 2).'%';
    }

    function formatCurrency($value) {

        return \OWA\Core\Lib::formatCurrency(
                $value,
                \OWA\Core\CoreAPI::getSetting( 'base', 'currencyLocal' ),
                \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3' )
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
                $dimEntity = \OWA\Core\CoreAPI::entityFactory($dim['entity']);
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
                \OWA\Core\CoreAPI::debug(sprintf('%s metric does not have relation to dimension %s', $fk['entity']->getName(), $dim['name']));
            }

    }

    // remove
    function addMetric($metric_name, $child = false) {

        $ret = false;

        $m = $this->getMetric($metric_name);

        if (!$m) {
            $m = \OWA\Core\CoreAPI::metricFactory($metric_name);

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
                        \OWA\Core\CoreAPI::debug("Added $child_name to ChildMetrics array");
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

    /**
     * The request asked for something that cannot be honoured.
     *
     * A query is NOT run when one of these is recorded. Returning rows anyway
     * is what made the original bug so hard to see: the caller got an error in
     * a field it had no reason to read AND a plausible set of numbers computed
     * without the filter it asked for, under a success status.
     *
     * Reserved for what a caller can fix by sending a different request.
     * Routine internal misses stay in addError().
     */
    function addRequestError($msg) {

        $this->request_errors[] = $msg;

        $this->addError($msg);
    }

    /** Whether the request is malformed, so no query should run. */
    function hasRequestErrors() {

        return ! empty( $this->request_errors );
    }

    function addError($msg) {

        $this->errors[] = $msg;
        \OWA\Core\CoreAPI::debug($msg);
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
        
        // if there is a limit set then respect the limit and try to paginate the results by over querying
        if ( $this->getLimit() ) {
	        
            // query for more than we need
            \OWA\Core\CoreAPI::debug('applying limit of: ' . $this->getLimit() );
            
            $this->db->limit( $this->getLimit() * 10 );
            
        } else {
          
          // assume it's a date/time range query and use range resolution as limit
          switch ( $this->getTimeResolution() ) {
            
            case "day":
              $this->setLimit( $this->timePeriod->getDaysDifference() );
              break;
              
            case "month":
              $this->setLimit( $this->timePeriod->getMonthsDifference() );
              break;
              
            case "year":
              $this->setLimit( $this->timePeriod->getYearsDifference() );
              break;
            
            default:
              
              break;
              
          }
          
          $this->db->limit( $this->getLimit() );
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

        /*
         * A malformed request is not run at all.
         *
         * Recording the error and querying anyway meant the caller received
         * BOTH the complaint and a full set of numbers computed without the
         * filter they asked for -- and, over REST, under a 201. Numbers that
         * look right are worse than none, because nothing downstream doubts
         * them.
         *
         * The result set still carries the errors, so the caller is told why.
         */
        if ( $this->hasRequestErrors() ) {

            $this->resultSet->errors = $this->errors;
        $this->resultSet->request_errors = $this->request_errors;

            return $this->resultSet;
        }

		// determin the best fact table ot use forthe query based on
		// the metrics and dimensions requested
        $bm = $this->chooseBaseEntity();

        if ( $bm ) {

            $bname = $bm->getName();

            \OWA\Core\CoreAPI::debug("Using $bname as fact table entity for this result set.");

            // generate aggregate results
            $results = $this->computeAggregates( $bm );
            
            // merge into result set
            if ( $results ) {
	            
                $this->resultSet->aggregates = array_merge( $this->applyMetaDataToSingleResultRow( $results ), $this->resultSet->aggregates );
            }
			
			$dresults = [];
            // setup dimensional query if dimensions were specified in query
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
                }

                // Query for the dimensional rows.
                //
                // OUTSIDE the orderby block, which is where these two lines used
                // to sit -- their indentation always said they belonged here, but
                // the brace put them inside. The effect was that a breakdown with
                // no sort never ran its query at all: $dresults stayed undefined,
                // generate() received nothing, and the caller got zero rows next
                // to a perfectly correct aggregate. No error, because an
                // unassigned variable is only a notice and nothing was watching.
                //
                // It survived because every one of the ~60 declarative report
                // controllers sets a sort, so no shipped report ever took the
                // unsorted path. Found by reporting-facets.spec.js, which asks
                // for a breakdown without one.
                $dresults = $this->computeDimensionalRows( $bm );

                // paginate the results
                $dresults = $this->applyMetaDataToResults( $dresults );

                // generate dimensional results
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
        $this->resultSet->request_errors = $this->request_errors;
		
		/*
		 * Related dimensions and metrics, WHEN there is a base entity.
		 *
		 * There is not when the combination asked for is impossible: nothing
		 * serves clicks and visits at once, so the reduction produces no
		 * entity and $bm is null. The request error saying so has just been
		 * put on the result set two lines up -- and then this dereferenced the
		 * null and the whole request died as a 500 with an empty body, which
		 * is how an answerable "these cannot be asked for together" became an
		 * unexplained failure.
		 *
		 * Empty is the honest answer: with no table chosen, nothing is related
		 * to it. The reader gets the request error instead.
		 */
        $this->resultSet->setRelatedDimensions( $bm ? $this->getAllRelatedDimensions( $bm ) : array() );

        $this->resultSet->setRelatedMetrics( $bm ? $this->getAllRelatedMetrics( $bm ) : array() );

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
        
		// add any errors that should be returned in the result set
        $this->resultSet->errors = $this->errors;
        $this->resultSet->request_errors = $this->request_errors;
        
        if ( ! empty( $this->limit ) ) {
	        
            // query for more than we need
            \OWA\Core\CoreAPI::debug('applying limit of: ' . $this->limit );
            
            $this->db->limit( $this->limit * 10 );
        }

        if ( ! empty( $this->page ) ) {

            $this->db->offset( $this->calculateOffset() );
            
        }

        $results = $this->db->getAllRows();
        
        // generate dimensional results
        $this->resultSet->generate( $results, $this->query_params, [
	                
	                'resultsPerPage' => $this->getlimit(),
	                'page'	=> $this->getPage()
                ] );
		
        return $this->resultSet;
    }

    function generateSegmentQuery( $base_entity ) {

        $segment = $this->getSegment();
        $segment_entity = \OWA\Core\CoreAPI::entityFactory($base_entity->getName());
        $segment_entity->setTableAlias( $segment_entity->getTableAlias() . '_segment');

        if ( $segment ) {
            // use a new data access object
            $db = \OWA\Core\CoreAPI::dbFactory();
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

        // No entity, nothing related to it. See the caller: this is reached
        // with null when the metric/dimension combination has no base table.
        if ( ! $entity ) {

            return array();
        }

        $s = \OWA\Core\CoreAPI::serviceSingleton();
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

        // No entity, nothing related to it -- same reason as
        // getAllRelatedDimensions().
        if ( ! $entity ) {

            return array();
        }

        $related_metrics = array();
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $all_metrics = $s->getAllMetrics();
        $entity_name = $entity->getName();
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $metricsByEntity = $s->getMap('metricsByEntity');
        foreach ($all_metrics as $metric_name => $implementations) {

            foreach ($implementations as $implementation) {

                $m = \OWA\Core\CoreAPI::metricFactory( $implementation['class'], $implementation['params'] );

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
