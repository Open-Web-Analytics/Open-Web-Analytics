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
 * Database Connection Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class Db extends \OWA\Core\Base {

    /**
     * Whitelist of operators the query builder is allowed to interpolate
     * into a WHERE/HAVING clause. The operator is emitted UNQUOTED, so any
     * value that reaches _makeConstraintClause() outside this set would be
     * a SQL-injection primitive.
     *
     * Set matches the Data Access API docs (==, !=, >, >=, <, <=, =~, !~,
     * =@, !@) plus the bare '=' default used by internal PHP callers and
     * 'between' used by internal range constraints. '<>' is NOT included:
     * the docs use '!=' as the canonical not-equals and no caller emits
     * '<>'.
     */
    const ALLOWED_OPERATORS = [
        '=', '==',
        '!=',
        '>', '>=', '<', '<=',
        'between',
        '=~', '!~',
        '=@', '!@',
    ];

    /**
     * Database Connection
     *
     * @var object
     */
    var $connection;

    var $connectionParams;

    /**
     * Number of queries
     *
     * @var integer
     */
    var $num_queries;

    /**
     * Raw result object
     *
     * @var object
     */
    var $new_result;

    /**
     * Rows
     *
     * @var array
     */
    var $result;

    /**
     * Caller Params
     *
     * @var array
     */
    var $params = array();

    /**
     * Status of selecting a databse
     *
     * @var boolean
     */
    var $database_selection;

    /**
     * Status of connection
     *
     * @var boolean
     */
    var $connection_status;

    /**
     * Number of rows in result set
     *
     * @var integer
     */
    var $num_rows;

    /**
     * Number of rows affected by insert/update/delete statements
     *
     * @var integer
     */
    var $rows_affected;

    /**
     * Microtime Start of Query
     *
     * @var float
     */
    var $_start_time;

    /**
     * Total Elapsed time of query
     *
     * @var string
     */
    var $_total_time;

    /**
     * Storage Array for components of sql queries
     *
     * @var array
     */
    var $_sqlParams = array();

    /**
     * Sql Statement
     *
     * @var string
     */
    var $_sql_statement;

    /**
     * Last Sql Statement
     *
     * @var string
     */
    var $_last_sql_statement;

    function __construct($db_host, $db_port, $db_name, $db_user, $db_password, $open_new_connection = true, $persistant = false) {

        $this->connectionParams = array('host' => $db_host,
                                        'port' => $db_port,
                                         'user' => $db_user,
                                         'password' => $db_password,
                                         'name' => $db_name,
                                         'open_new_connection' => $open_new_connection,
                                         'persistant' => $persistant);
                                                                          
        parent::__construct();
    }

    function __destruct() {

        if ( $this->isConnectionEstablished() ) {

            $this->close();
        }
    }

    function connect() {


        return false;
    }

    function pconnect() {

        return false;
    }

    function close() {

        return false;
    }

    function isConnectionEstablished() {

        return $this->connection_status;
    }

    function getConnectionParam($name) {

        if (array_key_exists($name, $this->connectionParams)) {
            return $this->connectionParams[$name];
        }
    }

    /**
     * Prepare string
     *
     * @param string $string
     * @return string
     */
    function prepare_string($string) {

        $chars = array("\t", "\n");
        return str_replace($chars, " ", $string);
    }

    /**
     * Starts the query microtimer
     *
     */
    function _timerStart() {

        $this->_start_time = microtime(true);
        return;
    }

    /**
     * Ends the query microtimer and populates $this->_total_time
     *
     */
    function _timerEnd() {

        $endtime = microtime(true);
        $this->_total_time = number_format($endtime - $this->_start_time, 6);

        return;

    }

    function selectColumn($name, $as = '') {

        if (is_array($name)) {
            $as = $name[1];
            $name = $name[0];
        }

        $this->_sqlParams['select_values'][] = array('name' => $name, 'as' => $as);

        return;
    }

    function select($name, $as = '') {
        return $this->selectColumn($name, $as = '');
    }

    function where($name, $value, $operator = '=') {

        if ( ! \OWA\Core\Lib::isEmpty( $value ) ) {

            // hack for intentional empty value
            if($value == ' '){
                $value = '';
            }

            $this->_sqlParams['where'][$name] = array('name' => $name, 'value' => $value, 'operator' => $operator);
        }
    }

    function having($name, $value, $operator = '=') {

        if ( ! \OWA\Core\Lib::isEmpty( $value ) ) {

            // hack for intentional empty value
            if($value == ' ') {
                $value = '';
            }

            $this->_sqlParams['having'][$name] = array('name' => $name, 'value' => $value, 'operator' => $operator);
        }
    }

    function multiWhere($where_array = array()) {

        if (!empty($where_array)):

            foreach ($where_array as $k => $v) {
                if ( ! \OWA\Core\Lib::isEmpty($v) ):

                    if (empty($v['operator'])):
                        $v['operator'] = '=';
                    endif;

                    $this->_sqlParams['where'][$k] = array('name' => $k, 'value' => $v['value'], 'operator' => $v['operator']);
                endif;
            }

        endif;
    }

    function groupBy($col) {

        $this->_sqlParams['groupby'][] = $col;
        return;
    }

    function orderBy($col, $flag = '') {

        $this->_sqlParams['orderby'][] = array($col, $flag);
        return;
    }

    function order($flag) {

        $this->_sqlParams['order'] = $flag;
        return;
    }

    function limit($value) {

        $this->_sqlParams['limit'] = $value;
        return;
    }

    function offset($value) {

        $this->_sqlParams['offset'] = $value;
        return;
    }

    function set($name, $value) {

        $this->_sqlParams['set_values'][] = array('name' => $name, 'value' => $value);
        return;
    }

    function executeQuery() {

        switch($this->_sqlParams['query_type']) {

            case 'insert':

                return $this->_insertQuery();

            case 'select':

                return $this->_selectQuery();

            case 'update':

                return $this->_updateQuery();

            case 'delete':

                return $this->_deleteQuery();

            default:

                return $this->_query();
        }
    }

    function getAllRows() {

         return $this->_selectQuery();
    }

    function getOneRow() {

        $this->limit(1);
        $ret = $this->_selectQuery();
        return (is_array($ret) && isset($ret[0])) ? $ret[0] : null;
        //return is_null($ret)?null:$ret[0];
    }

    function _setSql($sql) {
        $this->_sql_statement = $sql;
    }

    function selectFrom($name, $as = '') {

        if (is_array($name)) {
            $as = $name[1];
            $name = $name[0];
        }

        $this->_sqlParams['query_type'] = 'select';
        $this->_sqlParams['from'][$name] = array('name' => $name, 'as' => $as);
    }

    function from( $name, $as = '' ) {

        return $this->selectFrom( $name, $as );
    }

    function insertInto($table) {

        $this->_sqlParams['query_type'] = 'insert';
        $this->_sqlParams['table'] = $table;
    }

    function deleteFrom($table) {

        $this->_sqlParams['query_type'] = 'delete';
        $this->_sqlParams['table'] = $table;
    }

    function updateTable($table) {

        $this->_sqlParams['query_type'] = 'update';
        $this->_sqlParams['table'] = $table;
    }

    function _insertQuery() {
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        $params = $this->_fetchSqlParams('set_values');

        $count = count($params);

        $i = 0;

        $sql_cols = '';
        $sql_values = '';

        foreach ($params as $k => $v) {

            $sql_cols .= $v['name'];
            $sql_values .= "'".$this->prepare($v['value'])."'";

            $i++;

            // Add commas
            if ($i < $count):

                $sql_cols .= ", ";
                $sql_values .= ", ";

            endif;
        }
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        $this->_setSql(sprintf(OWA_SQL_INSERT_ROW, $this->_sqlParams['table'], $sql_cols, $sql_values));
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        $ret = $this->_query();
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        return $ret;

    }

    function generateSelectQuerySql() {

        $cols = '';
        $i = 0;
        $params = $this->_fetchSqlParams('select_values');
        $count = count($params);

        foreach ($params as $k => $v) {

            $cols .= $v['name'];

            // Add as
            if (!empty($v['as'])):

                $cols .= ' as '.$this->prepare( $v['as']);

            endif;

            // Add commas
            if ($i < $count - 1):

                $cols .= ', ';

            endif;

            $i++;

        }

        $sql = sprintf("SELECT %s FROM %s %s %s %s %s %s",
                                        $cols,
                                        $this->_makeFromClause(),
                                        $this->_makeWhereClause(),
                                        $this->_makeGroupByClause(),
                                        $this->_makeHavingClause(),
                                        $this->_makeOrderByClause(),
                                        $this->_makeLimitClause()
                                        );
        $this->_setSql($sql);
        return $sql;
    }

    function _selectQuery() {

        $this->generateSelectQuerySql();
        return $this->_query();

    }


    function _updateQuery() {

        $params = $this->_fetchSqlParams('set_values');

        $count = count($params);

        $i = 0;

        $sql_cols = '';
        $sql_values = '';
        $set = '';

        foreach ($params as $k => $v) {

            //$sql_cols = $sql_cols.$key;
            //$sql_values = $sql_values."'".$this->prepare($value)."'";

            // Add commas
            if ($i != 0):

                $set .= ', ';

            endif;

            $set .= $this->prepare( $v['name'] ) .' = \'' . $this->prepare($v['value']) . '\'';

            $i++;
        }

        $this->_setSql(sprintf(OWA_SQL_UPDATE_ROW, $this->_sqlParams['table'], $set, $this->_makeWhereClause()));

        return $this->_query();
    }

    function _deleteQuery() {

        $this->_setSql(sprintf(OWA_SQL_DELETE_ROW, $this->_sqlParams['table'], $this->_makeWhereClause()));

        return $this->_query();
    }

    function rawQuery($sql) {

        $this->_setSql($sql);

        return $this->_query();
    }

    function _fetchSqlParams($sql_params_name) {

        if (array_key_exists($sql_params_name, $this->_sqlParams)):
            if (!empty($this->_sqlParams[$sql_params_name])):
                return $this->_sqlParams[$sql_params_name];
            else:
                return false;
            endif;
        else:
            return false;
        endif;
    }

    function _makeWhereClause() {

        $params = $this->_fetchSqlParams('where');

        if ( ! empty( $params ) ) {

            return $this->_makeConstraintClause('WHERE', $params);
        }
    }

    function _makeHavingClause() {

        $params = $this->_fetchSqlParams('having');

        if ( ! empty( $params ) ) {

            return $this->_makeConstraintClause('HAVING', $params);
        }
    }
    
    /**
     *  Generates the SQL constraint string
     *  @type string    'WHERE' || 'HAVING'
     */
    function _makeConstraintClause( $type, $params ) {
         
        if ( ! empty( $params ) ) {

            $count = count( $params );
            $i = 0;

            $constraint = $type.' ';

            foreach ($params as $k => $v) {
                \OWA\Core\CoreAPI::debug($v);

                $op = strtolower( $v['operator'] );

                if ( ! in_array( $op, self::ALLOWED_OPERATORS, true ) ) {
                    \OWA\Core\CoreAPI::debug( sprintf( 'Refusing constraint with disallowed operator: %s', $v['operator'] ) );
                    // still bump the counter so the AND-join positions stay correct
                    $i++;
                    continue;
                }

                switch ( $op ) {

                    case '==':
                        $constraint .= sprintf("%s = '%s'", $this->prepare( $v['name'] ), $this->prepare( $v['value'] ) );
                        break;

                    case 'between':
                        $constraint .= sprintf("%s BETWEEN '%s' AND '%s'", $this->prepare( $v['name'] ), $this->prepare( $v['value']['start'] ), $this->prepare( $v['value']['end'] ) );
                        break;

                    case '=~':
                        $constraint .= sprintf("%s %s '%s'", $this->prepare( $v['name'] ), OWA_SQL_REGEXP, $this->prepare( $v['value'] ) );
                        break;

                    case '!~':
                        $constraint .= sprintf("%s %s '%s'",$this->prepare( $v['name'] ), OWA_SQL_NOTREGEXP, $this->prepare( $v['value'] ) );
                        break;

                    case '=@':
                        $constraint .= sprintf("LOCATE('%s', %s) > 0",$this->prepare( $v['value'] ), $this->prepare( $v['name'] ) );
                        break;

                    case '!@':
                        $constraint .= sprintf("LOCATE('%s', %s) = 0",$this->prepare( $v['value'] ), $this->prepare( $v['name'] ) );
                        break;

                    default:
                        // $op has already been validated against ALLOWED_OPERATORS,
                        // so this covers '=', '!=', '>', '>=', '<', '<='.
                        $constraint .= sprintf("%s %s '%s'",$this->prepare( $v['name'] ), $op, $this->prepare( $v['value'] ) );
                        break;
                }

                if ($i < $count - 1) {

                    $constraint .= " AND ";
                }

                $i++;
            }

            return $constraint;
        }
    }

    function join($type, $table, $as, $foreign_key, $primary_key = '') {

        if (!$primary_key) {

            if (!$as) {
                    $as = $table;
            }

            $primary_key = $as.'.id';
        }



        $this->_sqlParams['joins'][$as] = array('type' => $type,
                                             'table' => $table,
                                             'as' => $as,
                                             'foreign_key' => $foreign_key,
                                             'primary_key' => $primary_key);

    }

    function prepare ( $string ) {

        return \OWA\Module\Base\Classes\Sanitize::stripSql( $string );
    }

    function _makeJoinClause() {

        $params = $this->_fetchSqlParams('joins');

        if (!empty($params)):

            $join_clause = '';

            foreach ($params as $k => $v) {

                if (!empty($v['as'])):
                    $join_clause .= sprintf(" %s %s AS %s ON %s = %s", $v['type'],
                                                                 $v['table'],
                                                                 $v['as'],
                                                                 $v['foreign_key'],
                                                                 $v['primary_key']);
                else:
                    $join_clause .= sprintf(" %s %s ON %s = %s", $v['type'],
                                                                 $v['table'],                                                                                                                          $v['foreign_key'],
                                                                 $v['primary_key']);
                endif;



            }

            return $join_clause;

        else:
            return;
        endif;

    }

    function _makeFromClause() {

        $from = '';
        $i = 0;
        $params = $this->_fetchSqlParams('from');

        if(!empty($params)):

            $count = count($params);

            foreach ($params as $k => $v) {

                $from .= $v['name'];

                // Add as
                if (!empty($v['as'])):

                    $from .= ' as '.$v['as'];

                endif;

                // Add commas
                if ($i < $count - 1):

                    $from .= ', ';

                endif;

                $i++;

            }

            $from .= $this->_makeJoinClause();

            return $from;
        else:
            $this->e->debug("No SQL FROM params set.");
            return false;
        endif;

    }

    function _makeGroupByClause() {

        $params = $this->_fetchSqlParams('groupby');

        if (!empty($params)):

            return sprintf("GROUP BY %s", $this->_makeDelimitedValueList($params));

        else:
            return;
        endif;


    }

    function _makeOrderByClause() {

        $sorts = $this->_fetchSqlParams('orderby');
        //print_r($sorts);
        if (!empty($sorts)) {

            $order = $this->_fetchSqlParams('order');

            $i = 1;
            $sort_string = '';
            $count = count($sorts);
            foreach ($sorts as $sort) {

                // needed for backwards compatibility.
                if (!isset($sort[1])) {
                    $sort[1] = $order;
                }
                
                if ( ! $this->isValidSortDirectionValue( $sort[1] ) ) {
                    
                    $sort[1] = $this->getDefaultSortDirection();
                }

                $sort_string .= sprintf("%s %s",$sort[0], $sort[1]);
                if ($i < $count) {
                    $sort_string .= ', ';
                }

                $i++;
            }

            return sprintf("ORDER BY %s", $sort_string);

        } else {
         
            return;
        }
    }

    function _makeLimitClause() {

        $param = $this->_fetchSqlParams('limit');

        if(!empty($param)):
            $limit = sprintf("LIMIT %d", $param);

            $offset = $this->_makeOffsetClause();

            $ret = $limit . ' ' . $offset;

            return $ret;
        else:
            return;
        endif;

    }

    function _makeOffsetClause() {

        $param = $this->_fetchSqlParams('offset');

        if(!empty($param)):
            return sprintf("OFFSET %d", $param);
        else:
            return;
        endif;

    }


    /**
     * Creates a delimited value list from an array or arrays.
     *
     */
    function _makeDelimitedValueListArray($values, $delimiter = ', ', $inner_delimiter = ' ') {

        $items = '';
        $i = 0;
        $count = count($values);

        //print_r($values);

        foreach ($values as $k) {

            $items .= implode($inner_delimiter, $k);

            // Add commas
            if ($i < $count - 1):

                $items .= $delimiter;

            endif;

            $i++;

        }

        return $items;

    }

    function _makeDelimitedValueList($values, $delimiter = ', ') {

        $items = '';
        $i = 0;
        $count = count($values);

        if (is_array($values)):

            foreach ($values as $k) {

                $items .= $k;

                // Add commas
                if ($i < $count - 1):

                    $items .= $delimiter;

                endif;

                $i++;

            }

        else:

            $items = $values;

        endif;

        return $items;

    }

    function _query() {

        switch($this->_sqlParams['query_type']) {

            case 'insert':

                $ret = $this->query($this->_sql_statement);
                break;
            case 'select':

                $ret = $this->get_results($this->_sql_statement);

                if (array_key_exists('result_format', $this->_sqlParams)):
                    $ret = $this->_formatResults($ret);
                endif;

                break;

            case 'update':

                $ret = $this->query($this->_sql_statement);
                break;
            case 'delete':

                $ret = $this->query($this->_sql_statement);
                break;
        }

        $this->_last_sql_statement = $this->_sql_statement;
        $this->_sql_statement = '';
        $this->_sqlParams = array();
        return $ret;

    }

    function removeNs($string, $ns = '') {

        if (empty($ns)):
            $ns = $this->config['ns'];
        endif;

        $ns_len = strlen($ns);
        return substr($string, $ns_len);

    }

    function setFormat($value) {

        $this->_sqlParams['result_format'] = $value;
        return;
    }

    function _formatResults($results) {

        switch ($this->_sqlParams['result_format']) {

                case "single_array":
                    return $results[0];
                    break;
                case "single_row":
                    return $results[0];
                    break;
                case "inverted_array":
                    return \OWA\Core\Lib::deconstruct_assoc($results);
                    break;
                default:
                    return $results;
                    break;
        }

    }

        /**
     * Drops a table
     *
     */
    function dropTable($table_name) {

        return $this->query(sprintf(OWA_SQL_DROP_TABLE, $table_name));

    }

    /**
     * Change table type
     *
     */
    function alterTableType($table_name, $engine) {

        return $this->query(sprintf(OWA_SQL_ALTER_TABLE_TYPE, $table_name, $engine));

    }


    /**
     * Rename a table
     *
     */
    function renameTable($table_name, $new_table_name) {

        return $this->query(sprintf(OWA_SQL_RENAME_TABLE, $table_name, $new_table_name));
    }

    /**
     * Renames column
     * idempotent
     */
    function renameColumn($table_name, $old, $new, $defs) {

        return $this->query(sprintf(OWA_SQL_RENAME_COLUMN, $table_name, $old, $new, $defs));
    }


    /**
     * Adds new column to table
     * idempotent
     */
    function addColumn($table_name, $column_name, $column_definition) {

        return $this->query(sprintf(OWA_SQL_ADD_COLUMN, $table_name, $column_name, $column_definition));
    }

    /**
     * Drops a column from a table
     *
     */
    function dropColumn($table_name, $column_name) {

        return $this->query(sprintf(OWA_SQL_DROP_COLUMN, $table_name, $column_name));

    }

    /**
     * Changes the definition of a column
     *
     */
    function modifyColumn($table_name, $column_name, $column_definition) {

        return $this->query(sprintf(OWA_SQL_MODIFY_COLUMN, $table_name, $column_name, $column_definition));
    }

    /**
     * Normalizes a column list into its canonical comma-separated form.
     *
     * Callers pass either 'yyyymmdd' or 'action_group, action_name'. The
     * comparison against information_schema needs the form GROUP_CONCAT
     * produces, so whitespace is removed. Returns an empty array if any part is
     * not a bare identifier, which is the caller's signal to refuse.
     */
    protected function normalizeIndexColumns( $column_name ) {

        $cols = array();

        foreach ( explode( ',', (string) $column_name ) as $col ) {

            $col = trim( $col );

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $col ) ) {

                return array();
            }

            $cols[] = $col;
        }

        return $cols;
    }

    /**
     * Is there already an index covering exactly these columns?
     *
     * Schema introspection is driver-specific, so the driver answers this. A
     * driver that cannot introspect reports false, which preserves the old
     * add-unconditionally behaviour rather than silently skipping the index.
     */
    function indexExists( $table_name, $column_name ) {

        return false;
    }

    /**
     * Every non-primary index on the OWA tables.
     *
     * Schema introspection is driver-specific. A driver that cannot introspect
     * reports nothing, so callers see no indexes rather than a wrong answer.
     *
     * @return array
     */
    function listIndexes() {

        return array();
    }

    /**
     * Can this driver partition tables? Assume not.
     *
     * @return bool
     */
    function supportsPartitioning() {

        return false;
    }

    /**
     * The partitions on a table. Nothing, where partitioning is unsupported.
     *
     * @param string $table_name
     * @return array
     */
    function listPartitions( $table_name ) {

        return array();
    }

    /**
     * Is this table partitioned?
     *
     * @param string $table_name
     * @return bool
     */
    function isPartitioned( $table_name ) {

        return (bool) $this->listPartitions( $table_name );
    }

    /**
     * Where a month is cut, by day of month.
     *
     * Named for how many parts a month is divided into, never for a duration:
     * months vary from 28 to 31 days, so any name carrying a day count would be
     * wrong in some month. A third of January is 11 days and a third of
     * February is 8; both are still a third of their month.
     *
     * Cutting only on days of the month is also what keeps every boundary
     * aligned to a month start, which is what lets a granularity change rewrite
     * one month at a time instead of the whole table.
     */
    const PARTITION_CUTS = array(
        'monthly'       => array( 1 ),
        'half-month'    => array( 1, 16 ),
        'quarter-month' => array( 1, 8, 15, 22 ),
        // 'daily' is every day of the month, so it is generated rather than listed.
    );

    /**
     * Is this a granularity we can partition by?
     *
     * @param string $granularity
     * @return bool
     */
    public static function isPartitionGranularity( $granularity ) {

        return $granularity === 'daily' || isset( self::PARTITION_CUTS[ $granularity ] );
    }

    /**
     * The day-of-month cut points for one month.
     *
     * @param \DateTimeImmutable $month  any day within the month
     * @param string $granularity
     * @return int[] ascending days of the month
     */
    private static function cutsForMonth( $month, $granularity ) {

        if ( $granularity === 'daily' ) {

            return range( 1, (int) $month->format( 't' ) );
        }

        if ( ! isset( self::PARTITION_CUTS[ $granularity ] ) ) {

            return array();
        }

        $days = (int) $month->format( 't' );
        $cuts = array();

        foreach ( self::PARTITION_CUTS[ $granularity ] as $day ) {

            // A short month cannot be cut past its end.
            if ( $day <= $days ) {

                $cuts[] = $day;
            }
        }

        return $cuts;
    }

    /**
     * Partition boundaries covering a date range.
     *
     * Each partition is named for the first day it holds and bounded by the
     * first day it does not. Rows whose date is malformed -- a handful of
     * installs carry yyyymmdd values of 0 or 1 -- land in the first partition,
     * since RANGE has no lower bound.
     *
     * @param int    $start_yyyymmdd
     * @param int    $end_yyyymmdd
     * @param string $granularity
     * @return array name => less_than, in range order
     */
    public static function makePartitionRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity = 'monthly' ) {

        return self::buildRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity, false );
    }

    /**
     * Partition boundaries that exactly tile a half-open span.
     *
     * REORGANIZE requires the replacement partitions to cover precisely the
     * span of the ones they replace -- no gap, no overhang -- so boundaries
     * outside the span are clamped to it.
     *
     * @param int    $start_yyyymmdd  first day the span holds
     * @param int    $end_yyyymmdd    first day it does not
     * @param string $granularity
     * @return array name => less_than
     */
    public static function makePartitionRangesForSpan( $start_yyyymmdd, $end_yyyymmdd, $granularity = 'monthly' ) {

        return self::buildRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity, true );
    }

    /**
     * Walk the months in a range, emitting a partition per cut point.
     *
     * @param int    $start_yyyymmdd
     * @param int    $end_yyyymmdd
     * @param string $granularity
     * @param bool   $exact  treat the end as exclusive and clamp to it
     * @return array
     */
    private static function buildRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity, $exact ) {

        if ( ! self::isPartitionGranularity( $granularity ) ) {

            return array();
        }

        $start = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $start_yyyymmdd );
        $end   = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $end_yyyymmdd );

        if ( ! $start || ! $end ) {

            return array();
        }

        if ( $exact ? ( $end <= $start ) : ( $end < $start ) ) {

            return array();
        }

        $ranges = array();
        $month  = $start->modify( 'first day of this month' )->setTime( 0, 0 );
        $limit  = $end->modify( 'first day of next month' )->setTime( 0, 0 );

        while ( $month < $limit ) {

            $next_month = $month->modify( 'first day of next month' );

            $cuts = self::cutsForMonth( $month, $granularity );

            foreach ( $cuts as $i => $day ) {

                $from = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day );

                $to = isset( $cuts[ $i + 1 ] )
                    ? $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $cuts[ $i + 1 ] )
                    : $next_month;

                if ( $exact ) {

                    // Nothing outside the span the replacement has to tile.
                    if ( $to <= $start || $from >= $end ) {

                        continue;
                    }

                    if ( $from < $start ) {

                        $from = $start;
                    }

                    if ( $to > $end ) {

                        $to = $end;
                    }
                }

                $ranges[ 'p' . $from->format( 'Ymd' ) ] = $to->format( 'Ymd' );
            }

            $month = $next_month;
        }

        return $ranges;
    }

    /**
     * Build the clause that partitions a table by range.
     *
     * @param string $column
     * @param array  $ranges  name => less_than
     * @param bool   $with_maxvalue  append the catch-all
     * @return string  empty when the driver cannot partition
     */
    function makePartitionClause( $column, $ranges, $with_maxvalue = true ) {

        if ( ! $this->supportsPartitioning() || ! $ranges ) {

            return '';
        }

        $parts = array();

        foreach ( $ranges as $name => $less_than ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        if ( $with_maxvalue ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, 'pmax', OWA_DTD_PARTITION_MAXVALUE );
        }

        return sprintf( OWA_DTD_PARTITION_BY_RANGE, $column, implode( ', ', $parts ) );
    }

    /**
     * Partition an existing table by range.
     *
     * The partitioning column has to be part of every unique key, so the
     * primary key is widened first. Both statements rebuild the table, which is
     * why this belongs in a deliberate operation rather than on a request path.
     *
     * @param string $table_name
     * @param string $column
     * @param array  $ranges
     * @return bool
     */
    function partitionTable( $table_name, $column, $ranges ) {

        if ( ! $this->supportsPartitioning() ) {

            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $column ) ) {

            return false;
        }

        $clause = $this->makePartitionClause( $column, $ranges );

        if ( ! $clause ) {

            return false;
        }

        return $this->query( sprintf( 'ALTER TABLE %s%s', $table_name, $clause ) );
    }

    /**
     * Drop the named partition.
     *
     * @param string $table_name
     * @param string $partition
     * @return bool
     */
    function dropPartition( $table_name, $partition ) {

        if ( ! $this->supportsPartitioning()
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $partition ) ) {

            return false;
        }

        return $this->query( sprintf( OWA_SQL_DROP_PARTITION, $table_name, $partition ) );
    }

    /**
     * Replace one or more contiguous partitions with a different set.
     *
     * Only the named partitions are rewritten, so changing granularity can be
     * done a period at a time rather than rebuilding the whole table.
     *
     * @param string $table_name
     * @param array  $from   partition names being replaced
     * @param array  $ranges name => less_than to replace them with
     * @return bool
     */
    function reorganizePartitions( $table_name, $from, $ranges ) {

        if ( ! $this->supportsPartitioning() || ! $from || ! $ranges
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        foreach ( $from as $name ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $name ) ) {

                return false;
            }
        }

        $parts = array();

        foreach ( $ranges as $name => $less_than ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        return $this->query( sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, implode( ',', $from ), implode( ', ', $parts )
        ) );
    }

    /**
     * The partitions of a table as spans, excluding the catch-all.
     *
     * RANGE gives each partition an upper bound only, so a partition's lower
     * bound is the previous partition's upper bound. The first one has none --
     * it holds everything below its boundary -- and is reported with the start
     * encoded in its name, which is what this class's own naming provides.
     *
     * @param string $table_name
     * @return array of ['name','start','less_than']
     */
    function getPartitionSpans( $table_name ) {

        $spans = array();
        $prev  = null;

        foreach ( $this->listPartitions( $table_name ) as $p ) {

            if ( strtoupper( $p['less_than'] ) === OWA_DTD_PARTITION_MAXVALUE ) {

                continue;
            }

            $start = $prev;

            if ( $start === null ) {

                // No lower bound; recover the intended start from the name.
                $start = preg_match( '/^p(\d{8})$/', $p['name'], $m ) ? $m[1] : $p['less_than'];
            }

            $spans[] = array(
                'name'      => $p['name'],
                'start'     => (string) $start,
                'less_than' => (string) $p['less_than'],
            );

            $prev = $p['less_than'];
        }

        return $spans;
    }

    /**
     * Which partitions hold only data older than a cutoff.
     *
     * A partition is droppable only when everything in it precedes the cutoff,
     * so a partition straddling that date is kept: dropping it would remove
     * data on or after the date, which is more than was asked for. With
     * anything coarser than daily partitions that means the boundary actually
     * reached is usually earlier than the one requested, so it is reported --
     * 'effective' is the date before which data no longer exists once these are
     * dropped.
     *
     * The catch-all is never droppable: it has no upper bound, and it holds
     * current traffic.
     *
     * @param string $table_name
     * @param int    $older_than_yyyymmdd
     * @return array ['drop' => string[], 'effective' => string|null, 'straddling' => array|null]
     */
    function getDroppablePartitions( $table_name, $older_than_yyyymmdd ) {

        $drop       = array();
        $effective  = null;
        $straddling = null;
        $cutoff     = (string) $older_than_yyyymmdd;

        foreach ( $this->getPartitionSpans( $table_name ) as $span ) {

            if ( (string) $span['less_than'] <= $cutoff ) {

                $drop[]    = $span['name'];
                $effective = (string) $span['less_than'];

                continue;
            }

            // The first partition that reaches past the cutoff. Report it when
            // it also holds older rows, so the caller can say why the boundary
            // reached is earlier than the one asked for.
            if ( $straddling === null && (string) $span['start'] < $cutoff ) {

                $straddling = $span;
            }
        }

        return array( 'drop' => $drop, 'effective' => $effective, 'straddling' => $straddling );
    }

    /**
     * Change a table's partition granularity.
     *
     * Existing partitions and target ranges are walked together and grouped
     * into the smallest chunks whose boundaries agree, so each REORGANIZE
     * rewrites only the periods it has to. A partition that already matches the
     * target exactly is left untouched, which makes re-running this a no-op.
     *
     * @param string $table_name
     * @param string $granularity  daily|weekly|monthly
     * @param bool   $dry_run      report the statements without running them
     * @return array ['changed' => string[], 'skipped' => int, 'failed' => string[]]
     */
    function repartitionTable( $table_name, $granularity, $dry_run = false ) {

        $result = array( 'changed' => array(), 'skipped' => 0, 'failed' => array() );

        $spans = $this->getPartitionSpans( $table_name );

        if ( ! $spans ) {

            return $result;
        }

        $span_start = $spans[0]['start'];
        $span_end   = $spans[ count( $spans ) - 1 ]['less_than'];

        $target = self::makePartitionRangesForSpan( $span_start, $span_end, $granularity );

        if ( ! $target ) {

            return $result;
        }

        // Cut only where both sequences agree on a boundary. Every such cut
        // consumes at least one partition from each side, and the span end is
        // always shared, so this terminates.
        $existing_bounds = array();

        foreach ( $spans as $s ) {

            $existing_bounds[ (string) $s['less_than'] ] = true;
        }

        $cuts = array();

        foreach ( $target as $less_than ) {

            if ( isset( $existing_bounds[ (string) $less_than ] ) ) {

                $cuts[] = (string) $less_than;
            }
        }

        $i = 0; // index into $spans
        $j = 0; // index into target, as a list
        $t_names = array_keys( $target );

        foreach ( $cuts as $cut ) {

            $from = array();
            $into = array();

            while ( $i < count( $spans ) && (string) $spans[ $i ]['less_than'] <= $cut ) {

                $from[] = $spans[ $i ]['name'];
                $i++;
            }

            while ( $j < count( $t_names ) && (string) $target[ $t_names[ $j ] ] <= $cut ) {

                $into[ $t_names[ $j ] ] = $target[ $t_names[ $j ] ];
                $j++;
            }

            if ( ! $from || ! $into ) {

                continue;
            }

            // Already in the target shape: nothing to rewrite.
            if ( count( $from ) === 1 && count( $into ) === 1 && key( $into ) === $from[0] ) {

                $result['skipped']++;
                continue;
            }

            if ( $dry_run ) {

                $result['changed'][] = sprintf( '%s -> %s', implode( ',', $from ), implode( ',', array_keys( $into ) ) );
                continue;
            }

            if ( $this->reorganizePartitions( $table_name, $from, $into ) ) {

                $result['changed'][] = sprintf( '%s -> %s', implode( ',', $from ), implode( ',', array_keys( $into ) ) );

            } else {

                $result['failed'][] = implode( ',', $from );
            }
        }

        return $result;
    }

    /**
     * Adds index to a column
     *
     * Does nothing when an index over the same columns is already present.
     * addIndex() ran unnamed, so MySQL assigned a name each time and repeated
     * calls -- an update re-run, or two updates covering the same table --
     * silently accumulated duplicate copies rather than failing.
     */
    function addIndex($table_name, $column_name, $index_definition = '') {

        $cols = $this->normalizeIndexColumns( $column_name );

        if ( ! $cols || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Refusing to index %s (%s): not a bare identifier.', $table_name, $column_name ) );

            return false;
        }

        if ( $this->indexExists( $table_name, $column_name ) ) {

            return true;
        }

        // Name it, so a later run is recognisable and this stays diagnosable.
        // MySQL caps identifiers at 64 characters.
        $index_name = substr( 'idx_' . implode( '_', $cols ), 0, 64 );

        return $this->query(
            sprintf( OWA_SQL_ADD_NAMED_INDEX, $table_name, $index_name, implode( ', ', $cols ), $index_definition )
        );
    }

    /**
     * Adds index to a column
     *
     */
    function dropIndex($table_name, $column_name) {

        return $this->query(sprintf(OWA_SQL_DROP_INDEX, $column_name, $table_name));
    }

    /**
     * Creates a new table
     *
     */
    function createTable($entity) {

        //create column defs

        // A partitioned table cannot carry the primary key inline: the
        // partitioning column has to be part of it, so it is declared at table
        // level below. Only when the driver can actually partition.
        $partition_column = null;

        if ( method_exists( $entity, 'getPartitionColumn' ) && $this->supportsPartitioning() ) {

            $partition_column = $entity->getPartitionColumn();
        }

        $all_cols = $entity->getColumns();

        $columns = '';

        $table_defs = '';

        $i = 0;
        $count = count($all_cols);

        // Control loop

        foreach ($all_cols as $k => $v){

            // get column definition
            $columns .= $v.' '.$entity->getColumnDefinition($v, (bool) $partition_column);

            // Add commas to column statement
            if ($i < $count - 1):

                $columns .= ', ';

            endif;

            $i++;

        }

        // make table options
        $table_options = '';
        $options = $entity->getTableOptions();

        // table type
        switch ($options['table_type']) {

            case "disk":
                $table_type = OWA_DTD_TABLE_TYPE_DISK;
                break;
            case "memory":
                $table_type = OWA_DTD_TABLE_TYPE_MEMORY;
                break;
            default:
                $table_type = OWA_DTD_TABLE_TYPE_DEFAULT;

        }

        $table_options .= sprintf(OWA_DTD_TABLE_TYPE, $table_type);

        // character encoding type

        // just in case the propoerties is not i nthe array, add a default value.
        if (!array_key_exists('character_encoding', $options)) {

            $options['character_encoding'] = OWA_DTD_CHARACTER_ENCODING_UTF8;
        }

        $table_options .= sprintf(' ' . OWA_DTD_TABLE_CHARACTER_ENCODING, $options['character_encoding']);

        if ( $partition_column ) {

            $pk = $entity->getPrimaryKeyColumn();

            if ( $pk && $pk !== $partition_column ) {

                $columns .= sprintf( ', %s (%s, %s)', OWA_DTD_PRIMARY_KEY, $pk, $partition_column );
            }

            // Start with the month the table is created in; later periods are
            // added as they are needed, and everything beyond the last boundary
            // falls into the catch-all until then.
            $now = date( 'Ymd' );

            $table_options .= $this->makePartitionClause(
                $partition_column,
                self::makePartitionRanges( $now, $now, 'monthly' )
            );
        }

        return $this->query(sprintf(OWA_SQL_CREATE_TABLE, $entity->getTableName(), $columns, $table_options));
    }



    /**
     * Begins a SQL transaction statement
     *
     */
    function beginTransaction() {

        return $this->query(OWA_SQL_BEGIN_TRANSACTION);
    }

    /**
     * Ends a SQL transaction statement
     *
     */
    function endTransaction() {

        return $this->query(OWA_SQL_END_TRANSACTION);
    }

    function count($column_name) {

        return sprintf(OWA_SQL_COUNT, $column_name);
    }

    function sum($column_name) {

        return sprintf(OWA_SQL_SUM, $column_name);
    }

    function distinct($column_name) {

        return sprintf(OWA_SQL_DISTINCT, $column_name);
    }

    function division($numerator, $denominator) {

        return sprintf(OWA_SQL_DIVISION, $numerator, $denominator);
    }

    function round($value) {

        return sprintf(OWA_SQL_ROUND, $value);
    }

    function average($value) {

        return sprintf(OWA_SQL_AVERAGE, $value);
    }

    function getAffectedRows() {

        return false;
    }
    
    function isValidSortDirectionValue( $value ) {
        
        return in_array( strtolower( $value ), [ strtolower( OWA_SQL_DESCENDING ), strtolower( OWA_SQL_ASCENDING) ] ); 
    }
    
    function getDefaultSortDirection() {
        
        return OWA_SQL_DESCENDING;
    }
}

?>
