<?php
namespace OWA\Core;

/**
 * Database Connection Class
 * 
 *
 * The query methods every driver implements (Db\Mysql, Db\Pdo); declared
 * here so code holding a Db can call them. A row comes back column => value.
 * Db\Mysql and Db\Pdo give every value as a string or NULL; another driver
 * may give other scalars.
 *
 * @phpstan-type Row array<string, scalar|null>
 * @phpstan-type Bindings array<scalar|null>
 * @phpstan-type QueryResult \mysqli_result|\PDOStatement|bool
 * @phpstan-type ConstraintValue scalar|array<scalar|null>|null
 * @phpstan-type Constraint array{name: string, value: ConstraintValue, operator: string, sql?: bool}
 * @phpstan-type PartitionRanges array<string, string>
 * @phpstan-type Partition array{name: string, less_than: string, rows: int}
 * @phpstan-type Span array{name: string, start: string, less_than: string}
 * @phpstan-type Reshape array{names: list<string>, ranges: PartitionRanges, start: string, less_than: string}
 *
 * Bindings are bound in order; their keys are ignored. PartitionRanges are
 * partition name => the yyyymmdd it is less than. QueryResult is a result set,
 * true for a statement with none, false on failure.
 *
 * @method QueryResult    query( string $sql, Bindings $params = array() )
 * @method Row|null       get_row( string $sql, Bindings $params = array() )
 * @method list<Row>|null get_results( string $sql, Bindings $params = array() )
 * @method \Generator<int, Row, mixed, void> get_result_iterator( string $sql, Bindings $params = array() )
 * @method bool           tableExists( string $table_name )
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
        'empty', 'notempty',
        // Internal, like between: a list of values. Not in the REST
        // constraint syntax (ResultSetManager::CONSTRAINT_OPERATORS).
        'in',
    ];

    /**
     * Database Connection, null when there is none
     *
     * @var object|null
     */
    public $connection;

    /**
     * What the constructor was given, by name.
     *
     * @var array{host: string, port: int|string|null, user: string, password: string, name: string, open_new_connection: bool, persistant: bool}
     */
    public $connectionParams;

    /**
     * Queries issued on this connection.
     *
     * The drivers count here because a claim about how many queries a code path
     * costs is otherwise untestable -- settingRowsForChain()
     * exists to turn one query per key per scope into one per chain, and that
     * is only a fact if something can count.
     *
     * @var integer
     */
    public $num_queries = 0;

    /**
     * The driver's raw result: false when a query failed, empty before one ran
     *
     * @var object|string|false|null
     */
    public $new_result;

    /**
     * Rows
     *
     * @var list<Row>
     */
    public $result;

    /**
     * Caller Params
     *
     * @var array<string, mixed>
     */
    public $params = [];

    /**
     * Status of connection
     *
     * @var boolean
     */
    public $connection_status;

    /**
     * Number of rows affected by insert/update/delete statements
     *
     * @var int|null
     */
    public $rows_affected;

    /**
     * Storage Array for components of sql queries: clause => its parts, a
     * different shape per clause
     *
     * @var array<string, mixed>
     */
    public $_sqlParams = [];

    /**
     * Sql Statement
     *
     * @var string
     */
    public $_sql_statement;

    /**
     * Last Sql Statement
     *
     * @var string
     */
    public $_last_sql_statement;

    /**
     * Values bound to the statement being built, in placeholder order.
     *
     * ORDER IS THE WHOLE CONTRACT. Placeholders are positional, so binding N
     * must be the Nth `?` in the finished SQL. That holds because the clause
     * makers are evaluated as sprintf() ARGUMENTS in generateSelectQuerySql(),
     * and PHP evaluates arguments left to right in the order the format string
     * lays them out -- so the order they append in is the order they appear in.
     * Building clauses into variables first, then assembling them in a different
     * order, would silently transpose values between columns. DbBindingTest pins
     * the order.
     *
     * @var list<mixed> whatever bindValue() was given
     */
    public $_bindings = [];

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
     * What the server said about the most recent statement it refused.
     *
     * @var string
     */
    protected $last_query_error = '';

    /**
     * What the server said about the most recent statement it refused.
     *
     * Empty when nothing has failed, and NOT cleared on success, so read it
     * immediately after the call that answered false. It exists because the
     * drivers report every kind of refusal the same way -- as false -- and a
     * caller that wants to explain one otherwise has to guess. A message
     * asserting a cause nobody checked is worse than one that admits it does
     * not know.
     *
     * @return string
     */
    public function lastQueryError() {

        return $this->last_query_error;
    }

    /**
     * Whether the most recent refused statement was stopped by a key or
     * foreign key: the outcome a check-then-insert race expects.
     *
     * @var bool
     */
    protected $last_query_was_constraint_violation = false;

    /**
     * Whether the most recent refused statement was stopped by a key or foreign
     * key. Like lastQueryError(), not cleared on success: read it immediately
     * after the call that answered false.
     *
     * @return bool
     */
    public function lastQueryWasConstraintViolation() {

        return $this->last_query_was_constraint_violation;
    }

    /**
     * How many statements have been refused in this process.
     *
     * A read that answers no row and a read that failed both come back empty;
     * comparing this before and after the call is how a caller tells them
     * apart without a second query.
     *
     * @return int
     */
    public function queryErrorCount() {

        return self::$query_error_count;
    }

    /**
     * Report a statement the database refused.
     *
     * At notice or above, because the production error handler writes notice,
     * warning and error and drops debug -- and a refused write on the tracking
     * path returns a false nothing inspects, so the log line is the only trace.
     *
     * The query is truncated because some of them are enormous, and the error
     * message is what identifies the problem -- the SQL is context. The full
     * statement is still emitted at debug for anyone running the development
     * handler.
     *
     * @param string $message  what the database said
     * @param string $sql      the statement it refused
     * @param bool   $is_constraint_violation  a key or foreign key stopped it
     * @return void
     */
    protected function logQueryError( $message, $sql, $is_constraint_violation = false ) {

        $this->last_query_error = (string) $message;
        $this->last_query_was_constraint_violation = (bool) $is_constraint_violation;

        // Full detail for development, unconditionally.
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

    /**
     * @param string $db_host
     * @param int|string|null $db_port  empty for the server's default
     * @param string $db_name
     * @param string $db_user
     * @param string $db_password
     * @param bool   $open_new_connection unused; kept for drivers that implement this signature
     * @param bool   $persistant
     */
    public function __construct($db_host, $db_port, $db_name, $db_user, $db_password, $open_new_connection = true, $persistant = false) {

        $this->connectionParams = ['host' => $db_host,
                                        'port' => $db_port,
                                         'user' => $db_user,
                                         'password' => $db_password,
                                         'name' => $db_name,
                                         'open_new_connection' => $open_new_connection,
                                         'persistant' => $persistant];
                                                                          
        parent::__construct();
    }

    public function __destruct() {

        if ( $this->isConnectionEstablished() ) {

            $this->close();
        }
    }

    /**
     * Open the connection. The base class has no transport.
     *
     * @return bool
     */
    public function connect() {


        return false;
    }

    /**
     * While true, a failed connect logs nothing: canConnect() is asking.
     *
     * @var bool
     */
    protected $quiet_connect = false;

    /**
     * Whether a connection can be made, without logging a failure.
     *
     * For a caller asking a question rather than needing the database: the
     * installer's first page asks whether OWA is already installed, and with
     * an owa-config.php whose details do not connect the answer is "no", not
     * an alert in the log. The environment check is where a failed connection
     * is reported.
     *
     * The driver's own PHP warning is held back too: mysqli raises one
     * ("Connection refused", "Access denied") as well as returning false.
     *
     * @return bool
     */
    public function canConnect() {

        $this->quiet_connect = true;

        set_error_handler( static function () { return true; }, E_WARNING );

        try {

            return (bool) $this->connect();

        } finally {

            restore_error_handler();

            $this->quiet_connect = false;
        }
    }

    /**
     * Log a failed connect, unless canConnect() is asking.
     *
     * @return void
     */
    protected function connectFailed() {

        if ( ! $this->quiet_connect ) {

            $this->e->alert( 'Could not connect to database.' );
        }
    }

    /**
     * Close the connection. The base class has none to close.
     *
     * @return bool|void
     */
    public function close() {

        return false;
    }

    /**
     * @return bool
     */
    public function isConnectionEstablished() {

        return $this->connection_status;
    }

    /**
     * @param  string $name a key of $connectionParams
     * @return int|string|bool|void|null the value; nothing for a name that is not one
     */
    public function getConnectionParam($name) {

        if (array_key_exists($name, $this->connectionParams)) {
            return $this->connectionParams[$name];
        }
    }

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
    public function bindValue( $value ) {

        // NULL is bound as a real SQL NULL, not as '': under STRICT_ALL_TABLES an
        // empty string in a numeric column is refused, and a permissive sql_mode would
        // coerce it to 0.
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
     *
     * @return void
     */
    public function resetBindings() {

        $this->_bindings = [];
    }

    /**
     * @return list<mixed> the values bindValue() was given, in order
     */
    public function getBindings() {

        return $this->_bindings;
    }

    /**
     * Placeholders for an IN list of $count bound values: "?, ?, ?". A list
     * needs at least one value; IN () is not SQL.
     *
     * @param int $count
     * @return string
     */
    public static function placeholders( $count ) {

        $count = (int) $count;

        if ( $count < 1 ) {

            throw new \InvalidArgumentException( 'An IN list needs at least one value.' );
        }

        return implode( ', ', array_fill( 0, $count, '?' ) );
    }

    /**
     * @param  string|array{0: string, 1: string} $name a column or expression, or [it, alias]
     * @param  string $as
     * @return void
     */
    public function selectColumn($name, $as = '') {

        if (is_array($name)) {
            $as = $name[1];
            $name = $name[0];
        }

        $this->_sqlParams['select_values'][] = ['name' => $name, 'as' => $as];

        return;
    }

    /**
     * selectColumn().
     *
     * @param  string|array{0: string, 1: string} $name
     * @param  string $as
     * @return void
     */
    public function select($name, $as = '') {
        $this->selectColumn($name, $as);
    }

    /**
     * A condition on $name. One per name replaces the last, unless $key sets it
     * apart: a second condition on the same column (ResultSetManager, a
     * dimension filtered twice in one request) passes its own key, and both
     * apply. The clause is built from 'name', never the key.
     *
     * @param  string $name
     * @param  ConstraintValue $value  a list for 'in'; ['start' => .., 'end' => ..] for 'between'
     * @param  string $operator  one of ALLOWED_OPERATORS
     * @param  string|null $key
     * @return void
     */
    public function where($name, $value, $operator = '=', $key = null) {

        if ( ! \OWA\Core\Lib::isEmpty( $value ) ) {

            // hack for intentional empty value
            if($value == ' '){
                $value = '';
            }

            $this->_sqlParams['where'][ $key ?? $name ] = ['name' => $name, 'value' => $value, 'operator' => $operator];
        }
    }

    /**
     * A WHERE condition written as SQL, for one the operators cannot say. The
     * SQL is the caller's own, never a request's; $bindings are its values, in
     * the order of its placeholders.
     *
     * @param string $key
     * @param string $sql
     * @param Bindings $bindings
     * @return void
     */
    public function whereSql( $key, $sql, array $bindings = [] ) {

        $this->_sqlParams['where'][ $key ] = [ 'name' => $sql, 'value' => $bindings, 'operator' => 'sql', 'sql' => true ];
    }

    /**
     * As where(), for a metric.
     *
     * @param  string $name
     * @param  ConstraintValue $value
     * @param  string $operator
     * @param  string|null $key
     * @return void
     */
    public function having($name, $value, $operator = '=', $key = null) {

        if ( ! \OWA\Core\Lib::isEmpty( $value ) ) {

            // hack for intentional empty value
            if($value == ' ') {
                $value = '';
            }

            $this->_sqlParams['having'][ $key ?? $name ] = ['name' => $name, 'value' => $value, 'operator' => $operator];
        }
    }

    /**
     * @param  string $col
     * @return void
     */
    public function groupBy($col) {

        $this->_sqlParams['groupby'][] = $col;
        return;
    }

    /**
     * @param  string $col
     * @param  string $flag ASC or DESC; anything else is the default direction
     * @return void
     */
    public function orderBy($col, $flag = '') {

        $this->_sqlParams['orderby'][] = [$col, $flag];
        return;
    }

    /**
     * @param  int|string $value
     * @return void
     */
    public function limit($value) {

        $this->_sqlParams['limit'] = $value;
        return;
    }

    /**
     * @param  int|string $value
     * @return void
     */
    public function offset($value) {

        $this->_sqlParams['offset'] = $value;
        return;
    }

    /**
     * @param  string $name
     * @param  mixed  $value  bound as given
     * @return void
     */
    public function set($name, $value) {

        $this->_sqlParams['set_values'][] = ['name' => $name, 'value' => $value];
        return;
    }

    /**
     * Run the statement built.
     *
     * @return list<Row>|QueryResult|null  get_results() for a select, query() otherwise
     */
    public function executeQuery() {

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

    /**
     * @return list<Row>|null  null for no rows
     */
    public function getAllRows() {

         return $this->_selectQuery();
    }

    /**
     * @return Row|null
     */
    public function getOneRow() {

        $this->limit(1);
        $ret = $this->_selectQuery();
        return (is_array($ret) && isset($ret[0])) ? $ret[0] : null;
    }

    /**
     * @param  string $sql
     * @return void
     */
    public function _setSql($sql) {
        $this->_sql_statement = $sql;
    }

    /**
     * $bindings are the values of a subselect's placeholders, in order. They
     * are bound when the FROM clause is generated, so they take their place
     * between the select list's and the WHERE clause's.
     *
     * @param  string|array{0: string, 1: string} $name a table or a subselect, or [it, alias]
     * @param  string $as
     * @param  Bindings $bindings
     * @return void
     */
    public function selectFrom($name, $as = '', array $bindings = []) {

        if (is_array($name)) {
            $as = $name[1];
            $name = $name[0];
        }

        $this->_sqlParams['query_type'] = 'select';
        $this->_sqlParams['from'][$name] = ['name' => $name, 'as' => $as, 'bindings' => $bindings];
    }

    /**
     * @param  string|array{0: string, 1: string} $name
     * @param  string $as
     * @param  Bindings $bindings
     * @return void
     */
    public function from( $name, $as = '', array $bindings = [] ) {

        $this->selectFrom( $name, $as, $bindings );
    }

    /**
     * @param  string $table
     * @return void
     */
    public function insertInto($table) {

        $this->_sqlParams['query_type'] = 'insert';
        $this->_sqlParams['table'] = $table;
    }

    /**
     * @param  string $table
     * @return void
     */
    public function deleteFrom($table) {

        $this->_sqlParams['query_type'] = 'delete';
        $this->_sqlParams['table'] = $table;
    }

    /**
     * @param  string $table
     * @return void
     */
    public function updateTable($table) {

        $this->_sqlParams['query_type'] = 'update';
        $this->_sqlParams['table'] = $table;
    }

    /**
     * @return list<Row>|QueryResult|null  query()'s result
     */
    public function _insertQuery() {

        $this->resetBindings();
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
        $this->_setSql(sprintf(OWA_SQL_INSERT_ROW, $this->_sqlParams['table'], $sql_cols, $sql_values));
        $ret = $this->_query();
        return $ret;

    }

    /**
     * @return string
     */
    public function generateSelectQuerySql() {

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

    /**
     * @return list<Row>|QueryResult|null  get_results()'s result
     */
    public function _selectQuery() {

        $this->generateSelectQuerySql();
        return $this->_query();

    }


    /**
     * @return list<Row>|QueryResult|null  query()'s result; false when refused
     */
    public function _updateQuery() {

        if ( $this->refusesUnfiltered( 'UPDATE' ) ) {

            return false;
        }

        $this->resetBindings();

        $params = $this->_fetchSqlParams('set_values');

        $count = count($params);

        $i = 0;

        $sql_cols = '';
        $sql_values = '';
        $set = '';

        foreach ($params as $k => $v) {

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

    /**
     * @return list<Row>|QueryResult|null  query()'s result; false when refused
     */
    public function _deleteQuery() {

        if ( $this->refusesUnfiltered( 'DELETE' ) ) {

            return false;
        }

        $this->resetBindings();

        $this->_setSql(sprintf(OWA_SQL_DELETE_ROW, $this->_sqlParams['table'], $this->_makeWhereClause()));

        return $this->_query();
    }

    /**
     * A built DELETE or UPDATE with no WHERE is refused.
     *
     * where() drops a condition whose value is empty, so a caller passing an
     * empty id would build `DELETE FROM owa_user` and empty the table. Every caller
     * of deleteFrom() and updateTable() means to touch some rows, never all.
     * The builder is reset, as after a query.
     *
     * @param  string $verb DELETE or UPDATE, for the notice
     * @return bool   true when the statement was refused
     */
    private function refusesUnfiltered( $verb ) {

        if ( $this->_fetchSqlParams( 'where' ) ) {

            return false;
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Refusing %s on %s with no WHERE clause, from %s.',
            $verb, $this->_sqlParams['table'] ?? '(no table)',
            \OWA\Core\Lib::callSiteOutside( [ 'Db.php', 'Entity.php' ] ) ) );

        $this->_sql_statement = '';
        $this->_sqlParams = [];
        $this->resetBindings();

        return true;
    }

    /**
     * @param  string $sql_params_name  a clause's key in $_sqlParams
     * @return mixed  the clause's parts as stored, a different shape per clause; false when empty
     */
    public function _fetchSqlParams($sql_params_name) {

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

    /**
     * @return string|void  nothing with no conditions
     */
    public function _makeWhereClause() {

        $params = $this->_fetchSqlParams('where');

        if ( ! empty( $params ) ) {

            return $this->_makeConstraintClause('WHERE', $params);
        }
    }

    /**
     * @return string|void  nothing with no conditions
     */
    public function _makeHavingClause() {

        $params = $this->_fetchSqlParams('having');

        if ( ! empty( $params ) ) {

            return $this->_makeConstraintClause('HAVING', $params);
        }
    }
    
    /**
     * The operators that must not discard a row merely because it has no value.
     *
     * Only the negating ones. '==' and the ordering comparisons are correct
     * already: a row with no value genuinely does not equal, exceed or precede
     * anything.
     */
    const NULL_TOLERANT_OPERATORS = [ '!=', '!~', '!@' ];

    /**
     * Widen a comparison so a row holding no value satisfies it.
     *
     * Returns the expression untouched when tolerance does not apply, so the
     * call sites read the same whether or not the operator negates.
     *
     * @param  bool   $apply
     * @param  string $name
     * @param  string $expression
     * @return string
     */
    protected function tolerateNull( $apply, $name, $expression ) {

        if ( ! $apply ) {

            return $expression;
        }

        return sprintf( '( %s OR %s IS NULL )', $expression, $name );
    }

    /**
     * Generates the SQL constraint string.
     *
     * @param string $type 'WHERE' or 'HAVING'
     *
     * WHY THE OPERAND IS NOT ESCAPED.
     *
     * It is not a value. It is a column name, an expression a dimension
     * declared, or the SQL a metric generates for itself -- resolved through
     * the registry before it ever arrives, which is the boundary that keeps a
     * request from naming an arbitrary column. Only $v['value'] comes from
     * outside, and that is bound.
     *
     * Escaping an IDENTIFIER protects nothing -- an attacker who could choose
     * the operand would not need a quote to do damage -- and corrupts every
     * operand that legitimately contains one: a metric with a condition
     * generates `sum(CASE WHEN event_type = 'page_view' ...)`, and a dimension
     * built from several columns carries its separators as string literals.
     *
     * @param  array<string, Constraint>|false $params
     * @return string|void  nothing with no conditions
     */
    public function _makeConstraintClause( $type, $params ) {
         
        if ( ! empty( $params ) ) {

            $count = count( $params );
            $i = 0;

            $constraint = $type.' ';

            foreach ($params as $k => $v) {

                // whereSql(): its placeholders are in the SQL, so its values bind here, in order.
                if ( ! empty( $v['sql'] ) ) {

                    foreach ( $v['value'] as $value ) {

                        $this->bindValue( $value );
                    }

                    $constraint .= '( ' . $v['name'] . ' )';

                    if ( $i < $count - 1 ) {

                        $constraint .= ' AND ';
                    }

                    $i++;

                    continue;
                }

                $op = strtolower( $v['operator'] );

                /*
                 * A disallowed operator matches nothing. Skipped, it left the
                 * AND written for it dangling ("WHERE a = 1 AND ", or a bare
                 * "WHERE "), and dropping it from a working clause would widen
                 * the statement it filters.
                 */
                if ( ! in_array( $op, self::ALLOWED_OPERATORS, true ) ) {
                    \OWA\Core\CoreAPI::debug( sprintf( 'Refusing constraint with disallowed operator: %s', $v['operator'] ) );
                    $op = 'none';
                }

                /*
                 * A negating comparison has to tolerate absence.
                 *
                 * The reporting query LEFT OUTER JOINs its dimension tables, so
                 * a session whose geo lookup failed, or which carried no
                 * referer, still belongs in the result with NULLs for that
                 * dimension. But NULL != 'google' is NULL rather than true, so
                 * a WHERE built from a negating operator discards exactly the
                 * rows the LEFT JOIN exists to keep -- silently, and the count
                 * still looks plausible. On demo that is 23,945 of 326,212
                 * sessions missing from "sources other than google", which are
                 * the direct-traffic sessions somebody asking that question
                 * most wants to see.
                 *
                 * So these three emit ( <comparison> OR <column> IS NULL ).
                 *
                 * WHERE only. This method also builds HAVING, where the operand
                 * is an aggregate rather than a column: COUNT never returns
                 * NULL and SUM returns it only over no rows, so the same clause
                 * there would widen metric filtering for no reason.
                 */
                $tolerateNull = $type === 'WHERE'
                                && in_array( $op, self::NULL_TOLERANT_OPERATORS, true );

                switch ( $op ) {

                    case 'none':
                        $constraint .= '1 = 0';
                        break;

                    case '==':
                        $constraint .= sprintf("%s = %s", $v['name'], $this->bindValue( $v['value'] ) );
                        break;

                    case 'between':
                        $constraint .= sprintf("%s BETWEEN %s AND %s", $v['name'], $this->bindValue( $v['value']['start'] ), $this->bindValue( $v['value']['end'] ) );
                        break;

                    // Each value bound. An empty list matches nothing rather
                    // than everything (where() already drops an empty value).
                    case 'in':
                        $values = array_values( (array) $v['value'] );
                        $constraint .= $values
                            ? sprintf( '%s IN (%s)', $v['name'], implode( ', ', array_map( [ $this, 'bindValue' ], $values ) ) )
                            : '1 = 0';
                        break;

                    case '=~':
                        $constraint .= sprintf("%s %s %s", $v['name'], OWA_SQL_REGEXP, $this->bindValue( $v['value'] ) );
                        break;

                    /*
                     * THE EMPTY TEST. Unary: the value is ignored. Empty is NULL
                     * or '', the two states "(not set)" labels. notempty is not
                     * null-tolerant -- excluding unset rows is all it is for.
                     */
                    case 'empty':
                        $constraint .= sprintf( "( %1\$s IS NULL OR %1\$s = '' )", $v['name'] );
                        break;

                    case 'notempty':
                        $constraint .= sprintf( "( %1\$s IS NOT NULL AND %1\$s <> '' )", $v['name'] );
                        break;

                    case '!~':
                        $constraint .= $this->tolerateNull( $tolerateNull, $v['name'],
                            sprintf("%s %s %s",$v['name'], OWA_SQL_NOTREGEXP, $this->bindValue( $v['value'] ) ) );
                        break;

                    case '=@':
                        // Dialect-owned, like =~ and !~ above: the expression
                        // for "contains" is not the same SQL everywhere.
                        $constraint .= sprintf( OWA_SQL_CONTAINS, $this->bindValue( $v['value'] ), $v['name'] );
                        break;

                    case '!@':
                        $constraint .= $this->tolerateNull( $tolerateNull, $v['name'],
                            sprintf( OWA_SQL_NOT_CONTAINS, $this->bindValue( $v['value'] ), $v['name'] ) );
                        break;

                    default:
                        // $op has already been validated against ALLOWED_OPERATORS,
                        // so this covers '=', '!=', '>', '>=', '<', '<='.
                        $constraint .= $this->tolerateNull( $tolerateNull, $v['name'],
                            sprintf("%s %s %s",$v['name'], $op, $this->bindValue( $v['value'] ) ) );
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

    /**
     * Escape a value for interpolation into a statement.
     *
     * Every driver must implement this, because escaping is a property of the
     * connection -- it depends on the server's character set, which only the
     * driver knows. There is no correct database-agnostic version, so the base
     * class refuses rather than offering a plausible-looking one.
     *
     * A third-party driver that reaches this is not escaping its
     * values, which is a defect worth hearing about immediately rather than
     * discovering later.
     *
     * @param string|null $string
     * @return string|null
     * @throws \RuntimeException always
     */
    public function prepare ( $string ) {

        throw new \RuntimeException( sprintf(
            '%s does not implement prepare(). A database driver must escape values itself, '
          . 'because escaping depends on the connection character set. Implement prepare() '
          . 'on the driver.',
            get_class( $this )
        ) );
    }

    /**
     * @return string|false  false with no table
     */
    public function _makeFromClause() {

        $from = '';
        $i = 0;
        $params = $this->_fetchSqlParams('from');

        if(!empty($params)):

            $count = count($params);

            foreach ($params as $k => $v) {

                $from .= $v['name'];

                foreach ( $v['bindings'] ?? [] as $value ) {

                    $this->bindValue( $value );
                }

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

            return $from;
        else:
            $this->e->debug("No SQL FROM params set.");
            return false;
        endif;

    }

    /**
     * @return string|void
     */
    public function _makeGroupByClause() {

        $params = $this->_fetchSqlParams('groupby');

        if (!empty($params)):

            return sprintf("GROUP BY %s", $this->_makeDelimitedValueList($params));

        else:
            return;
        endif;


    }

    /**
     * @return string|void
     */
    public function _makeOrderByClause() {

        $sorts = $this->_fetchSqlParams('orderby');
        if (!empty($sorts)) {

            $i = 1;
            $sort_string = '';
            $count = count($sorts);
            foreach ($sorts as $sort) {

                if ( ! isset( $sort[1] ) || ! $this->isValidSortDirectionValue( $sort[1] ) ) {
                    
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

    /**
     * @return string|void
     */
    public function _makeLimitClause() {

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

    /**
     * @return string|void
     */
    public function _makeOffsetClause() {

        $param = $this->_fetchSqlParams('offset');

        if(!empty($param)):
            return sprintf("OFFSET %d", $param);
        else:
            return;
        endif;

    }


    /**
     * @param  list<string>|string $values  a string is returned as it is
     * @param  string $delimiter
     * @return string
     */
    public function _makeDelimitedValueList($values, $delimiter = ', ') {

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

    /**
     * Run the statement built, by its query type, and reset the builder.
     *
     * @return list<Row>|QueryResult|null  null when no query type was set
     */
    public function _query() {

        $ret = null;

        switch($this->_sqlParams['query_type']) {

            case 'insert':

                $ret = $this->query($this->_sql_statement, $this->_bindings);
                break;
            case 'select':

                $ret = $this->get_results($this->_sql_statement, $this->_bindings);
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
        $this->_sqlParams = [];
        $this->resetBindings();
        return $ret;

    }

        /**
     * Drops a table
     *
     * @param  string $table_name
     * @return QueryResult
     */
    public function dropTable($table_name) {

        return $this->query(sprintf(OWA_SQL_DROP_TABLE, $table_name));

    }

    /**
     * Rename a table
     *
     * @param  string $table_name
     * @param  string $new_table_name
     * @return QueryResult
     */
    public function renameTable($table_name, $new_table_name) {

        return $this->query(sprintf(OWA_SQL_RENAME_TABLE, $table_name, $new_table_name));
    }

    /**
     * Renames column. Fails when $old does not exist.
     *
     * @param  string $table_name
     * @param  string $old
     * @param  string $new
     * @param  string $defs  the column's definition
     * @return QueryResult
     */
    public function renameColumn($table_name, $old, $new, $defs) {

        return $this->query(sprintf(OWA_SQL_RENAME_COLUMN, $table_name, $old, $new, $defs));
    }


    /**
     * Adds new column to table. Fails when the column already exists.
     *
     * @param  string $table_name
     * @param  string $column_name
     * @param  string $column_definition
     * @return QueryResult
     */
    public function addColumn($table_name, $column_name, $column_definition) {

        return $this->query(sprintf(OWA_SQL_ADD_COLUMN, $table_name, $column_name, $column_definition));
    }

    /**
     * Drops a column from a table
     *
     * @param  string $table_name
     * @param  string $column_name
     * @return QueryResult
     */
    public function dropColumn($table_name, $column_name) {

        return $this->query(sprintf(OWA_SQL_DROP_COLUMN, $table_name, $column_name));

    }

    /**
     * Changes the definition of a column
     *
     * @param  string $table_name
     * @param  string $column_name
     * @param  string $column_definition
     * @return QueryResult
     */
    public function modifyColumn($table_name, $column_name, $column_definition) {

        return $this->query(sprintf(OWA_SQL_MODIFY_COLUMN, $table_name, $column_name, $column_definition));
    }

    /**
     * Normalizes a column list into its canonical comma-separated form.
     *
     * Callers pass either 'yyyymmdd' or 'action_group, action_name'. The
     * comparison against information_schema needs the form GROUP_CONCAT
     * produces, so whitespace is removed. Returns an empty array if any part is
     * not a bare identifier, which is the caller's signal to refuse.
     *
     * @param  string $column_name
     * @return list<string>
     */
    protected function normalizeIndexColumns( $column_name ) {

        $cols = [];

        foreach ( explode( ',', (string) $column_name ) as $col ) {

            $col = trim( $col );

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $col ) ) {

                return [];
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
     *
     * @param  string $table_name
     * @param  string $column_name  one column, or several comma-separated
     * @return bool
     */
    public function indexExists( $table_name, $column_name ) {

        return false;
    }

    /**
     * Can this driver partition tables? Assume not.
     *
     * @return bool
     */
    public function supportsPartitioning() {

        return false;
    }

    /**
     * The partitions on a table. Nothing, where partitioning is unsupported.
     *
     * @param string $table_name
     * @return list<Partition>
     */
    public function listPartitions( $table_name ) {

        return [];
    }

    /**
     * Whether a database exists. Unknown, without introspection.
     *
     * @param  string $name
     * @return bool|null  null when it cannot be told
     */
    public function databaseExists( $name ) {

        return null;
    }

    /**
     * Create a database. Not supported without a dialect that can.
     *
     * @param  string $name
     * @return bool
     */
    public function createDatabase( $name ) {

        return false;
    }

    /**
     * Drop a database. Not supported without a dialect that can.
     *
     * @param  string $name
     * @return bool
     */
    public function dropDatabase( $name ) {

        return false;
    }

    /**
     * The base tables whose names start with $prefix. None, without
     * introspection; the dialect overrides it (cmd=backup).
     *
     * @param string $prefix
     * @return list<string>
     */
    public function listTables( $prefix ) {

        return [];
    }

    /**
     * The columns an INSERT can write, column => whether binary. None, without
     * introspection.
     *
     * @param string $table_name
     * @return array<string, bool>
     */
    public function copyableColumns( $table_name ) {

        return [];
    }

    /**
     * @param string $table_name
     * @return list<string> the primary key's columns; none, without introspection
     */
    public function primaryKeyColumns( $table_name ) {

        return [];
    }

    /**
     * @param string $table_name
     * @return string|null the statement that creates the table; null, without a dialect that can say
     */
    public function createTableStatement( $table_name ) {

        return null;
    }

    /**
     * Run $fn in the session a dump is read in. As it is, without a dialect.
     *
     * @param callable $fn
     * @return mixed $fn's result
     */
    public function withDumpSession( callable $fn ) {

        return $fn();
    }

    /**
     * Run $fn in one consistent snapshot. Unguarded, without a dialect.
     *
     * @param callable $fn
     * @return mixed $fn's result
     */
    public function withConsistentSnapshot( callable $fn ) {

        return $fn();
    }

    /**
     * What a dump sets before and after its statements. Nothing, without a dialect.
     *
     * @return array{before: string, after: string}
     */
    public function dumpPreamble() {

        return [ 'before' => '', 'after' => '' ];
    }

    /**
     * A table's size as the server estimates it. Unknown, without introspection.
     *
     * @param string $table_name
     * @return array{rows: int, bytes: int}|null
     */
    public function tableSize( $table_name ) {

        return null;
    }

    /**
     * The scheduler's lock table, resolved through the entity so the namespace
     * prefix is not duplicated here. Db\JobLocks reads it from here.
     *
     * @return string
     */
    protected function jobLockTable() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.job_lock' )->getTableName();
    }

    /**
     * Delegates to Db\JobLocks::insertJobLock().
     *
     * @param string $job_name
     * @param string $owner
     * @param int    $now
     * @param int    $expires_at
     * @return bool
     */
    public function insertJobLock( $job_name, $owner, $now, $expires_at ) {

        return $this->jobLocks()->insertJobLock( $job_name, $owner, $now, $expires_at );
    }

    /**
     * Delegates to Db\JobLocks::deleteJobLock().
     *
     * @param string $job_name
     * @param int    $now
     * @return bool
     */
    public function deleteJobLock( $job_name, $now ) {

        return $this->jobLocks()->deleteJobLock( $job_name, $now );
    }

    /**
     * Delegates to Db\JobLocks::refreshJobLock().
     *
     * @param string $job_name
     * @param string $owner
     * @param int    $expires_at
     * @return bool
     */
    public function refreshJobLock( $job_name, $owner, $expires_at ) {

        return $this->jobLocks()->refreshJobLock( $job_name, $owner, $expires_at );
    }

    /**
     * Delegates to Db\JobLocks::releaseJobLock().
     *
     * @param string $job_name
     * @param string $owner
     * @return bool
     */
    public function releaseJobLock( $job_name, $owner ) {

        return $this->jobLocks()->releaseJobLock( $job_name, $owner );
    }

    /**
     * Delegates to Db\JobLocks::getJobLock().
     *
     * @param string $job_name
     * @return Row|null
     */
    public function getJobLock( $job_name ) {

        return $this->jobLocks()->getJobLock( $job_name );
    }

    /**
     * The job locks this Db delegates to.
     *
     * A new one per call: one held here would make a cycle with the Db and
     * hold off its __destruct().
     *
     * @return Db\JobLocks
     */
    private function jobLocks() {

        return new \OWA\Core\Db\JobLocks( $this );
    }

    /**
     * Row count and date range within one partition. Unknown, without support.
     *
     * @param string $table_name
     * @param string $partition
     * @param string $column
     * @return array{rows: int, min: string|null, max: string|null}|null
     */
    public function getPartitionContents( $table_name, $partition, $column = 'yyyymmdd' ) {

        return null;
    }

    /**
     * Delegates to Db\Partitions::isPartitioned().
     *
     * @param string $table_name
     * @return bool
     */
    public function isPartitioned( $table_name ) {

        return $this->partitions()->isPartitioned( $table_name );
    }

    /** The value of Db\Partitions::PARTITION_COUNT_LIMIT. */
    const PARTITION_COUNT_LIMIT = \OWA\Core\Db\Partitions::PARTITION_COUNT_LIMIT;

    /** The value of Db\Partitions::PARTITION_MONTHS_AHEAD. */
    const PARTITION_MONTHS_AHEAD = \OWA\Core\Db\Partitions::PARTITION_MONTHS_AHEAD;

    /** The value of Db\Partitions::CUBE_DAILY_MONTHS. */
    const CUBE_DAILY_MONTHS = \OWA\Core\Db\Partitions::CUBE_DAILY_MONTHS;

    /** The value of Db\Partitions::FACT_LOWER_BOUND_SLACK_DAYS. */
    const FACT_LOWER_BOUND_SLACK_DAYS = \OWA\Core\Db\Partitions::FACT_LOWER_BOUND_SLACK_DAYS;

    /** The value of Db\Partitions::PARTITION_CUTS. */
    const PARTITION_CUTS = \OWA\Core\Db\Partitions::PARTITION_CUTS;

    /**
     * Delegates to Db\Partitions::partitionLeadBoundary().
     *
     * @param int $months
     * @return string yyyymmdd
     */
    public static function partitionLeadBoundary( $months = self::PARTITION_MONTHS_AHEAD ) {

        return \OWA\Core\Db\Partitions::partitionLeadBoundary( $months );
    }

    /**
     * Delegates to Db\Partitions::makeLeadRanges().
     *
     * @param int    $daily_months  how many of the leading months are daily
     * @param string $granularity   the rest of the lead; the operator's choice
     * @return PartitionRanges
     */
    public static function makeLeadRanges( $daily_months = 0, $granularity = 'monthly' ) {

        return \OWA\Core\Db\Partitions::makeLeadRanges( $daily_months, $granularity );
    }

    /**
     * Delegates to Db\Partitions::isPartitionGranularity().
     *
     * @param string $granularity
     * @return bool
     */
    public static function isPartitionGranularity( $granularity ) {

        return \OWA\Core\Db\Partitions::isPartitionGranularity( $granularity );
    }

    /**
     * Delegates to Db\Partitions::makePartitionRanges().
     *
     * @param int|string $start_yyyymmdd
     * @param int|string $end_yyyymmdd
     * @param string $granularity
     * @return PartitionRanges in range order
     */
    public static function makePartitionRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity = 'monthly' ) {

        return \OWA\Core\Db\Partitions::makePartitionRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity );
    }

    /**
     * Delegates to Db\Partitions::makePartitionRangesForSpan().
     *
     * @param int|string $start_yyyymmdd  first day the span holds
     * @param int|string $end_yyyymmdd    first day it does not
     * @param string $granularity
     * @return PartitionRanges
     */
    public static function makePartitionRangesForSpan( $start_yyyymmdd, $end_yyyymmdd, $granularity = 'monthly' ) {

        return \OWA\Core\Db\Partitions::makePartitionRangesForSpan( $start_yyyymmdd, $end_yyyymmdd, $granularity );
    }

    /**
     * Delegates to Db\Partitions::makePartitionClause().
     *
     * @param string $column
     * @param PartitionRanges $ranges
     * @param bool   $with_maxvalue  append the catch-all
     * @return string  empty when the driver cannot partition
     */
    public function makePartitionClause( $column, $ranges, $with_maxvalue = true ) {

        return $this->partitions()->makePartitionClause( $column, $ranges, $with_maxvalue );
    }

    /**
     * Delegates to Db\Partitions::dropPartition().
     *
     * @param string $table_name
     * @param string $partition
     * @return bool
     */
    public function dropPartition( $table_name, $partition ) {

        return $this->partitions()->dropPartition( $table_name, $partition );
    }

    /**
     * Delegates to Db\Partitions::reorganizePartitions().
     *
     * @param string $table_name
     * @param list<string> $from   partition names being replaced
     * @param PartitionRanges $ranges to replace them with
     * @return bool
     */
    public function reorganizePartitions( $table_name, $from, $ranges ) {

        return $this->partitions()->reorganizePartitions( $table_name, $from, $ranges );
    }

    /**
     * Delegates to Db\Partitions::createUnpartitionedCopy().
     *
     * @param string $new     table to create
     * @param string $source  partitioned table to copy
     * @return bool
     */
    public function createUnpartitionedCopy( $new, $source ) {

        return $this->partitions()->createUnpartitionedCopy( $new, $source );
    }

    /**
     * Delegates to Db\Partitions::exchangePartition().
     *
     * @param string $table_name  the partitioned table
     * @param string $partition   the partition to exchange
     * @param string $with_table  an unpartitioned table of identical structure
     * @return bool
     */
    public function exchangePartition( $table_name, $partition, $with_table ) {

        return $this->partitions()->exchangePartition( $table_name, $partition, $with_table );
    }

    /**
     * Delegates to Db\Partitions::getPartitionSpans().
     *
     * @param string $table_name
     * @return list<Span>
     */
    public function getPartitionSpans( $table_name ) {

        return $this->partitions()->getPartitionSpans( $table_name );
    }

    /**
     * Delegates to Db\Partitions::factDateRange().
     *
     * @param mixed $session_yyyymmdd  a stored date, any type
     * @return array{start: string, end: string}|null yyyymmdd, or null where unusable
     */
    public static function factDateRange( $session_yyyymmdd ) {

        return \OWA\Core\Db\Partitions::factDateRange( $session_yyyymmdd );
    }

    /** The value of Db\Partitions::FACT_DATE_IN_LIMIT. */
    const FACT_DATE_IN_LIMIT = \OWA\Core\Db\Partitions::FACT_DATE_IN_LIMIT;

    /**
     * Delegates to Db\Partitions::factDateConstraint().
     *
     * @param mixed $yyyymmdd  a stored date, any type
     * @return array{}|array{yyyymmdd: array{value: list<int>|array{start: string, end: string}, operator: string}}
     */
    public static function factDateConstraint( $yyyymmdd ) {

        return \OWA\Core\Db\Partitions::factDateConstraint( $yyyymmdd );
    }

    /**
     * Delegates to Db\Partitions::inferPartitionGranularity().
     *
     * @param string $table_name
     * @return string|null  null where it does not match a known scheme
     */
    public function inferPartitionGranularity( $table_name ) {

        return $this->partitions()->inferPartitionGranularity( $table_name );
    }

    /**
     * Delegates to Db\Partitions::describePartitionPeriod().
     *
     * @param string $start      yyyymmdd
     * @param string $less_than  yyyymmdd
     * @return string
     */
    public static function describePartitionPeriod( $start, $less_than ) {

        return \OWA\Core\Db\Partitions::describePartitionPeriod( $start, $less_than );
    }

    /**
     * Delegates to Db\Partitions::describePartitionLayout().
     *
     * @param string $table_name
     * @return array{partitioned: bool, spans: int, total: int, covers: array{start: string, end: string}|null, granularity: string|null, tiers: list<array{period: string, count: int, start: string, end: string}>, catch_all: string|null, through: string|null, ahead: int|null, lead: int}
     */
    public function describePartitionLayout( $table_name ) {

        return $this->partitions()->describePartitionLayout( $table_name );
    }

    /**
     * Delegates to Db\Partitions::getCatchAllPartition().
     *
     * @param string $table_name
     * @return string|null
     */
    public function getCatchAllPartition( $table_name ) {

        return $this->partitions()->getCatchAllPartition( $table_name );
    }

    /**
     * Delegates to Db\Partitions::mergePartitions().
     *
     * @param string $table_name
     * @param list<string> $names  partition names, in ascending order
     * @param string $start      yyyymmdd the merged partition begins at (its name)
     * @param string $less_than  yyyymmdd upper bound -- the last partition's
     * @return bool
     */
    public function mergePartitions( $table_name, $names, $start, $less_than ) {

        return $this->partitions()->mergePartitions( $table_name, $names, $start, $less_than );
    }

    /** The value of Db\Partitions::PARTITION_DETAIL_MONTHS. */
    const PARTITION_DETAIL_MONTHS = \OWA\Core\Db\Partitions::PARTITION_DETAIL_MONTHS;

    /**
     * Delegates to Db\Partitions::yearBlocks().
     *
     * @param int    $first_year
     * @param string $tail_limit  yyyymmdd the last block ends on (exclusive)
     * @param int    $block       years per block
     * @return PartitionRanges
     */
    public static function yearBlocks( $first_year, $tail_limit, $block ) {

        return \OWA\Core\Db\Partitions::yearBlocks( $first_year, $tail_limit, $block );
    }

    /**
     * Delegates to Db\Partitions::oldTierBlockYears().
     *
     * @param int    $first_year  of the first old partition
     * @param string $tail_limit  where the old tier ends
     * @param int    $kept        partitions outside the old tier
     * @param int    $limit
     * @return int
     */
    public static function oldTierBlockYears( $first_year, $tail_limit, $kept, $limit ) {

        return \OWA\Core\Db\Partitions::oldTierBlockYears( $first_year, $tail_limit, $kept, $limit );
    }

    /**
     * Delegates to Db\Partitions::reshapePartitions().
     *
     * @param string $table_name
     * @param list<string> $names   partitions to replace, ascending and adjacent
     * @param PartitionRanges $ranges  ascending, tiling the same span
     * @return bool
     */
    public function reshapePartitions( $table_name, $names, $ranges ) {

        return $this->partitions()->reshapePartitions( $table_name, $names, $ranges );
    }

    /** The value of Db\Partitions::PARTITION_MAX_YEARS_PER_BLOCK. */
    const PARTITION_MAX_YEARS_PER_BLOCK = \OWA\Core\Db\Partitions::PARTITION_MAX_YEARS_PER_BLOCK;

    /**
     * Delegates to Db\Partitions::planPartitionCompaction().
     *
     * @param string      $table_name
     * @param int         $limit          most partitions this table may have
     * @param int         $detail_months  periods newer than this are left alone
     * @param string|null $cutoff         yyyymmdd: the first day retention keeps; null keeps everything
     * @return array{operations: list<Reshape>, merges: list<Reshape>, projected: int, fits: bool, floor: int, block_years: int}
     */
    public function planPartitionCompaction( $table_name, $limit, $detail_months = self::PARTITION_DETAIL_MONTHS, $cutoff = null ) {

        return $this->partitions()->planPartitionCompaction( $table_name, $limit, $detail_months, $cutoff );
    }

    /**
     * Delegates to Db\Partitions::splitAtCutoff().
     *
     * @param list<Span> $old  the old tier's spans, ascending
     * @param string $cutoff  yyyymmdd
     * @return array{tail: list<Span>, operations: list<Reshape>, doomed: int, added: int}
     *         doomed: spans the drop takes; added: partitions the split adds
     */
    public static function splitAtCutoff( array $old, $cutoff ) {

        return \OWA\Core\Db\Partitions::splitAtCutoff( $old, $cutoff );
    }

    /**
     * Delegates to Db\Partitions::extendPartitions().
     *
     * @param string $table_name
     * @param string $granularity
     * @param string $through      yyyymmdd the partitions must reach
     * @param bool   $dry_run
     * @return array{added: list<string>, planned: int, top: string|null, covered: bool}
     */
    public function extendPartitions( $table_name, $granularity, $through, $dry_run = false ) {

        return $this->partitions()->extendPartitions( $table_name, $granularity, $through, $dry_run );
    }

    /**
     * Delegates to Db\Partitions::extendPartitionsBack().
     *
     * @param string $table_name
     * @param string $from           yyyymmdd the partitions must reach back to
     * @param int    $detail_months  how much recent history stays monthly
     * @param int    $limit          the partition ceiling
     * @param bool   $dry_run
     * @return array{added: list<string>, start: string|null, covered: bool}
     */
    public function extendPartitionsBack( $table_name, $from, $detail_months = self::PARTITION_DETAIL_MONTHS,
            $limit = self::PARTITION_COUNT_LIMIT, $dry_run = false ) {

        return $this->partitions()->extendPartitionsBack( $table_name, $from, $detail_months, $limit, $dry_run );
    }

    /**
     * Delegates to Db\Partitions::backRanges().
     *
     * @param string $from
     * @param string $less_than  the first partition's upper bound
     * @param int    $others     partitions after the first
     * @param int    $detail_months
     * @param int    $limit
     * @return PartitionRanges
     */
    public static function backRanges( $from, $less_than, $others, $detail_months, $limit ) {

        return \OWA\Core\Db\Partitions::backRanges( $from, $less_than, $others, $detail_months, $limit );
    }

    /**
     * Delegates to Db\Partitions::getDroppablePartitions().
     *
     * @param string $table_name
     * @param int|string $older_than_yyyymmdd
     * @return array{drop: list<string>, effective: string|null, straddling: Span|null, requested: string|null}
     */
    public function getDroppablePartitions( $table_name, $older_than_yyyymmdd ) {

        return $this->partitions()->getDroppablePartitions( $table_name, $older_than_yyyymmdd );
    }

    /**
     * Delegates to Db\Partitions::repartitionTable().
     *
     * @param string      $table_name
     * @param string      $granularity  quarter-month|half-month|monthly
     * @param bool        $dry_run      report the statements without running them
     * @param string|null $from         first day to convert, yyyymmdd
     * @param string|null $to           first day not to convert, yyyymmdd
     * @param array{start: string, less_than: string}|null $skip  to leave untouched
     * @return array{changed: list<string>, skipped: int, failed: list<string>, planned: int}
     */
    public function repartitionTable( $table_name, $granularity, $dry_run = false, $from = null, $to = null, $skip = null ) {

        return $this->partitions()->repartitionTable( $table_name, $granularity, $dry_run, $from, $to, $skip );
    }

    /**
     * The partition management this Db delegates to.
     *
     * A new one per call: one held here would make a cycle with the Db and
     * hold off its __destruct().
     *
     * @return Db\Partitions
     */
    private function partitions() {

        return new \OWA\Core\Db\Partitions( $this );
    }

    /**
     * Seconds a partition change waits for its table before giving up.
     *
     * An ALTER TABLE needs the table's exclusive metadata lock, so it waits for
     * every statement already running on the table -- a report query, a cube
     * build's read, a dump. While it waits, every statement that arrives after
     * it queues behind it, and on owa_event_raw that is every beacon. The
     * server's own limit (lock_wait_timeout) is a year by default.
     */
    const PARTITION_CHANGE_WAIT = 5;

    /** @var bool the last partition change gave up waiting for its table */
    protected $partition_change_busy = false;

    /**
     * Whether the last partition change was not made because its table was in use.
     *
     * Nothing was changed when this is true -- the statement never got the
     * lock -- so the caller can try again later.
     *
     * @return bool
     */
    public function lastPartitionChangeWasBusy() {

        return $this->partition_change_busy;
    }

    /**
     * The server's version string, as it reports it; '' when it does not say.
     *
     * @return string
     */
    public function serverVersion() {

        return '';
    }

    /**
     * Run $fn with every SELECT in it limited to $seconds, then restore the
     * session's own limit.
     *
     * For queries any report viewer shapes, whose cost the database cannot
     * bound by itself: a filter or a wide period can make one run for minutes
     * on a server that also serves other sites. A driver whose server can cap
     * a statement's time overrides this (MysqlDialect); here $fn runs
     * unlimited.
     *
     * @param int      $seconds
     * @param callable $fn
     * @return mixed   $fn's result
     */
    public function withSelectTimeLimit( $seconds, callable $fn ) {

        return $fn();
    }

    /**
     * Run a statement that changes a table's partitions, waiting at most
     * PARTITION_CHANGE_WAIT seconds for the table.
     *
     * The connection is shared -- the scheduler runs ingest and the cube build
     * on it after the rotate -- so its own limit is put back afterwards. A
     * limit already shorter is left as it is.
     *
     * Db\Partitions makes every partition change through this.
     *
     * @param string $table_name  for the message
     * @param string $sql
     * @return mixed  query()'s result
     */
    protected function changePartitions( $table_name, $sql ) {

        $this->partition_change_busy = false;

        $previous = null;

        if ( defined( 'OWA_SQL_GET_LOCK_WAIT' ) && defined( 'OWA_SQL_SET_LOCK_WAIT' ) ) {

            $row = $this->get_row( OWA_SQL_GET_LOCK_WAIT );

            if ( is_array( $row ) && is_numeric( $row['seconds'] )
              && (int) $row['seconds'] > self::PARTITION_CHANGE_WAIT
              && $this->query( sprintf( OWA_SQL_SET_LOCK_WAIT, self::PARTITION_CHANGE_WAIT ) ) !== false ) {

                $previous = (int) $row['seconds'];
            }
        }

        try {

            $result = $this->query( $sql );

            // 1205, which MySQL and MariaDB both spell this way, for a
            // metadata lock as for a row lock.
            if ( $result === false && stripos( $this->last_query_error, 'Lock wait timeout exceeded' ) !== false ) {

                $this->partition_change_busy = true;

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s was in use for %d seconds, so its partitions were not changed. The next run tries again.',
                    $table_name, self::PARTITION_CHANGE_WAIT ) );
            }

            return $result;

        } finally {

            if ( $previous !== null ) {

                $this->query( sprintf( OWA_SQL_SET_LOCK_WAIT, $previous ) );
            }
        }
    }

    /**
     * Add a column without the instant algorithm, rebuilding the table.
     *
     * For a table that EXCHANGE PARTITION compares byte for byte. An instantly
     * added column leaves row-format metadata that a freshly built staging
     * table cannot have -- staging is always empty, so it has no old rows to
     * version -- and the swap is then refused with error 1731.
     *
     * Online: reads and writes continue during the rebuild.
     *
     * @param string $table_name
     * @param string $column_name
     * @param string $column_definition
     * @return bool false where the server will not do it this way
     */
    public function addColumnRebuilding( $table_name, $column_name, $column_definition ) {

        if ( ! defined( 'OWA_SQL_ADD_COLUMN_REBUILD' ) ) {

            return false;
        }

        return (bool) $this->query( sprintf( OWA_SQL_ADD_COLUMN_REBUILD,
            $table_name, $column_name, $column_definition ) );
    }

    /**
     * Add and drop columns in ONE statement, rebuilding rather than instant.
     *
     * ONE REBUILD FOR EVERYTHING IT CARRIES, which is why it takes both
     * directions at once: the rebuild is the cost and the clause count is not,
     * so applying accumulated changes together is cheaper than applying them as
     * they arrive.
     *
     * INPLACE for the same reason addColumnRebuilding() pins it: the default is
     * INSTANT, and an instant column leaves row-format metadata that makes
     * EXCHANGE PARTITION refuse the swap with error 1731 -- on the next build
     * rather than here, so an unpinned ALTER looks like it worked and breaks
     * publishing from then on.
     *
     * @param string $table_name
     * @param array<string, string> $add   column name => type definition
     * @param list<string> $drop  column names
     * @return bool
     */
    public function alterColumnsRebuilding( $table_name, array $add = [], array $drop = [] ) {

        $clauses = [];

        foreach ( $add as $column => $definition ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $column ) ) {

                return false;
            }

            $clauses[] = sprintf( 'ADD %s %s', $column, $definition );
        }

        foreach ( $drop as $column ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $column ) ) {

                return false;
            }

            $clauses[] = sprintf( 'DROP %s', $column );
        }

        if ( ! $clauses
          || ! defined( 'OWA_SQL_ALTER_COLUMNS_REBUILD' )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        return (bool) $this->query( sprintf( OWA_SQL_ALTER_COLUMNS_REBUILD,
            $table_name, implode( ', ', $clauses ) ) );
    }

    /**
     * A table's column names, optionally only those with a prefix.
     *
     * A driver that can introspect overrides this.
     *
     * @param string $table_name
     * @param string $prefix
     * @return string[]
     */
    public function listColumns( $table_name, $prefix = '' ) {

        return [];
    }

    /**
     * Whether a table carries instant-column history.
     *
     * A driver that can answer overrides this; the dialect is where the
     * introspection lives, as it does for partitions. Unknown is null, not
     * false -- "no history" and "cannot tell" lead to different decisions.
     *
     * @param string $table_name
     * @return bool|null
     */
    public function hasInstantColumns( $table_name ) {

        return null;
    }

    /**
     * Rewrite a table in place, keeping its rows.
     *
     * Costs a full rebuild, so it belongs in a deliberate operation. Its one
     * use is clearing the row-format metadata an instant ADD COLUMN leaves
     * behind, which EXCHANGE PARTITION refuses against a staging table built by
     * CREATE TABLE LIKE. REBUILD PARTITION does not clear it.
     *
     * @param string $table_name
     * @return bool
     */
    public function rebuildTable( $table_name ) {

        if ( ! defined( 'OWA_SQL_REBUILD_TABLE' )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        return (bool) $this->query( sprintf( OWA_SQL_REBUILD_TABLE, $table_name ) );
    }

    /**
     * Adds index to a column
     *
     * Does nothing when an index over the same columns is already present.
     * addIndex() ran unnamed, so MySQL assigned a name each time and repeated
     * calls -- an update re-run, or two updates covering the same table --
     * silently accumulated duplicate copies rather than failing.
     *
     * @param  string $table_name
     * @param  string $column_name  one column, or several comma-separated
     * @param  string $index_definition
     * @return QueryResult  false when refused, true when already there
     */
    public function addIndex($table_name, $column_name, $index_definition = '') {

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
     * Drops the index named $column_name
     *
     * @param  string $table_name
     * @param  string $column_name
     * @return QueryResult
     */
    public function dropIndex($table_name, $column_name) {

        return $this->query(sprintf(OWA_SQL_DROP_INDEX, $column_name, $table_name));
    }

    /**
     * Creates a new table
     *
     * @param \OWA\Core\Entity $entity
     * @return mixed
     */
    public function createTable($entity) {

        //create column defs

        // A partitioned table cannot carry the primary key inline: the
        // partitioning column has to be part of it, so it is declared at table
        // level below. Only when the driver can actually partition.
        $partition_column = null;

        if ( method_exists( $entity, 'getPartitionColumn' ) && $this->supportsPartitioning() ) {

            $partition_column = $entity->getPartitionColumn();
        }

        // A key over several columns is a table-level clause
        // (Entity::setCompositePrimaryKey()), declared in place of the inline one.
        $composite = method_exists( $entity, 'getCompositePrimaryKey' ) ? $entity->getCompositePrimaryKey() : [];

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
        $indexes = [];

        foreach ($all_cols as $k => $v){

            // get column definition
            $columns .= $v.' '.$entity->getColumnDefinition($v, $partition_column || $composite);

            if ( $entity->isColumnIndexed( $v ) ) {

                $indexes[] = sprintf( 'INDEX (%s)', $v );
            }

            // Add commas to column statement
            if ($i < $count - 1):

                $columns .= ', ';

            endif;

            $i++;

        }

        // Multi-column indexes are declared on the ENTITY, not on a column --
        // see Entity::addCompositeIndex(). Named, so that an update removing
        // one has something to pass to dropIndex().
        if ( method_exists( $entity, 'getCompositeIndexes' ) ) {

            foreach ( $entity->getCompositeIndexes() as $index_name => $index_columns ) {

                $indexes[] = sprintf(
                    'INDEX %s (%s)', $index_name, implode( ', ', $index_columns ) );
            }
        }

        if ( method_exists( $entity, 'getUniqueIndexes' ) ) {

            foreach ( $entity->getUniqueIndexes() as $index_name => $index_columns ) {

                $indexes[] = sprintf(
                    'UNIQUE INDEX %s (%s)', $index_name, implode( ', ', $index_columns ) );
            }
        }

        if ( $composite ) {

            $columns .= sprintf( ', %s (%s)', OWA_DTD_PRIMARY_KEY, implode( ', ', $composite ) );
        }

        if ( $indexes ) {

            $columns .= ', ' . implode( ', ', $indexes );
        }

        $table_options = $this->tableOptions( $entity->getTableOptions() );

        if ( $partition_column ) {

            $pk = $entity->getPrimaryKeyColumn();

            // Without a declared key, the row's own key with the partition column after it.
            if ( ! $composite && $pk && $pk !== $partition_column ) {

                $columns .= sprintf( ', %s (%s, %s)', OWA_DTD_PRIMARY_KEY, $pk, $partition_column );
            }

            /*
             * Cover the current month and a year ahead, so that the catch-all
             * stays empty until the lead runs down. The daily rotate-partitions
             * job tops it up; a table created and never topped up still has a
             * year before anything reaches the catch-all.
             *
             * AN ENTITY MAY ASK FOR A DIFFERENT SHAPE, and the reporting cube
             * does: the front of its lead has to be daily or every rebuild
             * rewrites a whole month. Built here rather than left to the first
             * partition-rotate, because until that runs the table is in the
             * state the daily part exists to avoid -- and a rotate that is not
             * scheduled never comes.
             */
            $daily = (int) $entity->getDailyLeadMonths();

            $ranges = self::makeLeadRanges( $daily );

            // An entity cannot spend more than the hard ceiling on its own
            // say-so. Falling back to a lead of one granularity leaves a correct
            // table that rebuilds a period at a time, which partition-rotate
            // reports.
            if ( $daily && count( $ranges ) > self::PARTITION_COUNT_LIMIT ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s asked for %d partitions at creation, over the ceiling of %d. '
                  . 'Created monthly throughout instead.',
                    $entity->getTableName(), count( $ranges ), self::PARTITION_COUNT_LIMIT ) );

                $ranges = self::makeLeadRanges( 0 );
            }

            $table_options .= $this->makePartitionClause( $partition_column, $ranges );
        }

        return $this->query(sprintf(OWA_SQL_CREATE_TABLE, $entity->getTableName(), $columns, $table_options));
    }

    /**
     * The collation a table in this character set is declared with, or null
     * for the server's default. See OWA_DTD_CHARACTER_ENCODING_UTF8_COLLATION.
     *
     * @param string $character_encoding
     * @return string|null
     */
    public function tableCollation( $character_encoding ) {

        if ( ! defined( 'OWA_DTD_CHARACTER_ENCODING_UTF8_COLLATION' ) ) {

            return null;
        }

        return strtolower( (string) $character_encoding ) === 'utf8'
            ? (string) OWA_DTD_CHARACTER_ENCODING_UTF8_COLLATION
            : null;
    }

    /**
     * The engine, character set, collation and row format a table is created with.
     *
     * Every CREATE TABLE goes through this, including the ones that do not
     * start from an entity: a table that names none of these takes the
     * database's defaults, which on an existing install can be latin1.
     *
     * @param array{table_type?: string, character_encoding?: string, collation?: string, row_format?: string} $options
     * @return string
     */
    public function tableOptions( array $options = [] ) {

        $table_options = '';

        // table type
        switch ($options['table_type'] ?? null) {

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

        $collation = array_key_exists( 'collation', $options )
            ? $options['collation']
            : $this->tableCollation( (string) $options['character_encoding'] );

        if ( $collation && defined( 'OWA_DTD_TABLE_COLLATION' ) ) {

            $table_options .= sprintf( ' ' . OWA_DTD_TABLE_COLLATION, $collation );
        }

        /*
         * Declared rather than inherited. InnoDB's ~8,126-byte on-page row
         * limit is the one that actually stops a wide table being created, and
         * how much of a long column can be moved off the page to satisfy it
         * depends entirely on this: the reporting cube is creatable under
         * DYNAMIC and refused under COMPACT and REDUNDANT.
         */
        if ( defined( 'OWA_DTD_TABLE_ROW_FORMAT' ) ) {

            if ( ! array_key_exists( 'row_format', $options ) ) {

                $options['row_format'] = OWA_DTD_TABLE_ROW_FORMAT_DEFAULT;
            }

            $table_options .= sprintf( ' ' . OWA_DTD_TABLE_ROW_FORMAT, $options['row_format'] );
        }

        return $table_options;
    }



    /**
     * Begins a SQL transaction statement
     *
     * @return QueryResult
     */
    public function beginTransaction() {

        return $this->query(OWA_SQL_BEGIN_TRANSACTION);
    }

    /**
     * Ends a SQL transaction statement
     *
     * @return QueryResult
     */
    public function endTransaction() {

        return $this->query(OWA_SQL_END_TRANSACTION);
    }

    /**
     * Abandons the open transaction.
     *
     * v2 ingest writes one beacon as several rows, all of them or none.
     *
     * @return QueryResult
     */
    public function rollbackTransaction() {

        return $this->query(OWA_SQL_ROLLBACK_TRANSACTION);
    }

    /**
     * @param  string $column_name
     * @return string
     */
    public function count($column_name) {

        return sprintf(OWA_SQL_COUNT, $column_name);
    }

    /**
     * @param  string $column_name
     * @return string
     */
    public function sum($column_name) {

        return sprintf(OWA_SQL_SUM, $column_name);
    }

    /**
     * @param  string $column_name
     * @return string
     */
    public function distinct($column_name) {

        return sprintf(OWA_SQL_DISTINCT, $column_name);
    }

    /**
     * Rows the last statement changed. The base class cannot tell.
     *
     * @return int|string|false
     */
    public function getAffectedRows() {

        return false;
    }
    
    /**
     * @param  string $value
     * @return bool
     */
    public function isValidSortDirectionValue( $value ) {
        
        return in_array( strtolower( $value ), [ strtolower( OWA_SQL_DESCENDING ), strtolower( OWA_SQL_ASCENDING) ] ); 
    }
    
    /**
     * @return string
     */
    public function getDefaultSortDirection() {
        
        return OWA_SQL_DESCENDING;
    }
}
