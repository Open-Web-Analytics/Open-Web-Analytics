<?php
namespace OWA\Core\Db;


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


// The MySQL DDL vocabulary and schema introspection now live in the
// MysqlDialect trait, shared with the PDO driver. `use` below is what defines
// the OWA_DTD_* / OWA_SQL_* constants -- they are dialect, not transport.

class Mysql extends \OWA\Core\Db {

    use MysqlDialect;

    /**
     * A connect attempt has already failed and must not be repeated.
     *
     * Releasing the dead handle on failure is what stops it being mistaken for
     * a connection -- but it also makes `! $this->connection` true again, so
     * without this every query would re-dial a database that is down, and every
     * attempt raises its own warning. Cleared by close(), so an explicit
     * reconnect is still possible.
     *
     * @var bool
     */
    protected $connect_failed = false;

    function connect() {

        if ( $this->connect_failed ) {

            return false;
        }

        if ( ! $this->connection ) {

            // make a persistent connection if need be.
            if ( $this->getConnectionParam('persistant') ) {

                $host = 'p:' . $this->getConnectionParam('host');

            } else {

                $host = $this->getConnectionParam('host');
            }

            if ($this->getConnectionParam('port')) {
                $port = $this->getConnectionParam('port');
            } else {
                $port = 3306;
            }
            
            $socket = null;
            $client_flags = defined( 'OWA_MYSQL_CLIENT_FLAGS' ) ? OWA_MYSQL_CLIENT_FLAGS : 0;
            
            /*
             * Set the MySQLi error reporting off.
             * This is needed due to the default value change from `MYSQLI_REPORT_OFF`
             * to `MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT` in PHP 8.1.
             */
            mysqli_report( MYSQLI_REPORT_OFF );
            
            $this->connection = mysqli_init();


            // A FAILED CONNECT IS NOT A CONNECTION.
            //
            // mysqli_init() always returns an object, so `$this->connection`
            // is truthy whether or not the connect below succeeds, and the
            // guard at the end of this method -- which exists precisely to
            // report a failure -- can never fire. What happened instead was
            // that mysqli_set_charset() was called on a handle that was never
            // connected, which in PHP 8 raises "mysqli object is not fully
            // initialized": an Error, not an Exception, so query()'s catch does
            // not see it either. A refused connection therefore took out the
            // whole request with an uncaught fatal rather than degrading.
            //
            // Observed in production: RDS refused a connection for a few
            // seconds and every page and tracking request during that window
            // fataled instead of logging and moving on.
            //
            // So the return value decides, and the dead handle is released
            // before anything can touch it.
            $connected = mysqli_real_connect(
                $this->connection,
                $host,
                $this->getConnectionParam('user'),
                $this->getConnectionParam('password'),
                $this->getConnectionParam('name'),
                $port,
                $socket,
                $client_flags
            );

            if ( ! $connected ) {

                $this->connection = null;
                $this->connection_status = false;
                $this->connect_failed = true;

                $this->e->alert( 'Could not connect to database.' );

                return false;
            }

            // Explicitly set the character set, from the same constant the
            // schema is built with. The connection encoding is the binding
            // constraint of the two -- a four-byte character is mangled in
            // transit by a three-byte connection however the column is
            // declared -- so these must not be allowed to drift apart.
            $encoding = $this->connectionCharacterEncoding();

            if (function_exists('mysqli_set_charset')) {

                mysqli_set_charset($this->connection, $encoding );

            } else {

                $this->query( sprintf( "SET NAMES '%s'", $encoding ) );
            }

            // Session sql_mode. Historically a hardcoded "" here, which disables
            // strict mode; now sourced from the dialect so it can be raised per
            // install or per test run. See MysqlDialect::sessionSqlMode().
            $mode = $this->sessionSqlMode();

            if ( $mode !== null ) {

                $this->query( sprintf( "SET SESSION sql_mode='%s'", $this->prepare( $mode ) ) );
            }

        }

        if ( ! $this->connection ) {

            $this->e->alert('Could not connect to database.');
            $this->connection_status = false;
            return false;

        } else {

            $this->connection_status = true;
            return true;
        }
    }


    /**
     * Database Query
     *
     * @param     string $sql
     * @access     public
     *
     */
    function query( $sql, array $params = array() ) {
  
          if ( $this->connection_status == false) {

              \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

              $connected = $this->connect();

              \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

                // Without a connection there is nothing to run the query
                // against, and every mysqli_* call below would raise on the
                // released handle. Report it the way a failed query reports
                // itself, so callers that already handle a falsy result --
                // get_row() and get_results() both do -- degrade rather than
                // die.
                if ( ! $connected ) {

                    $this->new_result = false;

                    return false;
                }
          }
  
          \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

        $this->e->debug(sprintf('Query: %s', $sql));

        $this->result = array();

        $this->new_result = '';

        if ( ! empty( $this->new_result ) ) {

            mysqli_free_result($this->new_result);
        }

        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__, $sql);

       try {
        $result = $params
              ? $this->executeBound( $sql, $params )
              : @mysqli_query( $this->connection, $sql );
    
            \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
            // Log Errors
    
            if ( mysqli_errno( $this->connection ) ) {
    
                $errno = mysqli_errno( $this->connection );

                $this->logQueryError(
                    sprintf( '(%s) %s', $errno, mysqli_error( $this->connection ) ),
                    $sql,
                    // mysqli reports the vendor code only. 1062 duplicate entry,
                    // 1452/1451 foreign key -- the same class SQLSTATE 23000
                    // covers for the PDO driver.
                    in_array( (int) $errno, array( 1062, 1451, 1452 ), true )
                );
            }
    
            \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
        } catch(\Exception $e) {
            $result = false;
           $this->e->debug(
                    sprintf(
                        'An exception occurred while running the database query. Exception: %s. Query: %s',
                         htmlspecialchars($e->getMessage()),
                        $sql
                    )
                );
        }
        $this->new_result = $result;

        return $this->new_result;
    }

    function close() {

        // mysqli_close() on a handle that is already closed raises an Error in
        // PHP 8, and @ cannot suppress an Error. Db::__destruct() closes when
        // isConnectionEstablished() is still true, so without clearing both of
        // these here an explicit close() followed by shutdown closes twice and
        // the second one is an uncaught fatal -- which also makes the process
        // exit 255 after it has done its work correctly.
        if ( $this->connection instanceof \mysqli ) {

            @mysqli_close( $this->connection );
        }

        $this->connection        = null;
        $this->connection_status = false;

        // An explicit close is a deliberate reset, so a later connect() may try
        // again -- unlike the per-query retries connect() refuses.
        $this->connect_failed    = false;
    }

    /**
     * The result set ONE ROW AT A TIME, as a generator.
     *
     * See the PDO driver for why this exists. Rows go through stringifyRow()
     * exactly as get_results() sends them, so a caller that switches between
     * the two sees the same values -- mysqlnd hands back native types where
     * the rest of OWA expects strings.
     *
     * @param  string $sql
     * @param  array  $params
     * @return \Generator
     */
    function get_result_iterator( $sql, array $params = array() ) {

        if ( $sql ) {

            $this->query( $sql, $params );
        }

        if ( ! $this->new_result ) {

            return;
        }

        while ( $row = mysqli_fetch_assoc( $this->new_result ) ) {

            yield $this->stringifyRow( $row );
        }
    }

    /**
     * Fetch result set array
     *
     * Null, not an empty array, when there is nothing to return -- no rows, or
     * a query that failed. Callers have always had to allow for that, so the
     * behaviour is left alone and the type is corrected to match it: returning
     * an array instead would silently change what `=== null` sees.
     *
     * @param     string $sql
     * @return     array|null
     * @access  public
     */
    function get_results( $sql, array $params = array() ) {

        if ( $sql ) {

            $this->query($sql, $params);
        }

        //$this->result = array();

        if (!$this->new_result) {
            return null;
        }

        while ( $row = mysqli_fetch_assoc( $this->new_result ) ) {

            array_push( $this->result, $this->stringifyRow( $row ) );

        }

        if ( $this->result ) {

            return $this->result;

        } else {

            return null;
        }
    }

    /**
     * Fetch Single Row
     *
     * Null when the query returns no row -- and also when it fails, since a
     * failed query has no row either. query() returns false in that case, which
     * mysqli_fetch_assoc() refuses, so a failure raised a TypeError rather than
     * reporting itself as no result. Every caller already tests the return
     * value, because "no row" has always been a normal answer.
     *
     * @param string $sql
     * @return array|null
     */
    function get_row($sql, array $params = array()) {

        $result = $this->query($sql, $params);

        if ( ! $result || ! ( $this->new_result instanceof \mysqli_result ) ) {

            return null;
        }

        return $this->stringifyRow( mysqli_fetch_assoc($this->new_result) );
    }

    /**
     * Return a fetched row with its values as strings.
     *
     * mysqli is inconsistent with itself: mysqli_query() yields strings, while a
     * PREPARED statement fetched via mysqli_stmt_get_result() yields native PHP
     * types under mysqlnd. Introducing bound parameters therefore changed the
     * type of every value the builder returns -- '0' became 0 -- which breaks
     * === comparisons across the codebase and would change the REST API's JSON,
     * serialising counts as numbers instead of quoted strings.
     *
     * MYSQLI_OPT_INT_AND_FLOAT_NATIVE is the documented switch for this and has
     * NO EFFECT here (mysqlnd 8.2, set before real_connect -- verified both
     * orderings), so the coercion is explicit. PDO's ATTR_STRINGIFY_FETCHES does
     * the same job on the other driver, and DbDriverSqlParityTest compares the
     * two drivers' rows so they cannot drift apart again.
     *
     * NULL IS PRESERVED. A blanket (string) cast turns NULL into '', which is a
     * different value -- OWA stores real NULLs and distinguishes them from empty
     * strings.
     */
    protected function stringifyRow( $row ) {

        if ( ! is_array( $row ) ) {

            return $row;
        }

        foreach ( $row as $k => $v ) {

            if ( $v !== null && ! is_string( $v ) && ! is_array( $v ) ) {

                $row[ $k ] = (string) $v;
            }
        }

        return $row;
    }


    /**
     * Prepares and escapes string
     *
     * SQL-injection safety here is provided by mysqli_real_escape_string on
     * the live connection. Do NOT layer value-content filters (comma/paren
     * stripping, keyword removal) on top: this method is invoked for every
     * bound value, including serialized configuration blobs, and mutating
     * the byte content would silently corrupt length-prefixed data
     * (serialize(), JSON, etc.).
     *
     * Value-content sanitization for untrusted request fields belongs at
     * the request-container / controller layer, not here.
     *
     * @param string $string
     * @return string
     */
    function prepare( $string ) {
        if(is_null($string)){
            return $string;
        }
        if ($this->connection_status == false) {
              $this->connect();
          }

        return mysqli_real_escape_string( $this->connection, $string );

    }

    /**
     * Run a statement with bound parameters.
     *
     * mysqli's binding is more awkward than PDO's: one type STRING covering all
     * parameters, and bind_param() takes its arguments BY REFERENCE, so values
     * must be held in a real array and spread -- passing expressions is a fatal.
     *
     * Returns what mysqli_query() would: a mysqli_result for a SELECT, true for
     * a statement with no result set, false on failure. get_results() and
     * get_row() then behave identically whether or not parameters were used.
     *
     * @return \mysqli_result|bool
     */
    protected function executeBound( $sql, array $params ) {

        $statement = @mysqli_prepare( $this->connection, $sql );

        if ( ! $statement ) {

            return false;
        }

        $types  = '';
        $values = array();

        foreach ( $params as $value ) {

            if ( is_int( $value ) || is_bool( $value ) ) {

                $types .= 'i';
                $values[] = (int) $value;

            } elseif ( is_float( $value ) ) {

                $types .= 'd';
                $values[] = $value;

            } else {

                // Null included: mysqli sends a typed NULL for a null bound as
                // 's', which keeps it a NULL rather than an empty string.
                $types .= 's';
                $values[] = $value;
            }
        }

        // Spread from a variable: bind_param takes references.
        if ( ! @mysqli_stmt_bind_param( $statement, $types, ...$values ) ) {

            @mysqli_stmt_close( $statement );

            return false;
        }

        if ( ! @mysqli_stmt_execute( $statement ) ) {

            @mysqli_stmt_close( $statement );

            return false;
        }

        $result = @mysqli_stmt_get_result( $statement );

        if ( $result === false ) {

            // No result set (INSERT/UPDATE/DELETE). Affected rows must be read
            // BEFORE the statement is closed, or getAffectedRows() reports on a
            // dead handle.
            $this->rows_affected = mysqli_stmt_affected_rows( $statement );

            @mysqli_stmt_close( $statement );

            return true;
        }

        @mysqli_stmt_close( $statement );

        return $result;
    }

    function getAffectedRows() {

        // A bound statement's affected-row count is captured at execute time
        // (see executeBound) because the statement handle is closed immediately;
        // mysqli_affected_rows() on the CONNECTION does not report it.
        if ( $this->rows_affected !== null ) {

            $affected = $this->rows_affected;
            $this->rows_affected = null;

            return $affected;
        }

        // mysqli_affected_rows() has required the connection arg since PHP 8.0.
        return mysqli_affected_rows( $this->connection );
    }
}

?>