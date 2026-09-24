<?php
namespace OWA\Core;


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
 * Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class Metric extends \OWA\Core\Base {

    /**
     * The rows this metric counts, when it counts some of them.
     *
     * ['column' => ..., 'value' => ..., 'operator' => '=']. Empty means every
     * row, which is what every metric did before conditions existed.
     *
     * @var array
     */
    protected $condition = array();

    /**
     * Current Time
     *
     * @var array
     */
    var $time_now = array();
    
    /**
     * Data
     *
     * @var array
     */
    var $data;
    
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
     * Page results
     *
     * @var boolean
     */
    var $page_results = false;
    
    /**
     * Data Access Object
     *
     * @var object
     */
    var $db;
    
    var $_default_offset = 0;
    
    var $pagination;
    
    var $page;
    
    var $limit;
    
    var $order;
    
    var $table;
    
    var $select = array();
    
    var $time_period_constraint_format = 'timestamp';
    
    var $column;
    
    var $is_calculated = false;
    
    var $is_aggregate;
    
    var $data_type;
    
    var $name;
    
    var $supported_data_types = array('percentage', 'decimal', 'integer', 'url', 'yyyymmdd', 'timestamp', 'string', 'currency');

    var $type, $entity, $all_columns;
        
    function __construct($params = array()) {
        
        if (!empty($params)) {
            $this->params = $params;
        }
            
        //$this->db = owa_coreAPI::dbSingleton();

        //$this->pagination = new owa_pagination;
        
        return parent::__construct();
    }
    
    /**
     * Set the labels of the measures
     *
     */
    function setLabels($array) {
    
        $this->labels = $array;
        return;
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
        
        if (!$key) {
            $key = $this->getName();
        }
        
        return $this->labels[$key];
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
     * Retrieve the labels of the measures
     *
     */
    function getLabels() {
    
        return $this->labels;
    
    }
    /*

    function getPagination() {
        
        $count = $this->calculatePaginationCount();
        $this->pagination->total_count = $count;
        return $this->pagination->getPagination();
    
    }
    
    */
    function zeroFill(&$array) {
    
        array_walk_recursive($array, array($this, 'addzero'));
        
        return $array;
        
    }
    
    function addzero(&$v, $k) {
        
        if (empty($v)) {
            
            $v = 0;
            
        }
        
        return;
    }
    /*

    function getPeriod() {
    
        return $this->params['period'];
    }
    
    function getOrder() {
    
        if (array_key_exists('order', $this->params)) {
            return $this->params['order'];
        }
    }
    
    function getLimit() {
        
        return $this->limit;
        
    }
    
    */
    function setEntity($name) {
        
        $this->entity = \OWA\Core\CoreAPI::entityFactory($name);
    }
    
    function getTableName() {
        
        return $this->entity->getTableName();
    }
    
    function getTableAlias() {
        
        return $this->entity->getTableAlias();
    }
    
    function setSelect($column, $as = '') {
        
        if (!$as) {
            
            $as = $this->getName();
        }
        
        $this->select = array($column, $as);
    }
    
    function getSelect() {
        
        if ( $this->select) {
            // old style metrics populate this explicitly.
            return $this->select;
        } else {
            $db = \OWA\Core\CoreAPI::dbSingleton();

            /*
             * Initialised, and the switch says so when it does not recognise a
             * type. It had no default, so an unrecognised aggregation returned
             * an undefined $statement -- a SELECT with a hole in it, built and
             * run without complaint. The outcome is the same as before (there
             * is nothing sensible to aggregate with), but it is now stated.
             */
            $statement = null;

            switch ( $this->type ) {
                
                case 'count':

                    /*
                     * A CONDITION IS THE SAME SCAN, not a subquery. Most of the
                     * v2 vocabulary is "count the rows that are X" --
                     * pageViews, domClicks, downloads, transactions, keyEvents
                     * -- and an event table answers that by testing a column on
                     * each row it is already reading. Measured on this box:
                     * EXPLAIN says select_type=SIMPLE, Using where; Using index.
                     *
                     * `sum(CASE ...)` rather than a FILTER clause or COUNTIF.
                     * CASE is SQL-92 and renders on every engine; FILTER is
                     * SQL:2003 and absent from MySQL, COUNTIF is BigQuery's and
                     * ClickHouse's. It is also what boolean_true_count below
                     * already does, so this follows the house pattern.
                     */
                    if ( $this->hasCondition() ) {

                        $where = $this->renderCondition();

                        // '' means the condition could not be rendered, and
                        // counting every row instead would be a metric quietly
                        // answering a different question.
                        $statement = $where === ''
                            ? null
                            : sprintf( 'sum(CASE WHEN %s THEN 1 ELSE 0 END)', $where );

                    } else {

                        $statement = $db->count( $this->getColumn() );
                    }
                    break;

                case 'distinct_count':

                    /*
                     * The CASE goes INSIDE the distinct, not around it: rows
                     * failing the test contribute NULL, which a distinct count
                     * ignores. Wrapping the aggregate instead would count the
                     * NULL group as a value.
                     */
                    if ( $this->hasCondition() ) {

                        $where = $this->renderCondition();

                        $statement = $where === ''
                            ? null
                            : $db->count( $db->distinct( sprintf( 'CASE WHEN %s THEN %s END',
                                  $where, $this->getColumn() ) ) );

                    } else {

                        $statement = $db->count( $db->distinct( $this->getColumn() ) );
                    }
                    break;
                
                case 'sum':
                    $statement = $db->sum( $this->getColumn() );
                    break;

                /*
                 * Named kinds, so a definition can describe an expression
                 * without carrying one. Both render exactly what the classes
                 * they replace rendered, character for character -- the
                 * conversion is meant to move where a metric is declared, not
                 * what it computes.
                 */
                case 'boolean_true_count':

                    /*
                     * NULL counts as 0 here, which merges "false" with "not
                     * recorded" (PLAN 1.12). Preserved deliberately rather than
                     * corrected: this reproduces the classes it replaces, and
                     * the three-valued boolean is a 1.x problem that v2's schema
                     * removes rather than one to fix inside a conversion.
                     */
                    $statement = sprintf(
                        'sum(CASE %s WHEN TRUE THEN 1 ELSE 0 END)', $this->getColumn() );
                    break;

                case 'avg_difference':

                    /*
                     * Two columns on the same table, subtracted then averaged --
                     * a duration. Qualified with the entity's alias because that
                     * is what the class did, and the alias is what makes the
                     * columns unambiguous once the query joins anything.
                     */
                    /* Both columns arrive already qualified by their setters. */
                    $statement = sprintf( 'round(avg(%s - %s))',
                        $this->getColumn(), $this->getSubtrahendColumn() );
                    break;

                default:
                    \OWA\Core\CoreAPI::error( sprintf(
                        'Metric "%s" has aggregation type "%s", which is not one of '
                        . 'count, distinct_count or sum. Its column will be missing '
                        . 'from the query.',
                        (string) $this->getName(), (string) $this->type ) );
            }
            
            return array( $statement, $this->getName() );
        }
        
    }
    
    function getSelectWithNoAlias() {
        
        if ( $this->select ) {
            return $this->select[0];
        } else {
            $select = $this->getSelect();
            return $select[0];
        }
    }
    
    function setName($name) {
        
        $this->name = $name;
    }
    
    function getName() {
        
        return $this->name;
    }
    
    function getFormat() {
        
        if (array_key_exists('result_format', $this->params)) {
            return $this->params['result_format'];
        }
    }
    
    /**
     * Sets a metric's column
     */
    function setColumn($col_name, $name = '') {
        
        if (!$name) {
            $name = $this->getName();
        }
        $this->column = $this->entity->getTableAlias().'.'.$col_name;
        $this->all_columns[$name] = $this->column;
        
    }
    
    /**
     * Gets a metric's column name
     */
    function getColumn() {
        
        return $this->column;
    }
    
    /**
     * The column subtracted from getColumn() by the avg_difference kind.
     *
     * Its own accessor rather than a second meaning for setColumn(), so a
     * metric that does not use it cannot silently acquire one.
     */
    var $subtrahend_column;

    function setSubtrahendColumn( $col_name ) {

        /*
         * Qualified here, exactly as setColumn() qualifies the primary column.
         * A report query joins several tables and both session.timestamp and
         * request.timestamp exist, so a bare column name is ambiguous -- but
         * that is the setter's job, not the expression's.
         */
        $this->subtrahend_column = $this->entity->getTableAlias() . '.' . $col_name;
    }

    function getSubtrahendColumn() {

        return $this->subtrahend_column;
    }

    function getEntityName() {
        return $this->entity->getName();
    }
    
    /**
     * Children and formula of a calculated metric.
     *
     * These lived only on CalculatedMetric, so ConfigurableMetric -- which
     * extends this class -- called setChildMetric() on an object that had no
     * such method. Its 'calculated' type has therefore never worked: supported
     * on paper, fatal on first use, and never registered by anything, so nobody
     * found out. Held here so any metric can be calculated; CalculatedMetric
     * keeps only the flag that says it is.
     */
    var $child_metrics = array();
    var $formula;

    function setChildMetric( $name ) {

        $this->child_metrics[] = $name;
    }

    function getChildMetrics() {

        return $this->child_metrics;
    }

    function setFormula( $string ) {

        $this->formula = $string;
    }

    function getFormula() {

        return $this->formula;
    }

    function isCalculated() {
        return $this->is_calculated;
    }
    
    function setDataType($string) {
        
        if (in_array($string, $this->supported_data_types)) {
            $this->data_type = $string;
        }
        
    }
    
    function getDataType() {
        return $this->data_type;
    }
    
    function setAggregate() {
    
        $this->is_aggregate = true;
    }
    
    function isAggregate() {
    
        return $this->is_aggregate;
    }
    
    /**
     * Restrict what this metric counts to the rows matching one test.
     *
     * A definition names a column, an operator and a value; it never carries
     * SQL. The same rule as a derived dimension naming a shape (PLAN 2.4), and
     * for the same reason -- a metric carrying an expression has to be rewritten
     * by hand if the reporting store ever changes.
     *
     * @param array $condition ['column' => ..., 'value' => ..., 'operator' => '=']
     * @return void
     */
    function setCondition( array $condition ) {

        $this->condition = $condition;
    }

    /** @return bool */
    function hasCondition() {

        return ! empty( $this->condition['column'] )
            && array_key_exists( 'value', (array) $this->condition );
    }

    /**
     * The condition as SQL.
     *
     * The OPERATOR IS NOT INTERPOLATED. It is matched against a fixed list and
     * the match is what reaches the statement, so a definition cannot put
     * anything else there -- these files are repository-controlled, but a
     * comparison operator is exactly the sort of thing that later gets wired to
     * something that is not.
     *
     * @return string
     */
    protected function renderCondition() {

        $allowed = array( '=', '!=', '<>', '>', '<', '>=', '<=' );

        $asked = isset( $this->condition['operator'] )
            ? (string) $this->condition['operator'] : '=';

        $operator = in_array( $asked, $allowed, true ) ? $asked : '=';

        if ( $operator !== $asked ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Metric "%s" asked for comparison operator "%s", which is not one of %s. '
              . 'Using "=" instead.',
                (string) $this->getName(), $asked, implode( ' ', $allowed ) ) );
        }

        $literal = $this->conditionLiteral();

        if ( $literal === null ) {

            return '';
        }

        return sprintf( '%s %s %s',
            $this->qualify( $this->condition['column'] ), $operator, $literal );
    }

    /**
     * The condition's value as a SQL literal, or null if it may not be one.
     *
     * VALIDATED, NOT ESCAPED, and the difference is the point.
     *
     * A driver's escaper needs a live connection -- Mysql::prepare() calls
     * mysqli_real_escape_string( $this->connection, ... ) -- so escaping here
     * made a metric's SELECT expression depend on the database being connected.
     * That is wrong on its own terms: the expression is a property of the
     * definition, built once at registration, and CI's unit job has no database
     * at all. It rendered `event_type = ''` there, which is a metric that
     * silently counts nothing.
     *
     * Escaping is also the wrong tool for the input. These values come from a
     * repository-controlled config file, so the risk is a typo that breaks the
     * statement rather than a visitor injecting one. A value that could not be
     * a literal is REFUSED and said out loud, which is a better answer than
     * quietly quoting something unexpected -- and the caller then renders no
     * statement at all, so the metric is missing rather than wrong.
     *
     * @return string|null
     */
    protected function conditionLiteral() {

        $value = $this->condition['value'];

        if ( is_int( $value ) || is_float( $value ) ) {

            return (string) $value;
        }

        if ( is_bool( $value ) ) {

            return $value ? '1' : '0';
        }

        $value = (string) $value;

        // Letters, digits, underscore, dot, hyphen and space: enough for an
        // event type, a state name or a short token, and nothing that can end
        // a quoted literal or start a comment.
        if ( ! preg_match( '/^[A-Za-z0-9_.\- ]*$/', $value ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Metric "%s" has a condition value (%s) that cannot be a SQL literal. '
              . 'Its column will be missing from the query.',
                (string) $this->getName(), $value ) );

            return null;
        }

        return "'" . $value . "'";
    }

    /**
     * A column, qualified by this metric's entity alias.
     *
     * setColumn() does this for the counted column; a condition column needs
     * the same treatment or it is ambiguous the moment the query joins
     * anything.
     *
     * @param  string $column
     * @return string
     */
    protected function qualify( $column ) {

        return $this->entity->getTableAlias() . '.' . $column;
    }

    function setMetricType( $type ) {
        $this->type = $type;
        
        if ( $type === 'calculated' ) {
             $this->is_calculated = true;
        }
    }
}

?>
