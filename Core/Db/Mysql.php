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


define('OWA_DTD_BIGINT', 'BIGINT');
define('OWA_DTD_INT', 'INT');
define('OWA_DTD_TINYINT', 'TINYINT(1)');
define('OWA_DTD_TINYINT2', 'TINYINT(2)');
define('OWA_DTD_TINYINT4', 'TINYINT(4)');
define('OWA_DTD_SERIAL', 'SERIAL');
define('OWA_DTD_PRIMARY_KEY', 'PRIMARY KEY');
define('OWA_DTD_VARCHAR10', 'VARCHAR(10)');
define('OWA_DTD_VARCHAR255', 'VARCHAR(255)');
define('OWA_DTD_VARCHAR', 'VARCHAR(%s)');
define('OWA_DTD_TEXT', 'MEDIUMTEXT');
define('OWA_DTD_BOOLEAN', 'TINYINT(1)');
define('OWA_DTD_TIMESTAMP', 'TIMESTAMP');
define('OWA_DTD_BLOB', 'BLOB');
define('OWA_DTD_INDEX', 'KEY');
define('OWA_DTD_AUTO_INCREMENT', 'AUTO_INCREMENT');
define('OWA_DTD_NOT_NULL', 'NOT NULL');
define('OWA_DTD_UNIQUE', 'PRIMARY KEY(%s)');
define('OWA_SQL_ADD_COLUMN', 'ALTER TABLE %s ADD %s %s');
define('OWA_SQL_DROP_COLUMN', 'ALTER TABLE %s DROP %s');
define('OWA_SQL_RENAME_COLUMN', 'ALTER TABLE %s CHANGE %s %s %s');
define('OWA_SQL_MODIFY_COLUMN', 'ALTER TABLE %s MODIFY %s %s');
define('OWA_SQL_RENAME_TABLE', 'ALTER TABLE %s RENAME %s');
define('OWA_SQL_CREATE_TABLE', 'CREATE TABLE IF NOT EXISTS %s (%s) %s');
define('OWA_SQL_DROP_TABLE', 'DROP TABLE IF EXISTS %s');
define('OWA_SQL_SHOW_TABLE', "show tables like '%s'");
define('OWA_SQL_INSERT_ROW', 'INSERT into %s (%s) VALUES (%s)');
define('OWA_SQL_UPDATE_ROW', 'UPDATE %s SET %s %s');
define('OWA_SQL_DELETE_ROW', "DELETE from %s %s");
define('OWA_SQL_CREATE_INDEX', 'CREATE INDEX %s ON %s (%s)');
define('OWA_SQL_DROP_INDEX', 'DROP INDEX %s ON %s');
define('OWA_SQL_INDEX', 'INDEX (%s)');
define('OWA_SQL_BEGIN_TRANSACTION', 'BEGIN');
define('OWA_SQL_END_TRANSACTION', 'COMMIT');
define('OWA_DTD_TABLE_TYPE', 'ENGINE = %s');
define('OWA_DTD_TABLE_TYPE_DEFAULT', 'INNODB');
define('OWA_DTD_TABLE_TYPE_DISK', 'INNODB');
define('OWA_DTD_TABLE_TYPE_MEMORY', 'MEMORY');
define('OWA_SQL_ALTER_TABLE_TYPE', 'ALTER TABLE %s ENGINE = %s');
// Partitioning. A driver that cannot partition simply leaves these undefined,
// and the table is created and managed unpartitioned -- the feature is absent
// rather than broken. The syntax differs enough between platforms (Postgres
// declares the parent then creates each partition as its own table) that only
// the fragments belong here; the sequencing stays in Db.
define('OWA_DTD_PARTITION_BY_RANGE', ' PARTITION BY RANGE (%s) (%s)');
define('OWA_DTD_PARTITION_LESS_THAN', 'PARTITION %s VALUES LESS THAN (%s)');
define('OWA_DTD_PARTITION_MAXVALUE', 'MAXVALUE');
define('OWA_SQL_PARTITION_TABLE', 'ALTER TABLE %s' . OWA_DTD_PARTITION_BY_RANGE);
define('OWA_SQL_DROP_PARTITION', 'ALTER TABLE %s DROP PARTITION %s');
define('OWA_SQL_REORGANIZE_PARTITION', 'ALTER TABLE %s REORGANIZE PARTITION %s INTO (%s)');
define('OWA_SQL_JOIN_LEFT_OUTER', 'LEFT OUTER JOIN');
define('OWA_SQL_JOIN_LEFT_INNER', 'LEFT INNER JOIN');
define('OWA_SQL_JOIN_RIGHT_OUTER', 'RIGHT OUTER JOIN');
define('OWA_SQL_JOIN_RIGHT_INNER', 'RIGHT INNER JOIN');
define('OWA_SQL_JOIN', 'JOIN');
define('OWA_SQL_DESCENDING', 'DESC');
define('OWA_SQL_ASCENDING', 'ASC');
define('OWA_SQL_REGEXP', 'REGEXP');
define('OWA_SQL_NOTREGEXP', 'NOT REGEXP');
define('OWA_SQL_LIKE', 'LIKE');
define('OWA_SQL_ADD_INDEX', 'ALTER TABLE %s ADD INDEX (%s) %s');
// Named form. The unnamed one above lets MySQL pick the name, so repeating it
// yields site_id, site_id_2, site_id_3 rather than failing as a duplicate.
define('OWA_SQL_ADD_NAMED_INDEX', 'ALTER TABLE %s ADD INDEX %s (%s) %s');
define('OWA_SQL_COUNT', 'COUNT(%s)');
define('OWA_SQL_SUM', 'SUM(%s)');
define('OWA_SQL_ROUND', 'ROUND(%s)');
define('OWA_SQL_AVERAGE', 'AVG(%s)');
define('OWA_SQL_DISTINCT', 'DISTINCT %s');
define('OWA_SQL_DIVISION', '(%s / %s)');
define('OWA_DTD_CHARACTER_ENCODING_UTF8', 'utf8');
define('OWA_DTD_TABLE_CHARACTER_ENCODING', 'CHARACTER SET = %s');


/**
 * MySQL Data Access Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class Mysql extends \OWA\Core\Db {

    function connect() {

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

                $this->e->alert( 'Could not connect to database.' );

                return false;
            }

            // explicitly set the character set as UTF-8
            if (function_exists('mysqli_set_charset')) {

                mysqli_set_charset($this->connection, 'utf8' );

            } else {

                $this->query("SET NAMES 'utf8'");
            }

            // turn off strict mode. needed on mysql 5.7 and lter when it is turned on by default.
            $this->query( "SET SESSION sql_mode=''" );

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
     * Can this driver partition tables?
     *
     * @return bool
     */
    function supportsPartitioning() {

        return defined( 'OWA_DTD_PARTITION_BY_RANGE' );
    }

    /**
     * The partitions on a table, in range order.
     *
     * @param string $table_name
     * @return array of ['name' => string, 'less_than' => string, 'rows' => int]
     */
    function listPartitions( $table_name ) {

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return array();
        }

        $sql = "SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS less_than, TABLE_ROWS AS rows_ "
             . "FROM information_schema.PARTITIONS "
             . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND PARTITION_NAME IS NOT NULL "
             . "ORDER BY PARTITION_ORDINAL_POSITION";

        $rows = $this->get_results( sprintf( $sql, $table_name ) );

        $out = array();

        foreach ( (array) $rows as $row ) {

            $out[] = array(
                'name'      => $row['name'],
                'less_than' => $row['less_than'],
                'rows'      => (int) $row['rows_'],
            );
        }

        return $out;
    }

    /**
     * Spare open-file slots on this server, or null if it cannot be read.
     *
     * Each partition is a file, and InnoDB caps how many tablespaces it keeps
     * open at once. That cap is shared with every table already present, so the
     * headroom for partitions is what is left after them -- not the cap itself.
     *
     * @return int|null
     */
    function getPartitionBudget() {

        $row = $this->get_row(
            "SELECT @@innodb_open_files AS cap, "
          . "(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_TYPE = 'BASE TABLE') AS used"
        );

        if ( ! $row || ! isset( $row['cap'] ) || ! $row['cap'] ) {

            return null;
        }

        return max( 0, (int) $row['cap'] - (int) $row['used'] );
    }

    /**
     * Row count and date range within one partition.
     *
     * Reads the partition itself rather than information_schema, because
     * TABLE_ROWS is an InnoDB estimate that can be out by a wide margin -- and
     * the whole point of asking about the catch-all is to know whether real data
     * has collected there. The extension restricts the scan to that one
     * partition, and the fact tables carry an index on the partitioning column,
     * which on a partitioned table is local to each partition: the bounds are a
     * seek, not a scan.
     *
     * @param string $table_name
     * @param string $partition
     * @param string $column
     * @return array|null  ['rows','min','max'], or null where it cannot be read
     */
    function getPartitionContents( $table_name, $partition, $column = 'yyyymmdd' ) {

        foreach ( array( $table_name, $partition, $column ) as $identifier ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $identifier ) ) {

                return null;
            }
        }

        $row = $this->get_row( sprintf(
            'SELECT COUNT(*) AS n, MIN(%1$s) AS lo, MAX(%1$s) AS hi FROM %2$s PARTITION (%3$s)',
            $column, $table_name, $partition
        ) );

        if ( ! $row ) {

            return null;
        }

        return array(
            'rows' => (int) $row['n'],
            'min'  => $row['lo'] === null ? null : (string) $row['lo'],
            'max'  => $row['hi'] === null ? null : (string) $row['hi'],
        );
    }

    /**
     * The columns of a table's primary key, in key order.
     *
     * @param string $table_name
     * @return string[]
     */
    function getPrimaryKeyColumns( $table_name ) {

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return array();
        }

        $sql = "SELECT COLUMN_NAME AS c FROM information_schema.STATISTICS "
             . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = 'PRIMARY' "
             . "ORDER BY SEQ_IN_INDEX";

        $cols = array();

        foreach ( (array) $this->get_results( sprintf( $sql, $table_name ) ) as $row ) {

            $cols[] = $row['c'];
        }

        return $cols;
    }

    /**
     * Is there already an index covering exactly these columns?
     *
     * Matched on the column list rather than the index name, so an index MySQL
     * named itself -- site_id, site_id_2 -- is recognised as covering site_id.
     *
     * @param string $table_name
     * @param string $column_name  one column, or a comma-separated list
     * @return bool
     */
    function indexExists( $table_name, $column_name ) {

        $cols = $this->normalizeIndexColumns( $column_name );

        if ( ! $cols || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        // Matched on the column list, which is what GROUP_CONCAT returns in
        // SEQ_IN_INDEX order, so the name MySQL happened to assign is irrelevant.
        $sql = "SELECT COUNT(*) AS n FROM ( "
             . "SELECT INDEX_NAME FROM information_schema.STATISTICS "
             . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' "
             . "GROUP BY INDEX_NAME "
             . "HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = '%s' ) x";

        $row = $this->get_row(
            sprintf( $sql, $table_name, implode( ',', $cols ) )
        );

        return ( is_array( $row ) && isset( $row['n'] ) && (int) $row['n'] > 0 );
    }

    /**
     * Every non-primary index on the OWA tables.
     *
     * Scoped to the 'owa_' prefix because an installation may share its
     * database with another application -- WordPress, typically -- and nothing
     * here should look at, let alone touch, tables OWA does not own.
     *
     * @return array of ['t' => table, 'i' => index, 'nu' => non_unique,
     *                   'ty' => type, 'cols' => comma-separated column list]
     */
    function listIndexes() {

        // Scoped to the 'owa_' prefix: an installation may share its database
        // with another application. Uniqueness and type come back too, so
        // indexes that merely share columns are not mistaken for copies of each
        // other. The grouping is left to the caller so the "keep one" decision
        // stays in PHP, where it can be read and tested.
        $sql = "SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, INDEX_TYPE AS ty, "
             . "GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols "
             . "FROM information_schema.STATISTICS "
             . "WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME <> 'PRIMARY' "
             . "AND TABLE_NAME LIKE 'owa\\\\_%' "
             . "GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE "
             . "ORDER BY TABLE_NAME, INDEX_NAME";

        $rows = $this->get_results( $sql );

        return is_array( $rows ) ? $rows : array();
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