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

require_once(OWA_BASE_DIR.'/owa_base.php');

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
class owa_db extends owa_base {

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
     * @var unknown_type
     */
    var $_start_time;

    /**
     * Total Elapsed time of query
     *
     * @var unknown_type
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

        return parent::__construct();
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

      $mtime = microtime();
      //$mtime = explode(' ', $mtime); 
      //$this->_start_time = $mtime[1].substr(round($mtime[0], 4), 1);
    $this->_start_time = microtime();
    return;
    }

    /**
     * Ends the query microtimer and populates $this->_total_time
     *
     */
    function _timerEnd() {

        $mtime = microtime();
        //$mtime = explode(" ", $mtime);
        //$endtime = $mtime[1].substr(round($mtime[0], 4), 1);
        $endtime = microtime();
        //$this->_total_time = bcsub($endtime, $this->_start_time, 4);
        $this->_total_time = number_format(((substr($endtime,0,9)) + (substr($endtime,-10)) - (substr($this->_start_time,0,9)) - (substr($this->_start_time,-10))),6);

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

        if ( ! owa_lib::isEmpty( $value ) ) {

            // hack for intentional empty value
            if($value == ' '){
                $value = '';
            }

            $this->_sqlParams['where'][$name] = array('name' => $name, 'value' => $value, 'operator' => $operator);
        }
    }

    function having($name, $value, $operator = '=') {

        if ( ! owa_lib::isEmpty( $value ) ) {

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
                if ( ! owa_lib::isEmpty($v) ):

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
        return $ret[0];
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
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
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
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
        $this->_setSql(sprintf(OWA_SQL_INSERT_ROW, $this->_sqlParams['table'], $sql_cols, $sql_values));
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
        $ret = $this->_query();
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
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

                $cols .= ' as '.$v['as'];

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

            $set .= $v['name'] .' = \'' . $this->prepare($v['value']) . '\'';

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

    function _makeConstraintClause($type = 'WHERE', $params) {

        if ( ! empty( $params ) ) {

            $count = count( $params );
            $i = 0;

            $constraint = $type.' ';

            foreach ($params as $k => $v) {

                switch (strtolower($v['operator'])) {

                    case '==':
                        $constraint .= sprintf("%s = '%s'",$v['name'], $this->prepare( $v['value'] ) );
                        break;

                    case 'between':
                        $constraint .= sprintf("%s BETWEEN '%s' AND '%s'", $v['name'], $this->prepare( $v['value']['start'] ), $this->prepare( $v['value']['end'] ) );
                        break;

                    case '=~':
                        $constraint .= sprintf("%s %s '%s'",$v['name'], OWA_SQL_REGEXP, $this->prepare( $v['value'] ) );
                        break;

                    case '!~':
                        $constraint .= sprintf("%s %s '%s'",$v['name'], OWA_SQL_NOTREGEXP, $this->prepare( $v['value'] ) );
                        break;

                    case '=@':
                        $constraint .= sprintf("LOCATE('%s', %s) > 0",$v['value'], $this->prepare( $v['name'] ) );
                        break;

                    case '!@':
                        $constraint .= sprintf("LOCATE('%s', %s) = 0",$v['value'], $this->prepare( $v['name'] ) );
                        break;

                    default:
                        $constraint .= sprintf("%s %s '%s'",$v['name'], $v['operator'], $this->prepare( $v['value'] ) );
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

        return $string;
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
        if (!empty($sorts)):

            $order = $this->_fetchSqlParams('order');

            $i = 1;
            $sort_string = '';
            $count = count($sorts);
            foreach ($sorts as $sort) {

                // needed for backwards compatability.
                if (!isset($sort[1])) {
                    $sort[1] = $order;
                }

                $sort_string .= sprintf("%s %s",$sort[0], $sort[1]);
                if ($i < $count) {
                    $sort_string .= ', ';
                }

                $i++;
            }

            return sprintf("ORDER BY %s", $sort_string);

        else:
            return;
        endif;


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
                    return owa_lib::deconstruct_assoc($results);
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
     * Adds index to a column
     *
     */
    function addIndex($table_name, $column_name, $index_definition = '') {

        return $this->query(sprintf(OWA_SQL_ADD_INDEX, $table_name, $column_name, $index_definition));
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

        $all_cols = $entity->getColumns();

        $columns = '';

        $table_defs = '';

        $i = 0;
        $count = count($all_cols);

        // Control loop

        foreach ($all_cols as $k => $v){

            // get column definition
            $columns .= $v.' '.$entity->getColumnDefinition($v);

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
}

?>