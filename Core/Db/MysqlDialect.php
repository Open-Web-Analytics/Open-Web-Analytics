<?php
namespace OWA\Core\Db;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The MySQL dialect: its DDL vocabulary and its schema introspection.
 *
 * Split out because a driver is two separable things, and only one of them is
 * about MySQL-the-database:
 *
 *   TRANSPORT   how bytes reach the server -- mysqli, PDO, something else.
 *   DIALECT     what the SQL looks like, and how the schema is interrogated.
 *
 * Everything here is dialect. It is identical whether the statements travel over
 * mysqli or PDO, so both drivers share it rather than carrying two copies that
 * drift -- the introspection queries in particular encode real behaviour
 * (partition budgets, index shapes) that was measured once and should not be
 * re-derived per transport.
 *
 * THE CONSTANTS ARE GUARDED. They were previously bare define() calls in
 * Mysql.php, which was safe only because exactly one driver ever loaded. With a
 * second driver in the tree, two files can be autoloaded in one process -- a
 * test suite covering both will do it -- and a redefinition is a PHP warning
 * that CI fails on. Same values either way, so first-definer-wins is correct.
 *
 * Loading this trait is what defines the OWA_DTD_* / OWA_SQL_* vocabulary the
 * rest of OWA compiles its SQL from, so a driver must `use` it, not merely
 * extend Db.
 */

if ( ! defined( 'OWA_DTD_BIGINT' ) ) { define('OWA_DTD_BIGINT', 'BIGINT'); }
if ( ! defined( 'OWA_DTD_INT' ) ) { define('OWA_DTD_INT', 'INT'); }
if ( ! defined( 'OWA_DTD_TINYINT' ) ) { define('OWA_DTD_TINYINT', 'TINYINT(1)'); }
if ( ! defined( 'OWA_DTD_TINYINT2' ) ) { define('OWA_DTD_TINYINT2', 'TINYINT(2)'); }
if ( ! defined( 'OWA_DTD_TINYINT4' ) ) { define('OWA_DTD_TINYINT4', 'TINYINT(4)'); }
if ( ! defined( 'OWA_DTD_SERIAL' ) ) { define('OWA_DTD_SERIAL', 'SERIAL'); }
if ( ! defined( 'OWA_DTD_PRIMARY_KEY' ) ) { define('OWA_DTD_PRIMARY_KEY', 'PRIMARY KEY'); }
if ( ! defined( 'OWA_DTD_VARCHAR10' ) ) { define('OWA_DTD_VARCHAR10', 'VARCHAR(10)'); }
if ( ! defined( 'OWA_DTD_VARCHAR255' ) ) { define('OWA_DTD_VARCHAR255', 'VARCHAR(255)'); }
if ( ! defined( 'OWA_DTD_VARCHAR' ) ) { define('OWA_DTD_VARCHAR', 'VARCHAR(%s)'); }
if ( ! defined( 'OWA_DTD_TEXT' ) ) { define('OWA_DTD_TEXT', 'MEDIUMTEXT'); }
if ( ! defined( 'OWA_DTD_BOOLEAN' ) ) { define('OWA_DTD_BOOLEAN', 'TINYINT(1)'); }
if ( ! defined( 'OWA_DTD_TIMESTAMP' ) ) { define('OWA_DTD_TIMESTAMP', 'TIMESTAMP'); }
if ( ! defined( 'OWA_DTD_BLOB' ) ) { define('OWA_DTD_BLOB', 'BLOB'); }
if ( ! defined( 'OWA_DTD_INDEX' ) ) { define('OWA_DTD_INDEX', 'KEY'); }
if ( ! defined( 'OWA_DTD_AUTO_INCREMENT' ) ) { define('OWA_DTD_AUTO_INCREMENT', 'AUTO_INCREMENT'); }
if ( ! defined( 'OWA_DTD_NOT_NULL' ) ) { define('OWA_DTD_NOT_NULL', 'NOT NULL'); }
if ( ! defined( 'OWA_DTD_UNIQUE' ) ) { define('OWA_DTD_UNIQUE', 'PRIMARY KEY(%s)'); }
if ( ! defined( 'OWA_SQL_ADD_COLUMN' ) ) { define('OWA_SQL_ADD_COLUMN', 'ALTER TABLE %s ADD %s %s'); }
if ( ! defined( 'OWA_SQL_DROP_COLUMN' ) ) { define('OWA_SQL_DROP_COLUMN', 'ALTER TABLE %s DROP %s'); }
if ( ! defined( 'OWA_SQL_RENAME_COLUMN' ) ) { define('OWA_SQL_RENAME_COLUMN', 'ALTER TABLE %s CHANGE %s %s %s'); }
if ( ! defined( 'OWA_SQL_MODIFY_COLUMN' ) ) { define('OWA_SQL_MODIFY_COLUMN', 'ALTER TABLE %s MODIFY %s %s'); }
if ( ! defined( 'OWA_SQL_RENAME_TABLE' ) ) { define('OWA_SQL_RENAME_TABLE', 'ALTER TABLE %s RENAME %s'); }
if ( ! defined( 'OWA_SQL_CREATE_TABLE' ) ) { define('OWA_SQL_CREATE_TABLE', 'CREATE TABLE IF NOT EXISTS %s (%s) %s'); }
if ( ! defined( 'OWA_SQL_DROP_TABLE' ) ) { define('OWA_SQL_DROP_TABLE', 'DROP TABLE IF EXISTS %s'); }
if ( ! defined( 'OWA_SQL_SHOW_TABLE' ) ) { define('OWA_SQL_SHOW_TABLE', "show tables like '%s'"); }
if ( ! defined( 'OWA_SQL_INSERT_ROW' ) ) { define('OWA_SQL_INSERT_ROW', 'INSERT into %s (%s) VALUES (%s)'); }
if ( ! defined( 'OWA_SQL_UPDATE_ROW' ) ) { define('OWA_SQL_UPDATE_ROW', 'UPDATE %s SET %s %s'); }
if ( ! defined( 'OWA_SQL_DELETE_ROW' ) ) { define('OWA_SQL_DELETE_ROW', "DELETE from %s %s"); }
if ( ! defined( 'OWA_SQL_CREATE_INDEX' ) ) { define('OWA_SQL_CREATE_INDEX', 'CREATE INDEX %s ON %s (%s)'); }
if ( ! defined( 'OWA_SQL_DROP_INDEX' ) ) { define('OWA_SQL_DROP_INDEX', 'DROP INDEX %s ON %s'); }
if ( ! defined( 'OWA_SQL_INDEX' ) ) { define('OWA_SQL_INDEX', 'INDEX (%s)'); }
if ( ! defined( 'OWA_SQL_BEGIN_TRANSACTION' ) ) { define('OWA_SQL_BEGIN_TRANSACTION', 'BEGIN'); }
if ( ! defined( 'OWA_SQL_END_TRANSACTION' ) ) { define('OWA_SQL_END_TRANSACTION', 'COMMIT'); }
if ( ! defined( 'OWA_DTD_TABLE_TYPE' ) ) { define('OWA_DTD_TABLE_TYPE', 'ENGINE = %s'); }
if ( ! defined( 'OWA_DTD_TABLE_TYPE_DEFAULT' ) ) { define('OWA_DTD_TABLE_TYPE_DEFAULT', 'INNODB'); }
if ( ! defined( 'OWA_DTD_TABLE_TYPE_DISK' ) ) { define('OWA_DTD_TABLE_TYPE_DISK', 'INNODB'); }
if ( ! defined( 'OWA_DTD_TABLE_TYPE_MEMORY' ) ) { define('OWA_DTD_TABLE_TYPE_MEMORY', 'MEMORY'); }
if ( ! defined( 'OWA_SQL_ALTER_TABLE_TYPE' ) ) { define('OWA_SQL_ALTER_TABLE_TYPE', 'ALTER TABLE %s ENGINE = %s'); }
// Partitioning. A driver that cannot partition simply leaves these undefined,
// and the table is created and managed unpartitioned -- the feature is absent
// rather than broken. The syntax differs enough between platforms (Postgres
// declares the parent then creates each partition as its own table) that only
// the fragments belong here; the sequencing stays in Db.
if ( ! defined( 'OWA_DTD_PARTITION_BY_RANGE' ) ) { define('OWA_DTD_PARTITION_BY_RANGE', ' PARTITION BY RANGE (%s) (%s)'); }
if ( ! defined( 'OWA_DTD_PARTITION_LESS_THAN' ) ) { define('OWA_DTD_PARTITION_LESS_THAN', 'PARTITION %s VALUES LESS THAN (%s)'); }
if ( ! defined( 'OWA_DTD_PARTITION_MAXVALUE' ) ) { define('OWA_DTD_PARTITION_MAXVALUE', 'MAXVALUE'); }
if ( ! defined( 'OWA_SQL_PARTITION_TABLE' ) ) { define('OWA_SQL_PARTITION_TABLE', 'ALTER TABLE %s' . OWA_DTD_PARTITION_BY_RANGE); }
if ( ! defined( 'OWA_SQL_DROP_PARTITION' ) ) { define('OWA_SQL_DROP_PARTITION', 'ALTER TABLE %s DROP PARTITION %s'); }
if ( ! defined( 'OWA_SQL_REORGANIZE_PARTITION' ) ) { define('OWA_SQL_REORGANIZE_PARTITION', 'ALTER TABLE %s REORGANIZE PARTITION %s INTO (%s)'); }
if ( ! defined( 'OWA_SQL_JOIN_LEFT_OUTER' ) ) { define('OWA_SQL_JOIN_LEFT_OUTER', 'LEFT OUTER JOIN'); }
if ( ! defined( 'OWA_SQL_JOIN_LEFT_INNER' ) ) { define('OWA_SQL_JOIN_LEFT_INNER', 'LEFT INNER JOIN'); }
if ( ! defined( 'OWA_SQL_JOIN_RIGHT_OUTER' ) ) { define('OWA_SQL_JOIN_RIGHT_OUTER', 'RIGHT OUTER JOIN'); }
if ( ! defined( 'OWA_SQL_JOIN_RIGHT_INNER' ) ) { define('OWA_SQL_JOIN_RIGHT_INNER', 'RIGHT INNER JOIN'); }
if ( ! defined( 'OWA_SQL_JOIN' ) ) { define('OWA_SQL_JOIN', 'JOIN'); }
if ( ! defined( 'OWA_SQL_DESCENDING' ) ) { define('OWA_SQL_DESCENDING', 'DESC'); }
if ( ! defined( 'OWA_SQL_ASCENDING' ) ) { define('OWA_SQL_ASCENDING', 'ASC'); }
if ( ! defined( 'OWA_SQL_REGEXP' ) ) { define('OWA_SQL_REGEXP', 'REGEXP'); }
if ( ! defined( 'OWA_SQL_NOTREGEXP' ) ) { define('OWA_SQL_NOTREGEXP', 'NOT REGEXP'); }
if ( ! defined( 'OWA_SQL_LIKE' ) ) { define('OWA_SQL_LIKE', 'LIKE'); }
// Substring containment. MySQL spells it LOCATE(needle, haystack); PostgreSQL
// has POSITION(needle IN haystack) and SQL Server CHARINDEX, so the whole
// expression is the dialect's to give -- not just the function name. The
// argument order is part of the contract: needle first, then the column.
if ( ! defined( 'OWA_SQL_CONTAINS' ) ) { define('OWA_SQL_CONTAINS', 'LOCATE(%s, %s) > 0'); }
if ( ! defined( 'OWA_SQL_NOT_CONTAINS' ) ) { define('OWA_SQL_NOT_CONTAINS', 'LOCATE(%s, %s) = 0'); }
// Prefix match, spelled as containment AT POSITION ONE rather than as LIKE
// 'x%'. LIKE would read % and _ in the value as wildcards, so a goal event on
// "50% off" would quietly match far more than it says -- and escaping them is a
// thing to get wrong once per call site. Same argument order: needle, column.
if ( ! defined( 'OWA_SQL_STARTS_WITH' ) ) { define('OWA_SQL_STARTS_WITH', 'LOCATE(%s, %s) = 1'); }
if ( ! defined( 'OWA_SQL_ADD_INDEX' ) ) { define('OWA_SQL_ADD_INDEX', 'ALTER TABLE %s ADD INDEX (%s) %s'); }
// Named form. The unnamed one above lets MySQL pick the name, so repeating it
// yields site_id, site_id_2, site_id_3 rather than failing as a duplicate.
if ( ! defined( 'OWA_SQL_ADD_NAMED_INDEX' ) ) { define('OWA_SQL_ADD_NAMED_INDEX', 'ALTER TABLE %s ADD INDEX %s (%s) %s'); }
if ( ! defined( 'OWA_SQL_COUNT' ) ) { define('OWA_SQL_COUNT', 'COUNT(%s)'); }
// The first non-null of its arguments. Used by GoalEventPredicate so a NULL
// column compares as the empty string, the way GoalEvent::compare() sees it.
if ( ! defined( 'OWA_SQL_COALESCE' ) ) { define('OWA_SQL_COALESCE', 'COALESCE(%s, %s)'); }
if ( ! defined( 'OWA_SQL_SUM' ) ) { define('OWA_SQL_SUM', 'SUM(%s)'); }
if ( ! defined( 'OWA_SQL_ROUND' ) ) { define('OWA_SQL_ROUND', 'ROUND(%s)'); }
if ( ! defined( 'OWA_SQL_AVERAGE' ) ) { define('OWA_SQL_AVERAGE', 'AVG(%s)'); }
if ( ! defined( 'OWA_SQL_DISTINCT' ) ) { define('OWA_SQL_DISTINCT', 'DISTINCT %s'); }
if ( ! defined( 'OWA_SQL_DIVISION' ) ) { define('OWA_SQL_DIVISION', '(%s / %s)'); }
// The encoding NEW tables are declared with when the entity does not name one.
//
// 'utf8' is MySQL's three-byte encoding and cannot hold anything above U+FFFF.
// It stays the default because it is what every existing table already is, and
// changing it here would only affect tables created afterwards -- leaving one
// installation holding a mix with nothing to reconcile them.
//
// A table that wants better says so per-entity rather than per-installation.
// See Entity::getTableCharacterEncoding(): that is how v2 tables can be created
// as utf8mb4 alongside v1 tables that stay as they are, in the same database.
if ( ! defined( 'OWA_DTD_CHARACTER_ENCODING_UTF8' ) ) { define('OWA_DTD_CHARACTER_ENCODING_UTF8', 'utf8'); }

// The encoding the CONNECTION negotiates, which is a different question and has
// a different answer.
//
// It is not "whatever the tables are" -- tables may legitimately differ from
// each other. It is the widest thing the server understands, because the
// connection encoding is a CEILING on both directions: a four-byte character is
// mangled in transit by a three-byte connection however the destination column
// is declared.
//
// Raising it costs the older tables nothing. Verified against both: over a
// utf8mb4 connection a utf8 table still refuses a four-byte character exactly
// as it did before, three-byte text is unaffected, and a utf8mb4 table stores
// the character. So the ceiling can be raised once, for everyone, and each
// table keeps deciding for itself.
if ( ! defined( 'OWA_DTD_CONNECTION_ENCODING' ) ) { define('OWA_DTD_CONNECTION_ENCODING', 'utf8mb4'); }
if ( ! defined( 'OWA_DTD_TABLE_CHARACTER_ENCODING' ) ) { define('OWA_DTD_TABLE_CHARACTER_ENCODING', 'CHARACTER SET = %s'); }


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

trait MysqlDialect
{
    /**
     * The session sql_mode to apply on connect, or null to leave the server's.
     *
     * OWA sent `SET SESSION sql_mode=''` on every connection for years, which
     * disables strict mode. That is not free: without it MySQL coerces instead
     * of refusing, so a value too long for its column is truncated and a
     * non-numeric value written to an integer column silently becomes 0. Two
     * live installs were measured carrying rows whose yyyymmdd -- the partition
     * key, and the column every date-range report filters on -- had been
     * coerced to 0 that way, making those rows invisible to reporting.
     *
     * The default is now STRICT_ALL_TABLES: a bad write fails loudly instead of
     * storing something that looks like data and is not.
     *
     * WHAT CHANGES FOR AN INSTALL
     * A write that previously succeeded by being coerced now fails. Everything
     * OWA itself does was fixed before this default moved -- the whole suite
     * passes under strict, and so does the end-to-end ingestion path on both
     * drivers -- but a third-party module writing through the entity layer may
     * not have been. OWA_DB_SQL_MODE is the escape hatch, and reverting is one
     * line in owa-config.php:
     *
     *   define( 'OWA_DB_SQL_MODE', '' );                    // the old behaviour
     *   define( 'OWA_DB_SQL_MODE', null );                  // whatever the server sets
     *   define( 'OWA_DB_SQL_MODE', 'STRICT_ALL_TABLES' );   // the new default
     *
     * Chosen over null (leave the server alone) because OWA ships to hosts whose
     * configuration it does not control: an explicit mode means every install
     * behaves the way the test suite is verified to behave, rather than
     * inheriting whatever the host decided.
     *
     * @return string|null
     */
    /**
     * The encoding to negotiate on the connection.
     *
     * Deliberately NOT "whatever the tables are declared with" -- tables may
     * differ from one another, and the connection has to serve all of them.
     * It is a ceiling in both directions, so it is set to the widest encoding
     * and each table decides for itself underneath it.
     *
     * @return string
     */
    protected function connectionCharacterEncoding() {

        return defined( 'OWA_DTD_CONNECTION_ENCODING' )
            ? (string) OWA_DTD_CONNECTION_ENCODING
            : 'utf8mb4';
    }

    protected function sessionSqlMode() {

        if ( defined( 'OWA_DB_SQL_MODE' ) ) {

            return OWA_DB_SQL_MODE;
        }

        // Env override, so a test run or a one-off CLI check can raise the mode
        // without editing a config file. Deliberately NOT a general config
        // channel -- an install sets the constant.
        $env = getenv( 'OWA_DB_SQL_MODE' );

        if ( $env !== false ) {

            return $env;
        }

        // Strict by default. See the note above; OWA_DB_SQL_MODE reverts it.
        return 'STRICT_ALL_TABLES';
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
}
