<?php
namespace OWA\Core\Db;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * PDO transport, shared by every PDO-based driver.
 *
 * Transport only. The SQL vocabulary and the schema introspection are identical
 * to the mysqli driver's and come from MysqlDialect, so this file is about how
 * statements travel and how failures are reported -- nothing about what the SQL
 * says. Selected with `db_type = 'pdo'`.
 *
 * WHY THE BUILDER STILL INTERPOLATES
 * ----------------------------------
 * NOTE: query() DOES prepare and bind when it is handed parameters -- see the
 * $params path below, which is how inserts and constrained selects run. What
 * follows describes the builder, which is a separate thing and still
 * interpolates.
 *
 * The builder deliberately does not use placeholders throughout,
 * and that is not laziness. OWA's reporting layer composes its SQL
 * dynamically -- Db's builder assembles select lists, joins, group-bys and
 * constraints from a registry at runtime, and ResultSetManager drives it -- so
 * the statement text is not known until the moment it runs, and the values are
 * interpolated into it by the builder. Converting that to placeholders means
 * rewriting the builder, which is a much larger change than swapping a
 * transport and is not what this driver is for.
 *
 * THE QUOTING TRAP
 * ----------------
 * Because of the above, prepare() is an ESCAPER, not a statement preparer, and
 * the builder supplies the surrounding quotes itself:
 *
 *     sprintf( "%s = '%s'", $this->prepare( $name ), $this->prepare( $value ) )
 *
 * mysqli_real_escape_string() escapes without adding quotes. PDO::quote() adds
 * them -- quote("O'Brien") is "'O''Brien'". Returning that verbatim would
 * produce `col = ''O''Brien''` and break every query in the application, with
 * reporting hit hardest since that is where the dynamic construction lives. So
 * prepare() strips the outer pair that quote() adds. PdoDriverTest pins this;
 * it is the single easiest thing to get wrong here.
 */

abstract class Pdo extends \OWA\Core\Db
{

    /**
     * A connect attempt has already failed and must not be repeated.
     *
     * Same latch as the mysqli driver: without it every query re-dials a
     * database that is down, and each attempt costs a timeout.
     *
     * @var bool
     */
    protected $connect_failed = false;

    /** The statement most recently executed, for getAffectedRows(). */
    protected $last_statement;

    /**
     * The PDO DSN for this database, e.g. "mysql:host=...;dbname=...".
     *
     * The one thing a PDO subclass must supply. Everything else in this class is
     * transport and is the same whichever server is on the other end; the SQL
     * itself comes from whichever dialect the subclass uses.
     *
     * @return string
     */
    abstract protected function dsn();

    /**
     * The session sql_mode to apply on connect, or null to leave the server's.
     *
     * Supplied by the dialect trait a concrete driver uses, not by the transport
     * -- sql_mode is a MySQL concept and another database's dialect will answer
     * this differently, or not at all.
     *
     * @return string|null
     */
    abstract protected function sessionSqlMode();

    function connect() {

        if ( $this->connect_failed ) {

            return false;
        }

        if ( ! $this->connection ) {

            $dsn = $this->dsn();

            $options = array(
                // Failures surface as exceptions and are converted to the falsy
                // returns callers already handle -- see query(). Silent mode
                // would mean checking errorInfo() after every call and is easier
                // to get wrong.
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_PERSISTENT         => (bool) $this->getConnectionParam('persistant'),
                // Server-side prepares would gain nothing here (no placeholders
                // are used) and change quote()/error timing, so keep the driver
                // behaving like the transport it replaces.
                \PDO::ATTR_EMULATE_PREPARES   => true,
                // Columns come back as STRINGS, as mysqli returns them.
                //
                // Without this PDO returns native types on mysqlnd, so an
                // integer column arrives as int where it used to be '1'. That is
                // not cosmetic: it changes `===` comparisons throughout, and it
                // changes the REST API's JSON, where counts would start
                // serialising as numbers instead of quoted strings -- a public
                // contract change smuggled in by a transport swap. Caught by
                // DbDriverSqlParityTest::testBothDriversReturnTheSameRows.
                \PDO::ATTR_STRINGIFY_FETCHES  => true,
            );

            try {

                $this->connection = new \PDO(
                    $dsn,
                    $this->getConnectionParam('user'),
                    $this->getConnectionParam('password'),
                    $options
                );

            } catch ( \Throwable $e ) {

                // A FAILED CONNECT IS NOT A CONNECTION. Released so that
                // isConnectionEstablished() and the guard below both see the
                // truth, exactly as the mysqli driver does.
                $this->connection        = null;
                $this->connection_status = false;
                $this->connect_failed    = true;

                $this->e->alert( 'Could not connect to database.' );

                return false;
            }

            $mode = $this->sessionSqlMode();

            if ( $mode !== null ) {

                $this->query( sprintf( "SET SESSION sql_mode='%s'", $this->prepare( $mode ) ) );
            }
        }

        $this->connection_status = (bool) $this->connection;

        if ( ! $this->connection_status ) {

            $this->e->alert('Could not connect to database.');
        }

        return $this->connection_status;
    }

    /**
     * Run a statement.
     *
     * Returns a PDOStatement, or false on failure. Never throws: callers -- and
     * get_row()/get_results() below -- have always been written against a falsy
     * return, and a throw from here would take down a tracking request that
     * should degrade instead.
     *
     * @param string $sql
     * @return \PDOStatement|false
     */
    function query( $sql, array $params = array() ) {

        if ( $this->connection_status == false ) {

            \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

            $connected = $this->connect();

            \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

            if ( ! $connected ) {

                $this->new_result = false;

                return false;
            }
        }

        $this->e->debug( sprintf('Query: %s', $sql) );

        $this->result     = array();
        $this->new_result = false;

        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__, $sql);

        try {

            if ( $params ) {

                $statement = $this->connection->prepare( $sql );

                /*
                 * Placeholders and values must agree in number, and the check
                 * is here because the failure is otherwise silent and wrong
                 * rather than loud.
                 *
                 * PDO binds what it is given; MySQL then reports a type error
                 * against whichever column happens to line up with a misplaced
                 * value -- "Incorrect integer value: 'ludhiana' for column
                 * 'is_browser'" is what that looks like from the log, with
                 * nothing to say the values were offset rather than the data
                 * bad. Worse, an offset that lands a string in another string
                 * column raises nothing at all and writes the wrong value.
                 *
                 * Counting '?' outside quotes is enough: this driver only ever
                 * emits positional placeholders, and the SQL is assembled from
                 * column names and bindValue() returns rather than from user
                 * text.
                 */
                $expected = self::countPlaceholders( $sql );

                if ( $expected !== count( $params ) ) {

                    $this->logQueryError( sprintf(
                        'Refusing to execute: %d placeholders but %d bound values. '
                        . 'Binding these would put values in the wrong columns.',
                        $expected, count( $params ) ), $sql, false );

                    $this->new_result = false;

                    return false;
                }

                /*
                 * A running position, NOT the array key. Binding to the key
                 * plus one is correct only while the bindings array is
                 * contiguous and zero-based; a single unset() or filter
                 * anywhere upstream would shift every parameter after the gap,
                 * silently.
                 */
                $position = 0;

                foreach ( $params as $value ) {

                    $position++;

                    // Typed rather than everything-as-string: binding an int as
                    // an int is what lets strict mode reject a genuinely bad
                    // value instead of coercing it, and PARAM_NULL keeps a null
                    // a NULL rather than turning it into ''.
                    $statement->bindValue(
                        $position,
                        is_bool( $value ) ? (int) $value : $value,
                        self::paramType( $value )
                    );
                }

                $statement->execute();

            } else {

                $statement = $this->connection->query( $sql );
            }

        } catch ( \Throwable $e ) {

            // Not htmlspecialchars(): this goes to a log file, not to a page,
            // and escaping it only makes the message harder to read at the
            // moment someone is trying to read it.
            // SQLSTATE 23000 is the ANSI integrity-constraint class, so this
            // stays right on a driver that is not MySQL. The vendor code (1062
            // here) is not portable and is deliberately not used.
            $this->logQueryError(
                $e->getMessage(),
                $sql,
                (string) $e->getCode() === '23000'
            );

            $this->new_result = false;

            return false;
        }

        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

        $this->last_statement = $statement;

        // A statement with NO RESULT SET reports success as `true`, which is
        // what mysqli_query() returns and therefore what callers test for --
        // PartitionOperationsTest asserts exactly that on an ALTER. PDO hands
        // back a PDOStatement either way, which is truthy but is not true, so
        // an `=== true` or assertTrue() check would fail on DDL that worked.
        $this->new_result = $statement->columnCount() > 0 ? $statement : true;

        return $this->new_result;
    }

    function close() {

        // PDO closes on refcount drop; there is no explicit close. Clearing the
        // handle is the close, and it must also clear the latch so a later
        // connect() may try again -- an explicit close is a deliberate reset.
        $this->connection        = null;
        $this->last_statement    = null;
        $this->new_result        = false;
        $this->connection_status = false;
        $this->connect_failed    = false;
    }

    /**
     * Fetch result set array.
     *
     * Null, not an empty array, when there is nothing to return -- no rows, or a
     * query that failed. Matches the mysqli driver exactly, because callers test
     * `=== null` and returning an array would change what they see.
     *
     * @param string $sql
     * @return array|null
     */
    function get_results( $sql, array $params = array() ) {

        if ( $sql ) {

            $this->query( $sql, $params );
        }

        if ( ! $this->new_result instanceof \PDOStatement ) {

            return null;
        }

        $rows = $this->new_result->fetchAll( \PDO::FETCH_ASSOC );

        if ( ! $rows ) {

            return null;
        }

        $this->result = $rows;

        return $this->result;
    }

    /**
     * Fetch a single row, or null when there is none -- including when the query
     * failed, since a failed query has no row either.
     *
     * @param string $sql
     * @return array|null
     */
    function get_row( $sql, array $params = array() ) {

        $result = $this->query( $sql, $params );

        if ( ! $result instanceof \PDOStatement ) {

            return null;
        }

        $row = $result->fetch( \PDO::FETCH_ASSOC );

        return $row === false ? null : $row;
    }

    /**
     * Escape a value for interpolation into SQL -- WITHOUT surrounding quotes.
     *
     * The builder adds its own quotes (see the class docblock), so the outer
     * pair PDO::quote() contributes has to come back off. Anything else emits
     * `col = ''value''` and breaks every query in the application.
     *
     * @param string $string
     * @return string
     */
    function prepare( $string ) {

        if ( is_null( $string ) ) {

            return $string;
        }

        if ( $this->connection_status == false ) {

            $this->connect();
        }

        if ( ! $this->connection ) {

            // Nothing to escape against. Refusing is safer than returning the
            // raw value, which would be interpolated into SQL unescaped.
            return '';
        }

        $quoted = $this->connection->quote( (string) $string );

        if ( $quoted === false ) {

            return '';
        }

        // quote() guarantees the outer pair; strip exactly one from each end.
        return substr( $quoted, 1, -1 );
    }

    /**
     * The PDO type for a bound value.
     *
     * Booleans go as int, not PARAM_BOOL: OWA stores them in TINYINT(1) columns
     * and PARAM_BOOL round-trips as '' for false on some drivers, which lands as
     * 0 under a permissive sql_mode and fails outright under a strict one.
     */
    /**
     * How many positional placeholders a statement carries.
     *
     * String literals are skipped so a '?' inside quoted text is not counted.
     * This driver assembles SQL from column names and bindValue() returns, so
     * quoted text is rare -- but a miscount here would refuse a legitimate
     * query, which is worse than the problem being guarded against.
     */
    public static function countPlaceholders( $sql ) {

        $sql    = (string) $sql;
        $count  = 0;
        $quote  = '';
        $length = strlen( $sql );

        for ( $i = 0; $i < $length; $i++ ) {

            $char = $sql[ $i ];

            if ( $quote !== '' ) {

                /* Escaped quote inside a literal: skip the pair. */
                if ( $char === '\\' ) {

                    $i++;

                    continue;
                }

                if ( $char === $quote ) {

                    $quote = '';
                }

                continue;
            }

            if ( $char === "'" || $char === '"' ) {

                $quote = $char;

                continue;
            }

            if ( $char === '?' ) {

                $count++;
            }
        }

        return $count;
    }

    private static function paramType( $value ) {

        if ( is_null( $value ) ) {

            return \PDO::PARAM_NULL;
        }

        if ( is_int( $value ) || is_bool( $value ) ) {

            return \PDO::PARAM_INT;
        }

        return \PDO::PARAM_STR;
    }

    function getAffectedRows() {

        return $this->last_statement instanceof \PDOStatement
            ? $this->last_statement->rowCount()
            : 0;
    }
}
