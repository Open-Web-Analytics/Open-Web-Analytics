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

            // explicitly set the character set as UTF-8
            if (function_exists('mysqli_set_charset')) {

                mysqli_set_charset($this->connection, 'utf8' );

            } else {

                $this->query("SET NAMES 'utf8'");
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
    function query( $sql ) {
  
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
        $result = @mysqli_query( $this->connection, $sql );
    
            \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
            // Log Errors
    
            if ( mysqli_errno( $this->connection ) ) {
    
                $this->e->debug(
                    sprintf(
                        'A MySQL error ocured. Error: (%s) %s. Query: %s',
                        mysqli_errno( $this->connection ),
                        htmlspecialchars( mysqli_error( $this->connection ) ),
                        $sql
                    )
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
    function get_results( $sql ) {

        if ( $sql ) {

            $this->query($sql);
        }

        //$this->result = array();

        if (!$this->new_result) {
            return null;
        }

        while ( $row = mysqli_fetch_assoc( $this->new_result ) ) {

            array_push($this->result, $row);

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
    function get_row($sql) {

        $result = $this->query($sql);

        if ( ! $result || ! ( $this->new_result instanceof \mysqli_result ) ) {

            return null;
        }

        return mysqli_fetch_assoc($this->new_result);
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

    function getAffectedRows() {

        // mysqli_affected_rows() has required the connection arg since PHP 8.0.
        return mysqli_affected_rows( $this->connection );
    }
}

?>