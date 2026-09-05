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

    /**
     * Values bound to the statement being built, in placeholder order.
     *
     * ORDER IS THE WHOLE CONTRACT. Placeholders are positional, so binding N
     * must be the Nth `?` in the finished SQL. That holds because the clause
     * makers are evaluated as sprintf() ARGUMENTS in generateSelectQuerySql(),
     * and PHP evaluates arguments left to right in the order the format string
     * lays them out -- so the order they append in is the order they appear in.
     * A future rearrangement that builds clauses into variables first, then
     * assembles them in a different order, would silently transpose values
     * between columns. DbBindingTest pins the order for exactly this reason.
     *
     * @var array
     */
    var $_bindings = array();

    /**
     * How many query errors one process will spell out before going quiet.
     *
     * A broken database fails every statement, and this log is on disk on the
     * same box. The cap bounds a bad minute to a readable handful of lines
     * instead of one per query, and says so when it stops rather than just
     * stopping.
     */
    const QUERY_ERROR_LOG_LIMIT = 25;

    /** @var int */
    protected static $query_error_count = 0;

    /**
     * Report a statement the database refused.
     *
     * This used to be $this->e->debug(), which under the production error
     * handler is not written anywhere -- so a refused write produced NOTHING.
     * That is how a strict sql_mode silently dropped page views on a live
     * installation: the INSERT failed, the failure propagated as a false return
     * that the tracking path does not inspect, and no log line existed to
     * contradict the impression that everything was fine.
     *
     * Verified against the production handler: notice, warning and error are
     * written; debug is not.
     *
     * The query is truncated because some of them are enormous, and the error
     * message is what identifies the problem -- the SQL is context. The full
     * statement is still emitted at debug for anyone running the development
     * handler.
     *
     * @param string $message  what the database said
     * @param string $sql      the statement it refused
     * @return void
     */
    protected function logQueryError( $message, $sql, $is_constraint_violation = false ) {

        // Full detail for development, unconditionally: this is the level that
        // was already being used, so nothing that worked before is lost.
        $this->e->debug( sprintf( 'A database error occurred. Error: %s. Query: %s', $message, $sql ) );

        self::$query_error_count++;

        if ( self::$query_error_count > self::QUERY_ERROR_LOG_LIMIT ) {

            return;
        }

        $truncated = strlen( $sql ) > 500 ? substr( $sql, 0, 500 ) . ' ...[truncated]' : $sql;

        // A constraint violation is usually not a fault, and reporting it as
        // one would cry wolf on every busy site.
        //
        // Dimension rows are written check-then-insert: load by id, and insert
        // if it was not there. Two concurrent first-hits on the same new URL,
        // user agent or host both miss and both insert, and one of them loses.
        // The row exists either way, which is the whole point of the insert, so
        // the losing request has nothing to fix.
        //
        // Logged rather than dropped, because a duplicate rate that climbs is
        // worth seeing -- just not at a level that says something is broken.
        if ( $is_constraint_violation ) {

            $this->e->notice( sprintf(
                'A constraint stopped a statement, which is expected where rows are inserted '
              . 'concurrently. Error: %s. Query: %s',
                $message,
                $truncated
            ) );

            return;
        }

        $this->e->err( sprintf(
            'The database refused a statement. Error: %s. Query: %s',
            $message,
            $truncated
        ) );

        if ( self::$query_error_count === self::QUERY_ERROR_LOG_LIMIT ) {

            $this->e->err( sprintf(
                'Further query errors in this process will not be logged (%d already reported). '
              . 'This is a cap on log volume, not a sign that the errors stopped.',
                self::QUERY_ERROR_LOG_LIMIT
            ) );
        }
    }

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
    /**
     * Record a value for binding and return the placeholder that stands for it.
     *
     * Replaces interpolating an escaped value into the SQL text. The value never
     * becomes part of the statement, so it cannot change its structure -- which
     * is the difference between being safe by construction and being safe
     * because every caller remembered to call prepare().
     *
     * @param mixed $value
     * @return string
     */
    function bindValue( $value ) {

        // NULL is bound as a real SQL NULL.
        //
        // The escaping path could not do this: prepare( null ) returned null and
        // sprintf( "'%s'", null ) produced '', so every unset value was written
        // as an empty string and a permissive sql_mode coerced that to 0 in a
        // numeric column. That is the coercion the strict-mode work exists to
        // remove -- under STRICT_ALL_TABLES the same write is rejected outright
        // with "Incorrect integer value: '' for column ...".
        //
        // Writing NULL means callers must stop relying on the coercion. The one
        // that did is owa_queue_item.not_before_timestamp, whose due check now
        // reads a missing value as "due now" rather than depending on '' having
        // silently become 0. See DbEventQueue::getNextItems().
        $this->_bindings[] = $value;

        return '?';
    }

    /**
     * Discard bindings from any previous statement.
     *
     * Called at the START of each generation entry point rather than only after
     * execution, because SQL can be generated WITHOUT being run --
     * generateSelectQuerySql() is public and the parity tests use it that way.
     * Without this, bindings from a discarded statement would prepend
     * themselves to the next one.
     */
    function resetBindings() {

        $this->_bindings = array();
    }

    function getBindings() {

        return $this->_bindings;
    }

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

        $this->resetBindings();
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        $params = $this->_fetchSqlParams('set_values');

        $count = count($params);

        $i = 0;

        $sql_cols = '';
        $sql_values = '';

        foreach ($params as $k => $v) {

            $sql_cols .= $v['name'];
            $sql_values .= $this->bindValue( $v['value'] );

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

        $this->resetBindings();

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

        $this->resetBindings();

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

            $set .= $this->prepare( $v['name'] ) . ' = ' . $this->bindValue( $v['value'] );

            $i++;
        }

        $this->_setSql(sprintf(OWA_SQL_UPDATE_ROW, $this->_sqlParams['table'], $set, $this->_makeWhereClause()));

        return $this->_query();
    }

    function _deleteQuery() {

        $this->resetBindings();

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
                        $constraint .= sprintf("%s = %s", $this->prepare( $v['name'] ), $this->bindValue( $v['value'] ) );
                        break;

                    case 'between':
                        $constraint .= sprintf("%s BETWEEN %s AND %s", $this->prepare( $v['name'] ), $this->bindValue( $v['value']['start'] ), $this->bindValue( $v['value']['end'] ) );
                        break;

                    case '=~':
                        $constraint .= sprintf("%s %s %s", $this->prepare( $v['name'] ), OWA_SQL_REGEXP, $this->bindValue( $v['value'] ) );
                        break;

                    case '!~':
                        $constraint .= sprintf("%s %s %s",$this->prepare( $v['name'] ), OWA_SQL_NOTREGEXP, $this->bindValue( $v['value'] ) );
                        break;

                    case '=@':
                        // Dialect-owned, like =~ and !~ above: the expression
                        // for "contains" is not the same SQL everywhere.
                        $constraint .= sprintf( OWA_SQL_CONTAINS, $this->bindValue( $v['value'] ), $this->prepare( $v['name'] ) );
                        break;

                    case '!@':
                        $constraint .= sprintf( OWA_SQL_NOT_CONTAINS, $this->bindValue( $v['value'] ), $this->prepare( $v['name'] ) );
                        break;

                    default:
                        // $op has already been validated against ALLOWED_OPERATORS,
                        // so this covers '=', '!=', '>', '>=', '<', '<='.
                        $constraint .= sprintf("%s %s %s",$this->prepare( $v['name'] ), $op, $this->bindValue( $v['value'] ) );
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

    /**
     * Escape a value for interpolation into a statement.
     *
     * Every driver must implement this, because escaping is a property of the
     * connection -- it depends on the server's character set, which only the
     * driver knows. There is no correct database-agnostic version, so the base
     * class refuses rather than offering a plausible-looking one.
     *
     * It used to offer Sanitize::stripSql(), which was not escaping at all: it
     * deleted parentheses, commas, and any word matching a list of SQL keywords
     * from the value. That is not a defence -- values were already escaped by
     * the real drivers -- and it silently corrupted data whenever it was
     * reached. It ran on this installation's user agents for six months, giving
     * every browser two identities and roughly quadrupling the cardinality of
     * the user-agent dimension.
     *
     * A third-party driver at plugins/db/ that reaches this is not escaping its
     * values, which is a defect worth hearing about immediately rather than
     * discovering later.
     *
     * @param string $string
     * @throws \RuntimeException always
     */
    function prepare ( $string ) {

        throw new \RuntimeException( sprintf(
            '%s does not implement prepare(). A database driver must escape values itself, '
          . 'because escaping depends on the connection character set. Implement prepare() '
          . 'on the driver.',
            get_class( $this )
        ) );
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

                $ret = $this->query($this->_sql_statement, $this->_bindings);
                break;
            case 'select':

                $ret = $this->get_results($this->_sql_statement, $this->_bindings);

                if (array_key_exists('result_format', $this->_sqlParams)):
                    $ret = $this->_formatResults($ret);
                endif;

                break;

            case 'update':

                $ret = $this->query($this->_sql_statement, $this->_bindings);
                break;
            case 'delete':

                $ret = $this->query($this->_sql_statement, $this->_bindings);
                break;
        }

        $this->_last_sql_statement = $this->_sql_statement;
        $this->_sql_statement = '';
        $this->_sqlParams = array();
        $this->resetBindings();
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
     * The columns of a table's primary key. Unknown, without introspection.
     *
     * @param string $table_name
     * @return string[]
     */
    function getPrimaryKeyColumns( $table_name ) {

        return array();
    }

    /**
     * Spare open-file slots on this server. Unknown, without introspection.
     *
     * @return int|null
     */
    function getPartitionBudget() {

        return null;
    }

    /**
     * The scheduler's lock table, resolved through the entity so the namespace
     * prefix is not duplicated here.
     *
     * @return string
     */
    protected function jobLockTable() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.job_lock' )->getTableName();
    }

    /**
     * Take a job lock. Fails, without raising, when someone already holds it.
     *
     * A plain INSERT: job_name is the primary key, so the database itself
     * decides the race and the loser simply gets a falsy result. That is what
     * makes this portable -- no advisory locks, no SELECT ... FOR UPDATE, no
     * engine-specific syntax.
     *
     * @param string $job_name
     * @param string $owner
     * @param int    $now
     * @param int    $expires_at
     * @return bool
     */
    function insertJobLock( $job_name, $owner, $now, $expires_at ) {

        return (bool) $this->query( sprintf(
            "INSERT INTO %s (job_name, owner, acquired_at, expires_at) VALUES ('%s', '%s', %d, %d)",
            $this->jobLockTable(),
            $this->prepare( (string) $job_name ),
            $this->prepare( (string) $owner ),
            (int) $now,
            (int) $expires_at
        ) );
    }

    /**
     * Clear an abandoned lock for one job.
     *
     * Scoped to a genuinely expired row, so it can never disturb a live holder.
     * This is the only thing that ever frees a lock left behind by a process
     * that died -- there is no reaper and no timer.
     *
     * @param string $job_name
     * @param int    $now
     * @return bool
     */
    function deleteJobLock( $job_name, $now ) {

        return (bool) $this->query( sprintf(
            "DELETE FROM %s WHERE job_name = '%s' AND expires_at <= %d",
            $this->jobLockTable(),
            $this->prepare( (string) $job_name ),
            (int) $now
        ) );
    }

    /**
     * Extend a lock we still hold.
     *
     * Scoped to the owner token: a run that has already been taken over must
     * not be able to extend a lock it no longer owns.
     *
     * @param string $job_name
     * @param string $owner
     * @param int    $expires_at
     * @return bool
     */
    function refreshJobLock( $job_name, $owner, $expires_at ) {

        return (bool) $this->query( sprintf(
            "UPDATE %s SET expires_at = %d WHERE job_name = '%s' AND owner = '%s'",
            $this->jobLockTable(),
            (int) $expires_at,
            $this->prepare( (string) $job_name ),
            $this->prepare( (string) $owner )
        ) );
    }

    /**
     * Release a lock, but only our own.
     *
     * A job that overran its lease and was taken over finds its token no longer
     * matches and deletes nothing, which is correct: a late finisher must not
     * yank the lock from the run that replaced it.
     *
     * @param string $job_name
     * @param string $owner
     * @return bool
     */
    function releaseJobLock( $job_name, $owner ) {

        return (bool) $this->query( sprintf(
            "DELETE FROM %s WHERE job_name = '%s' AND owner = '%s'",
            $this->jobLockTable(),
            $this->prepare( (string) $job_name ),
            $this->prepare( (string) $owner )
        ) );
    }

    /**
     * The lock row for a job, for reporting. Null when unheld.
     *
     * @param string $job_name
     * @return array|null
     */
    function getJobLock( $job_name ) {

        $row = $this->get_row( sprintf(
            "SELECT job_name, owner, acquired_at, expires_at FROM %s WHERE job_name = '%s'",
            $this->jobLockTable(),
            $this->prepare( (string) $job_name )
        ) );

        return $row ? (array) $row : null;
    }

    /**
     * Row count and date range within one partition. Unknown, without support.
     *
     * @param string $table_name
     * @param string $partition
     * @param string $column
     * @return array|null
     */
    function getPartitionContents( $table_name, $partition, $column = 'yyyymmdd' ) {

        return null;
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
    /**
     * Partitions per table beyond which an operation asks to be confirmed.
     *
     * Each partition is a file, and they are shared with every other table
     * through innodb_open_files, which is typically 4,000. Past that limit
     * MySQL closes and reopens tablespaces under load, which degrades
     * everything on the instance, and metadata operations slow in proportion to
     * the count. Quarter-month over a decade is 480 per table, so this is
     * reachable without trying.
     *
     * It is a prompt, not a ceiling: force=1 is there for someone who has done
     * the arithmetic.
     */
    const PARTITION_COUNT_LIMIT = 400;

    /**
     * How many whole future months of partitions to keep ahead of today.
     *
     * The catch-all exists so that a write past the last boundary is accepted
     * rather than rejected, but anything landing in it cannot be dropped by a
     * retention cutoff -- it has no upper bound, so it is never wholly older
     * than any date. Keeping a year of partitions ahead means the catch-all
     * stays empty in normal operation: every write finds a real bounded
     * partition, retention reaches the date asked for, and the periodic top-up
     * splits an empty catch-all, which is cheap. It also means a top-up that
     * stops running has a year of slack before anything is affected.
     */
    const PARTITION_MONTHS_AHEAD = 12;

    /**
     * Days of slack on the lower bound used to prune per-session queries.
     *
     * See factLowerBound(). Sized empirically against observed anomalies, not
     * from a known mechanism -- the one unexplained case measured in the field
     * was two days.
     */
    const FACT_LOWER_BOUND_SLACK_DAYS = 30;

    const PARTITION_CUTS = array(
        'monthly'       => array( 1 ),
        'half-month'    => array( 1, 16 ),
        'quarter-month' => array( 1, 8, 15, 22 ),
    );

    /**
     * The upper boundary a table needs for a given lead of whole future months.
     *
     * Counted from the start of the current month, so the result does not move
     * within a month: the current period plus the lead. Twelve months ahead of
     * mid-August 2026 is 1 September 2027 -- September 2026 through August 2027
     * being the twelve future months.
     *
     * @param int $months
     * @return string yyyymmdd
     */
    static function partitionLeadBoundary( $months = self::PARTITION_MONTHS_AHEAD ) {

        return date( 'Ymd', strtotime( date( 'Ym' ) . '01 +' . ( (int) $months + 1 ) . ' months' ) );
    }

    /**
     * Is this a granularity we can partition by?
     *
     * @param string $granularity
     * @return bool
     */
    public static function isPartitionGranularity( $granularity ) {

        return isset( self::PARTITION_CUTS[ $granularity ] );
    }

    /**
     * The day-of-month cut points for one month.
     *
     * @param \DateTimeImmutable $month  any day within the month
     * @param string $granularity
     * @return int[] ascending days of the month
     */
    private static function cutsForMonth( $month, $granularity ) {

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

        // Every unique key has to contain the partitioning column, so widen the
        // primary key first where it does not. A table built by createTable()
        // already has it; one that predates partitioning does not.
        $pk = $this->getPrimaryKeyColumns( $table_name );

        if ( $pk && ! in_array( $column, $pk, true ) ) {

            $widened = array_merge( $pk, array( $column ) );

            $ret = $this->query( sprintf(
                'ALTER TABLE %s DROP PRIMARY KEY, ADD PRIMARY KEY (%s)',
                $table_name, implode( ', ', $widened )
            ) );

            if ( ! $ret ) {

                return false;
            }
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
     * Indexes that duplicate another index on the same table, exactly.
     *
     * Same columns in the same order, same uniqueness, same type. The first by
     * name is kept and the rest are returned as removable, so the result is
     * stable and one index always survives for each column list.
     *
     * Reporting is separated from removing so the decision can be inspected --
     * and tested -- without touching the schema.
     *
     * @return array of ['t' => table, 'i' => index, 'cols' => column list, 'keeping' => index kept]
     */
    function getDuplicateIndexes( $only_table = null ) {

        $seen = array();
        $dupes = array();

        foreach ( $this->listIndexes() as $row ) {

            if ( $only_table !== null && $row['t'] !== $only_table ) {

                continue;
            }

            // Uniqueness and type are part of the identity: a unique index and a
            // non-unique one over the same columns are not copies of each other.
            $key = $row['t'] . "\0" . $row['cols'] . "\0" . $row['nu'] . "\0" . $row['ty'];

            if ( isset( $seen[ $key ] ) ) {

                $dupes[] = array(
                    't'       => $row['t'],
                    'i'       => $row['i'],
                    'cols'    => $row['cols'],
                    'keeping' => $seen[ $key ],
                );

            } else {

                $seen[ $key ] = $row['i'];
            }
        }

        return $dupes;
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
     * The range of the partitioning column that a known session's fact rows can
     * occupy, for queries that select them.
     *
     * Partition pruning reads only the partitioning column, so a query
     * constrained on session_id alone has to visit every partition. A row
     * belonging to a session cannot be older than the session itself, so the
     * session's own date is a lower bound that can never exclude a valid row --
     * it only tells the optimizer which partitions cannot possibly hold one.
     *
     * The date must be the one stored on the session row, which the server
     * assigned. Not the current event's date, which is later for a session
     * running past midnight, and not a date taken from an id, which the tracker
     * mints from the browser's clock.
     *
     * The slack is empirical, and deliberately generous, because the ways this
     * invariant can be broken are not fully known.
     *
     * Both dates are server-assigned from the same clock: an event takes its
     * timestamp in Event::__construct(), before it is persisted to the queue,
     * and yyyymmdd is derived from that timestamp -- so neither queue lag nor a
     * client clock can move them apart. On a 685,623-row installation four rows
     * were nonetheless dated before their session. Three are explained: a
     * sentinel session_id of -1, and two rows joined through a collided 32-bit
     * crc32 id, which is not a real session at all. The fourth, two days out
     * with a modern id, has no established cause.
     *
     * A month costs one extra partition on a monthly layout, which is cheap
     * against an anomaly whose mechanism has not been identified. Narrow it
     * only with evidence about that mechanism, not on the reasoning that both
     * dates ought to agree -- they ought to, and in one case did not.
     *
     * Returns null where the date is unusable, in which case the caller simply
     * does not constrain -- slower, never wrong.
     *
     * @param mixed $session_yyyymmdd
     * @return array|null ['start','end'] as yyyymmdd, or null where unusable
     */
    static function factDateRange( $session_yyyymmdd ) {

        $value = (string) $session_yyyymmdd;

        // Installations carry rows with a yyyymmdd of 0, and anything that is
        // not a plausible date cannot bound anything.
        if ( ! preg_match( '/^\d{8}$/', $value ) || $value <= '19700101' ) {

            return null;
        }

        $date = \DateTimeImmutable::createFromFormat( 'Ymd|', $value );

        if ( ! $date ) {

            return null;
        }

        // The upper bound is today, not the session's date: rows are added to a
        // session as it runs, and one replayed or backfilled later still lands
        // on the day it was processed. Nothing can be dated ahead of the server
        // clock that stamps it, so today closes the range -- with a day of
        // slack, which costs nothing and survives a forward clock step.
        //
        // Without it a floor alone leaves the whole lead in play, since every
        // future partition sits above it: on a table with two years of history
        // and a year ahead, 15 partitions of 37 rather than 2.
        $start = $date->modify( '-' . self::FACT_LOWER_BOUND_SLACK_DAYS . ' days' )->format( 'Ymd' );
        $end   = ( new \DateTimeImmutable( 'tomorrow' ) )->format( 'Ymd' );

        // A session dated in the future inverts the range, and BETWEEN would
        // then match nothing at all -- turning a summary into a zero rather
        // than a slow query. Refuse to bound instead.
        if ( $start > $end ) {

            return null;
        }

        return array( 'start' => $start, 'end' => $end );
    }

    /**
     * factDateRange() as a constraint array, ready to hand to a lookup.
     *
     * Empty where no usable range exists, so a caller can pass it
     * unconditionally and simply get an unconstrained query.
     *
     * @param mixed $yyyymmdd
     * @return array
     */
    static function factDateConstraint( $yyyymmdd ) {

        $range = self::factDateRange( $yyyymmdd );

        if ( ! $range ) {

            return array();
        }

        return array( 'yyyymmdd' => array( 'value' => $range, 'operator' => 'between' ) );
    }

    /**
     * The range a session's fact rows can occupy, derived from the session id
     * alone, for callers that have no date at all.
     *
     * Ids minted by generateRandomUid() and by the tracker's matching JS begin
     * with a unix timestamp, so the id carries roughly when its session began.
     * "Roughly" is the operative word: the tracker mints session, visitor and
     * domstream ids from the BROWSER's clock, which is not ours. Measured
     * across two installations of 193,057 and 282,109 tracker-minted sessions,
     * a window of two days either side covers 99.93% and 99.91% of them; the
     * tail runs to 5,707 and 88,421 days out, which is a clock set to the wrong
     * decade rather than drift.
     *
     * So this is a hint and never an answer. A caller MUST fall back to an
     * unbounded query when the bounded one finds nothing, or it will lose rows
     * for whoever has the wrong clock. Prefer factDateRange() wherever a
     * server-assigned date is in reach -- it is exact, and it cannot be
     * influenced from outside.
     *
     * @param mixed $id
     * @param int   $days  window either side
     * @return array|null ['start','end'] as yyyymmdd, or null where unusable
     */
    static function factDateRangeFromId( $id, $days = 2 ) {

        $id = (string) $id;

        // Only the timestamp-prefixed form carries a date. A crc32-era id is
        // a hash and its leading digits mean nothing.
        if ( ! preg_match( '/^\d{19}$/', $id ) ) {

            return null;
        }

        $seconds = (int) substr( $id, 0, 10 );

        if ( $seconds <= 0 ) {

            return null;
        }

        $date = new \DateTimeImmutable( '@' . $seconds );
        $date = $date->setTimezone( new \DateTimeZone( date_default_timezone_get() ) );

        return array(
            'start' => $date->modify( '-' . (int) $days . ' days' )->format( 'Ymd' ),
            'end'   => $date->modify( '+' . (int) $days . ' days' )->format( 'Ymd' ),
        );
    }

    /**
     * Work out the granularity a table is already using.
     *
     * Taken from the boundaries rather than the partition names. The names
     * encode the same dates -- p20261008 is the period starting on the 8th --
     * but a name is a label this class chose, while VALUES LESS THAN is what
     * MySQL enforces and what actually decides where a row goes. Where the two
     * could disagree, the boundary is the truth.
     *
     * Since every cut is a day of the month, the days a month is cut on are
     * exactly the granularity's entry in PARTITION_CUTS, so this is a lookup
     * rather than a calculation.
     *
     * Only the most recent month is considered. A table is allowed to be coarse
     * in history and finer over recent periods, and it is the recent end that
     * says what new periods should look like.
     *
     * @param string $table_name
     * @return string|null  null where it does not match a known scheme
     */
    function inferPartitionGranularity( $table_name ) {

        $spans = $this->getPartitionSpans( $table_name );

        if ( ! $spans ) {

            return null;
        }

        $month = substr( $spans[ count( $spans ) - 1 ]['start'], 0, 6 );
        $days  = array();

        foreach ( $spans as $span ) {

            if ( substr( $span['start'], 0, 6 ) === $month ) {

                $days[] = (int) substr( $span['start'], 6, 2 );
            }
        }

        sort( $days );

        foreach ( self::PARTITION_CUTS as $granularity => $cuts ) {

            if ( $days === $cuts ) {

                return $granularity;
            }
        }

        return null;
    }

    /**
     * Name the period one partition spans.
     *
     * A tiered table does not have "a granularity": recent months are cut at the
     * granularity in force, older ones have been merged into blocks of months or
     * years to stay inside the open-file budget. Describing such a table with one
     * word is wrong in both directions -- it either overstates the resolution of
     * the tail or understates the head.
     *
     * A sub-month period is named by the granularity whose cuts it falls between,
     * so the vocabulary is the one the reorganize command takes. Anything longer
     * is named by its own length.
     *
     * @param string $start      yyyymmdd
     * @param string $less_than  yyyymmdd
     * @return string
     */
    static function describePartitionPeriod( $start, $less_than ) {

        $a = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $start );
        $b = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $less_than );

        if ( ! $a || ! $b || $b <= $a ) {

            return 'unknown';
        }

        // Whole months from the 1st: one calendar month is "monthly", and longer
        // blocks are named in years where they divide evenly, because that is how
        // the merged tail is built and how an operator thinks about it.
        if ( $a->format( 'd' ) === '01' && $b->format( 'd' ) === '01' ) {

            $months = ( (int) $b->format( 'Y' ) - (int) $a->format( 'Y' ) ) * 12
                    + ( (int) $b->format( 'n' ) - (int) $a->format( 'n' ) );

            if ( $months === 1 ) {

                return 'monthly';
            }

            if ( $months % 12 === 0 ) {

                $years = intdiv( $months, 12 );

                return $years === 1 ? '1 year' : $years . ' years';
            }

            return $months . ' months';
        }

        // Within a month: the granularity that cuts it here.
        foreach ( self::PARTITION_CUTS as $granularity => $cuts ) {

            $day  = (int) $a->format( 'j' );
            $at   = array_search( $day, $cuts, true );

            if ( $at === false ) {

                continue;
            }

            $next = isset( $cuts[ $at + 1 ] )
                  ? $a->setDate( (int) $a->format( 'Y' ), (int) $a->format( 'n' ), $cuts[ $at + 1 ] )
                  : $a->modify( 'first day of next month' );

            if ( $next->format( 'Ymd' ) === $b->format( 'Ymd' ) ) {

                return $granularity;
            }
        }

        return $a->diff( $b )->days . ' days';
    }

    /**
     * What a table's partitioning actually looks like right now.
     *
     * Reports the layout as tiers -- runs of adjacent partitions covering the
     * same length of time -- because that is the shape the commands produce and
     * a single granularity cannot describe it. The lead is separated out, since
     * how far the bounded partitions reach into the future is what determines
     * when the next rotate is due.
     *
     * Reads only metadata: no table is scanned. Catch-all contents are counted
     * separately, by the caller that wants them.
     *
     * @param string $table_name
     * @return array
     */
    function describePartitionLayout( $table_name ) {

        $out = array(
            'partitioned' => false,
            'spans'       => 0,
            'total'       => 0,
            'covers'      => null,
            'granularity' => null,
            'tiers'       => array(),
            'catch_all'   => null,
            'through'     => null,
            'ahead'       => null,
            'lead'        => 0,
        );

        $all = $this->listPartitions( $table_name );

        if ( ! $all ) {

            return $out;
        }

        $spans = $this->getPartitionSpans( $table_name );

        $out['partitioned'] = true;
        $out['total']       = count( $all );
        $out['spans']       = count( $spans );
        $out['catch_all']   = $this->getCatchAllPartition( $table_name );
        $out['granularity'] = $this->inferPartitionGranularity( $table_name );

        if ( ! $spans ) {

            // A catch-all and nothing else: everything is unbounded.
            return $out;
        }

        $out['covers']  = array( 'start' => $spans[0]['start'], 'end' => end( $spans )['less_than'] );
        $out['through'] = end( $spans )['less_than'];

        $today = date( 'Ymd' );

        foreach ( $spans as $span ) {

            $period = self::describePartitionPeriod( $span['start'], $span['less_than'] );
            $last   = count( $out['tiers'] ) - 1;

            if ( $last >= 0 && $out['tiers'][ $last ]['period'] === $period ) {

                $out['tiers'][ $last ]['count']++;
                $out['tiers'][ $last ]['end'] = $span['less_than'];

            } else {

                $out['tiers'][] = array(
                    'period' => $period,
                    'count'  => 1,
                    'start'  => $span['start'],
                    'end'    => $span['less_than'],
                );
            }

            // Lead is the partitions that begin after today: periods bought in
            // advance, which is what running out of them costs.
            if ( $span['start'] > $today ) {

                $out['lead']++;
            }
        }

        $a = \DateTimeImmutable::createFromFormat( 'Ymd|', $today );
        $b = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $out['through'] );

        if ( $a && $b ) {

            // Negative where the top boundary is already behind us, which is the
            // state that matters: new rows are landing in the catch-all.
            $out['ahead'] = (int) $a->diff( $b )->format( '%r%a' );
        }

        return $out;
    }

    /**
     * One row by id, optionally narrowed. Test seam for the constrained-load
     * behaviour in Entity::getByColumn(), which is otherwise reachable only
     * through the entity registry.
     *
     * @param string $table_name
     * @param mixed  $id
     * @param array  $constraints
     * @return array
     */
    function getOneRowFromTable( $table_name, $id, $constraints = array() ) {

        $this->selectFrom( $table_name );
        $this->selectColumn( '*' );
        $this->where( 'id', $id );

        foreach ( $constraints as $name => $constraint ) {

            $this->where( $name, $constraint['value'], $constraint['operator'] );
        }

        return (array) $this->getOneRow();
    }

    /**
     * The name of the catch-all partition, or null where there is none.
     *
     * @param string $table_name
     * @return string|null
     */
    function getCatchAllPartition( $table_name ) {

        foreach ( $this->listPartitions( $table_name ) as $p ) {

            if ( strtoupper( $p['less_than'] ) === OWA_DTD_PARTITION_MAXVALUE ) {

                return $p['name'];
            }
        }

        return null;
    }

    /**
     * Merge a run of adjacent partitions into one.
     *
     * The inverse of extendPartitions(): that splits, this combines. It exists so
     * that partition count can be kept under the server's open-file budget
     * WITHOUT deleting anything -- old periods are coarsened rather than dropped.
     * A table can then hold decades of history in a few dozen files.
     *
     * The replacement keeps the p<yyyymmdd> naming, because getPartitionSpans()
     * recovers the first partition's lower bound from its name and
     * inferPartitionGranularity() reads the day-of-month out of it. A partition
     * named anything else reads back as having no usable start.
     *
     * The partitions must be adjacent and given in order: REORGANIZE requires the
     * replacement to tile exactly the range being replaced, so the new upper
     * bound has to be the last one's.
     *
     * @param string $table_name
     * @param array  $names      partition names, in ascending order
     * @param string $start      yyyymmdd the merged partition begins at (its name)
     * @param string $less_than  yyyymmdd upper bound -- the last partition's
     * @return bool
     */
    function mergePartitions( $table_name, $names, $start, $less_than ) {

        if ( ! $this->supportsPartitioning() || count( $names ) < 2 ) {

            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        foreach ( $names as $n ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $n ) ) {

                return false;
            }
        }

        if ( ! preg_match( '/^\d{8}$/', (string) $start ) || ! preg_match( '/^\d{8}$/', (string) $less_than ) ) {

            return false;
        }

        $replacement = sprintf( OWA_DTD_PARTITION_LESS_THAN, 'p' . $start, $less_than );

        return (bool) $this->query( sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, implode( ',', $names ), $replacement
        ) );
    }

    /**
     * How old a period must be before it is eligible to be coarsened, in months.
     *
     * Inside this window partitions keep whatever granularity the table uses, so
     * recent reporting and recent retention stay precise. Outside it they may be
     * merged, because old periods are queried rarely and pruned in bulk.
     *
     * This is the code-level default. An installation overrides it with
     * OWA_PARTITION_DETAIL_MONTHS in owa-config.php; the commands read the
     * setting and pass the result in, so this value applies only to a direct
     * caller that supplies nothing.
     */
    const PARTITION_DETAIL_MONTHS = 36;

    /**
     * Fraction of the server's spare open-file slots this feature may claim,
     * expressed as a divisor: 2 means half of them.
     *
     * The reading is a snapshot of a shared resource -- every other table on the
     * instance draws on the same cap, and the schema grows -- so taking all of it
     * would be planning for a server that no longer exists by the time the
     * partitions are created.
     */
    const PARTITION_BUDGET_RESERVE = 2;   // default; OWA_PARTITION_BUDGET_RESERVE

    /**
     * Fewest partitions a table is allowed regardless of what the budget says.
     *
     * A server reporting almost no headroom would otherwise derive a limit that
     * refuses even a couple of years of monthly partitions, which is worse than
     * useless -- the feature would be unavailable exactly where retention matters
     * most.
     */
    const PARTITION_MIN_LIMIT = 24;       // default; OWA_PARTITION_MIN_LIMIT

    /**
     * Ranges for a table that is being partitioned for the first time: coarse
     * over old history, fine over the detail window and the lead.
     *
     * A flat monthly layout over a long-running installation asks for one
     * partition per month of history -- over three hundred on a twenty-five year
     * table -- which exceeds any reasonable open-file budget and leaves the
     * operator no way through, since monthly is already the coarsest granularity
     * and old data cannot be pruned before partitions exist to prune.
     *
     * Building the tiers up front avoids partitioning flat and merging
     * afterwards, which would mean writing every old row twice.
     *
     * Whole calendar years are used for the old tier because a year is the unit
     * an operator reasons about, and because retention still means something:
     * history ages out a year at a time. Lumping it all into one partition
     * instead would leave a table whose first routine retention run discards
     * decades in a single statement.
     *
     * @param string $min_yyyymmdd    oldest data present
     * @param string $through         upper boundary to reach (exclusive)
     * @param string $granularity     granularity for the detail window
     * @param int    $detail_months   how much recent history stays fine
     * @return array name => less_than, ascending
     */
    static function makeTieredPartitionRanges( $min_yyyymmdd, $through, $granularity, $detail_months, $limit = null ) {

        $boundary = date( 'Ymd', strtotime( date( 'Ym' ) . '01 -' . (int) $detail_months . ' months' ) );

        // Nothing old enough to coarsen: this is just the flat layout.
        if ( (string) $min_yyyymmdd >= $boundary ) {

            return self::makePartitionRanges(
                $min_yyyymmdd, date( 'Ymd', strtotime( $through . ' -1 day' ) ), $granularity
            );
        }

        $ranges = array();

        // Old tier: whole calendar years, except the last, which closes on the
        // month the detail window opens rather than on a January.
        //
        // That boundary has to be the one planPartitionCompaction() uses, or the
        // two disagree about which periods are old: init would leave the months
        // between January and the detail window as separate partitions, and the
        // next rotate would immediately merge them. The table would be rewritten
        // on every scheduled run, for no change in shape.
        $first_year = (int) substr( $min_yyyymmdd, 0, 4 );
        $last_year  = (int) substr( $boundary, 0, 4 );

        // Detail tier first, because its size decides how much room the tail has.
        $fine = self::makePartitionRanges(
            $boundary, date( 'Ymd', strtotime( $through . ' -1 day' ) ), $granularity
        );

        // Widen the tail blocks until the whole layout fits, exactly as
        // planPartitionCompaction() does. Building 1-year blocks regardless and
        // leaving the next rotation to coarsen them would mean the first
        // scheduled run rewrote everything init had just created.
        $years = max( 1, $last_year - $first_year + ( $boundary > $last_year . '0101' ? 1 : 0 ) );
        $block = 1;

        if ( $limit !== null ) {

            for ( ; $block < self::PARTITION_MAX_YEARS_PER_BLOCK; $block++ ) {

                if ( count( $fine ) + (int) ceil( $years / $block ) <= $limit ) {

                    break;
                }
            }
        }

        for ( $y = $first_year; ; $y += $block ) {

            $end = ( $y + $block ) . '0101';

            if ( $end >= $boundary ) {

                $end = $boundary;
            }

            $ranges[ 'p' . $y . '0101' ] = $end;

            if ( $end >= $boundary ) {

                break;
            }
        }

        return array_merge( $ranges, $fine );
    }

    /**
     * Replace a run of adjacent partitions with a different set of ranges.
     *
     * The general form of both merging and splitting: REORGANIZE requires only
     * that the replacements tile exactly the span they replace, so N partitions
     * can become one, or one can become N, or five can become three.
     *
     * Splitting matters as much as merging. Without it the layout would depend
     * on the order operations happened in rather than on the current settings --
     * a table coarsened under a tight budget would stay coarse after the budget
     * grew, while an identical installation partitioned fresh would not. Same
     * inputs, different result.
     *
     * @param string $table_name
     * @param array  $names   partitions to replace, ascending and adjacent
     * @param array  $ranges  name => less_than, ascending, tiling the same span
     * @return bool
     */
    function reshapePartitions( $table_name, $names, $ranges ) {

        if ( ! $this->supportsPartitioning() || ! $names || ! $ranges ) {

            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        foreach ( $names as $n ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $n ) ) {

                return false;
            }
        }

        $parts = array();

        foreach ( $ranges as $name => $less_than ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $name )
              || ! preg_match( '/^\d{8}$/', (string) $less_than ) ) {

                return false;
            }

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        return (bool) $this->query( sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, implode( ',', $names ), implode( ', ', $parts )
        ) );
    }

    /**
     * Largest run of calendar years that may be merged into one partition.
     *
     * A cap, not a target. Without one, a budget that cannot be met would drive
     * the planner to collapse the entire tail into a single partition -- which
     * fits nothing better and destroys retention granularity for all of history,
     * since a partition can only be dropped as a unit. Better to return the best
     * plan available and report that it does not fit.
     */
    const PARTITION_MAX_YEARS_PER_BLOCK = 5;  // default; OWA_PARTITION_MAX_YEARS_PER_BLOCK

    /**
     * Work out the tail layout the current settings imply, and how to get there
     * from whatever the table looks like now.
     *
     * The result is a function of the budget and the detail window ALONE, never
     * of the order operations happened in. That is deliberate: a table coarsened
     * under a tight budget must go back to finer blocks when the budget grows,
     * or an installation's layout would depend on its history rather than its
     * configuration, and two identical installations could differ permanently.
     *
     * So this both merges and splits. Blocks are whole calendar years, widened
     * only as far as the budget requires and never past
     * PARTITION_MAX_YEARS_PER_BLOCK -- a cap, because a budget that cannot be
     * met would otherwise drive everything into one partition, which fits no
     * better and means all of history ages out in a single drop.
     *
     * Nothing is ever deleted. The detail window is never touched.
     *
     * @param string $table_name
     * @param int    $limit          most partitions this table may have
     * @param int    $detail_months  periods newer than this are left alone
     * @return array ['operations','projected','fits','floor','block_years']
     */
    function planPartitionCompaction( $table_name, $limit, $detail_months = self::PARTITION_DETAIL_MONTHS ) {

        $spans  = $this->getPartitionSpans( $table_name );
        $result = array(
            'operations'  => array(),
            'merges'      => array(),
            'projected'   => count( $spans ),
            'fits'        => true,
            'floor'       => count( $spans ),
            'block_years' => 1,
        );

        if ( ! $spans ) {

            return $result;
        }

        $boundary = date( 'Ymd', strtotime( date( 'Ym' ) . '01 -' . (int) $detail_months . ' months' ) );

        $old = array();

        foreach ( $spans as $span ) {

            if ( (string) $span['less_than'] <= $boundary ) {

                $old[] = $span;
            }
        }

        $kept            = count( $spans ) - count( $old );
        $result['floor'] = $kept + ( $old ? 1 : 0 );

        if ( ! $old ) {

            $result['fits'] = count( $spans ) <= $limit;

            return $result;
        }

        // The tail runs from the first old partition to wherever the last one
        // ends -- which is usually mid-year, because the detail window opens on
        // a month rather than a January. The final block therefore closes on
        // that boundary rather than on a year end, or the remaining months would
        // belong to no block and stay unmerged.
        $tail_start = (int) substr( $old[0]['start'], 0, 4 );
        $tail_limit = (string) $old[ count( $old ) - 1 ]['less_than'];

        $years = max( 1, (int) ceil(
            ( strtotime( $tail_limit ) - strtotime( $tail_start . '0101' ) ) / ( 86400 * 365.25 )
        ) );

        // Smallest block size that fits, capped. One year is the finest the tail
        // is ever cut to: below that it is the detail window's job.
        $block = 1;

        for ( ; $block < self::PARTITION_MAX_YEARS_PER_BLOCK; $block++ ) {

            if ( $kept + (int) ceil( $years / $block ) <= $limit ) {

                break;
            }
        }

        $result['block_years'] = $block;

        // The layout those settings imply.
        $target = array();

        for ( $y = $tail_start; ; $y += $block ) {

            $end = ( $y + $block ) . '0101';

            if ( $end >= $tail_limit ) {

                $end = $tail_limit;
            }

            $target[ 'p' . $y . '0101' ] = $end;

            if ( $end >= $tail_limit ) {

                break;
            }
        }

        $result['projected'] = $kept + count( $target );
        $result['fits']      = $result['projected'] <= $limit;

        // Already in that shape? Then there is nothing to do, however many times
        // this runs.
        $current = array();

        foreach ( $old as $span ) {

            $current[ $span['name'] ] = (string) $span['less_than'];
        }

        if ( $current === array_map( 'strval', $target ) ) {

            return $result;
        }

        // One reorganisation per target block, over whichever partitions it
        // covers. That merges where the tail is too fine and splits where it is
        // too coarse, in the same operation.
        $names_by_block = array();

        foreach ( $target as $name => $less_than ) {

            $from = substr( $name, 1 );

            $names_by_block[ $name ] = array(
                'names'     => array(),
                'start'     => $from,
                'less_than' => $less_than,
                'ranges'    => array( $name => $less_than ),
            );
        }

        foreach ( $old as $span ) {

            foreach ( $names_by_block as $name => &$block_def ) {

                // A partition belongs to the block its start falls in. A block
                // that is coarser than the target is claimed by the first target
                // block it overlaps, and split by the reorganisation.
                if ( (string) $span['start'] >= $block_def['start']
                  && (string) $span['start'] < $block_def['less_than'] ) {

                    $block_def['names'][] = $span['name'];

                    if ( (string) $span['less_than'] > $block_def['less_than'] ) {

                        // This partition reaches past the block, so the block and
                        // everything it spans are reshaped together.
                        $block_def['less_than'] = (string) $span['less_than'];
                    }

                    break;
                }
            }

            unset( $block_def );
        }

        foreach ( $names_by_block as $name => $block_def ) {

            if ( ! $block_def['names'] ) {

                continue;
            }

            // Ranges covering exactly what the named partitions span.
            $ranges = array();

            for ( $y = (int) substr( $block_def['start'], 0, 4 ); ; $y += $block ) {

                $end = ( $y + $block ) . '0101';

                if ( $end >= $block_def['less_than'] ) {

                    $end = (string) $block_def['less_than'];
                }

                $ranges[ 'p' . $y . '0101' ] = $end;

                if ( $end >= $block_def['less_than'] ) {

                    break;
                }
            }

            if ( ! $ranges ) {

                continue;
            }

            // No change needed where the shape already matches.
            $same = ( count( $block_def['names'] ) === count( $ranges ) );

            if ( $same ) {

                $i = 0;

                foreach ( $ranges as $rname => $rless ) {

                    if ( $block_def['names'][ $i ] !== $rname ) {

                        $same = false;

                        break;
                    }

                    $i++;
                }
            }

            if ( $same ) {

                continue;
            }

            $result['operations'][] = array(
                'names'  => $block_def['names'],
                'ranges' => $ranges,
                'start'  => $block_def['start'],
                'less_than' => $block_def['less_than'],
            );
        }

        // Kept for callers that only care about the coarsening case.
        $result['merges'] = $result['operations'];

        return $result;
    }

    /**
     * Add whatever partitions are missing between the last boundary and a date.
     *
     * The new periods are cut out of the catch-all, which is the only partition
     * holding anything above the last boundary, and a fresh catch-all is put
     * back on the end so writes beyond the new range are still accepted. Where
     * the catch-all is empty -- the normal case when this is run often enough --
     * the rewrite moves no rows.
     *
     * Doing nothing when the range is already covered is what makes this safe
     * to run on a schedule: the result depends on the date, not on how many
     * times it has run.
     *
     * @param string $table_name
     * @param string $granularity
     * @param string $through      yyyymmdd the partitions must reach
     * @param bool   $dry_run
     * @return array ['added','planned','top','covered']
     */
    function extendPartitions( $table_name, $granularity, $through, $dry_run = false ) {

        $result = array( 'added' => array(), 'planned' => 0, 'top' => null, 'covered' => false );

        $spans     = $this->getPartitionSpans( $table_name );
        $catch_all = $this->getCatchAllPartition( $table_name );

        if ( ! $spans || ! $catch_all ) {

            return $result;
        }

        $top = $spans[ count( $spans ) - 1 ]['less_than'];

        $result['top'] = (string) $top;

        if ( (string) $top >= (string) $through ) {

            $result['covered'] = true;

            return $result;
        }

        $ranges = self::makePartitionRangesForSpan( $top, $through, $granularity );

        if ( ! $ranges ) {

            return $result;
        }

        $result['planned'] = count( $ranges );

        if ( $dry_run ) {

            $result['added'] = array_keys( $ranges );

            return $result;
        }

        // The replacements must tile exactly what they replace, and the
        // catch-all reaches to MAXVALUE, so one has to go back on the end.
        $parts = array();

        foreach ( $ranges as $name => $less_than ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $catch_all, OWA_DTD_PARTITION_MAXVALUE );

        $sql = sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, $catch_all, implode( ', ', $parts )
        );

        if ( $this->query( $sql ) ) {

            $result['added'] = array_keys( $ranges );
        }

        return $result;
    }

    /**
     * Which partitions hold only data older than a cutoff.
     *
     * A partition is droppable only when everything in it precedes the cutoff,
     * so a partition straddling that date is kept: dropping it would remove
     * data on or after the date, which is more than was asked for. With
     * partitions being periods rather than days that means the boundary actually
     * reached is usually earlier than the one requested, so it is reported --
     * 'effective' is the date before which data no longer exists once these are
     * dropped.
     *
     * The catch-all is never droppable: it has no upper bound, and it holds
     * current traffic.
     *
     * A cutoff later than today is clamped to today. Data up to and including
     * today is being written right now, so no cutoff may reach it -- a date in
     * the future is a mistyped year, not a request to discard the current
     * period. With the cutoff clamped, the partition holding today straddles it
     * and is kept by the rule above, which leaves exactly the current period in
     * place. 'requested' reports the original where it differed, so the caller
     * can say the date was not taken literally.
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
        $requested  = null;
        $today      = date( 'Ymd' );

        if ( $cutoff > $today ) {

            $requested = $cutoff;
            $cutoff    = $today;
        }

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

        return array(
            'drop'       => $drop,
            'effective'  => $effective,
            'straddling' => $straddling,
            'requested'  => $requested,
        );
    }

    /**
     * Change a table's partition granularity.
     *
     * Existing partitions and target ranges are walked together and grouped
     * into the smallest chunks whose boundaries agree, so each REORGANIZE
     * rewrites only the periods it has to. A partition that already matches the
     * target exactly is left untouched, which makes re-running this a no-op.
     *
     * A range restricts it to part of the table, which is how an installation
     * ends up coarse for old data and fine for recent without carrying a
     * partition -- and so a file -- for every period of its whole history. The
     * range is snapped outwards to the boundaries of the partitions it touches,
     * since a partition can only be rewritten whole.
     *
     * @param string      $table_name
     * @param string      $granularity  quarter-month|half-month|monthly
     * @param bool        $dry_run      report the statements without running them
     * @param string|null $from         first day to convert, yyyymmdd
     * @param string|null $to           first day not to convert, yyyymmdd
     * @return array ['changed' => string[], 'skipped' => int, 'failed' => string[]]
     */
    function repartitionTable( $table_name, $granularity, $dry_run = false, $from = null, $to = null ) {

        $result = array( 'changed' => array(), 'skipped' => 0, 'failed' => array(), 'planned' => 0 );

        $spans = $this->getPartitionSpans( $table_name );

        if ( ! $spans ) {

            return $result;
        }

        // Leave coarsened history alone unless a range explicitly asks for it.
        //
        // A tail block spans whole years by design, to keep the partition count
        // within the server's open-file budget. Refining it back to the table's
        // granularity would undo that -- on a ten-year table, sixty-odd
        // partitions become nearly three hundred -- and would be the opposite of
        // what compaction just did. A partition covering at most one calendar
        // month is a normal period and is fair game; anything wider is a block.
        if ( $from === null && $to === null ) {

            $fine = array();

            foreach ( $spans as $span ) {

                $month_after = date( 'Ymd', strtotime( substr( $span['start'], 0, 6 ) . '01 +1 month' ) );

                if ( (string) $span['less_than'] <= $month_after ) {

                    $fine[] = $span;
                }
            }

            $spans = array_values( $fine );

            if ( ! $spans ) {

                return $result;
            }
        }

        // Keep only the partitions the range touches. A partition is rewritten
        // whole or not at all, so one that merely overlaps the range is
        // included and the range effectively widens to its boundaries.
        if ( $from !== null || $to !== null ) {

            $wanted = array();

            foreach ( $spans as $span ) {

                if ( $to !== null && (string) $span['start'] >= (string) $to ) {

                    continue;
                }

                if ( $from !== null && (string) $span['less_than'] <= (string) $from ) {

                    continue;
                }

                $wanted[] = $span;
            }

            $spans = array_values( $wanted );

            if ( ! $spans ) {

                return $result;
            }
        }

        $span_start = $spans[0]['start'];
        $span_end   = $spans[ count( $spans ) - 1 ]['less_than'];

        $target = self::makePartitionRangesForSpan( $span_start, $span_end, $granularity );

        if ( ! $target ) {

            return $result;
        }

        // What the table would end up with: the converted span, plus whatever
        // partitions the range left alone.
        $result['planned'] = count( $target ) + ( count( $this->getPartitionSpans( $table_name ) ) - count( $spans ) );

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

        // Index clauses are TABLE-level and are collected as we go. They used
        // to arrive glued onto the end of a column's definition, which is
        // legal only here -- and made addColumn() emit a syntax error for
        // every indexed column. See DbColumn::getDefinition().
        $indexes = array();

        foreach ($all_cols as $k => $v){

            // get column definition
            $columns .= $v.' '.$entity->getColumnDefinition($v, (bool) $partition_column);

            if ( $entity->isColumnIndexed( $v ) ) {

                $indexes[] = sprintf( 'INDEX (%s)', $v );
            }

            // Add commas to column statement
            if ($i < $count - 1):

                $columns .= ', ';

            endif;

            $i++;

        }

        if ( $indexes ) {

            $columns .= ', ' . implode( ', ', $indexes );
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

            // Cover the current month and a year ahead, so that the catch-all
            // stays empty until the lead runs down. partition-init tops this up
            // and is meant to run periodically; a table created and never
            // topped up still has a year before anything reaches the catch-all.
            $table_options .= $this->makePartitionClause(
                $partition_column,
                self::makePartitionRanges(
                    date( 'Ymd' ),
                    date( 'Ymd', strtotime( self::partitionLeadBoundary() . ' -1 day' ) ),
                    'monthly'
                )
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
