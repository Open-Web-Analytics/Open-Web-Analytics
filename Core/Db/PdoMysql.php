<?php
namespace OWA\Core\Db;

/**
 * MySQL over PDO: the PDO transport plus the MySQL dialect.
 *
 * Selected with `db_type = 'pdo_mysql'`, and used automatically for
 * `db_type = 'mysql'` wherever the pdo_mysql extension is present -- see
 * CoreAPI::resolveDbDriver(). Existing installs therefore get it without editing
 * owa-config.php, and a host that only has mysqli keeps working.
 *
 * ADDING ANOTHER DATABASE
 * -----------------------
 * A driver is three things: this transport, a dialect, and a DSN. So Postgres
 * support is a sibling of this file, not a fork of it:
 *
 *     class PdoPgsql extends Pdo
 *     {
 *         use PgsqlDialect;                    // DDL vocabulary + introspection
 *
 *         protected function dsn() {
 *             return sprintf( 'pgsql:host=%s;port=%s;dbname=%s', ... );
 *         }
 *     }
 *
 * in Core/Db/PdoPgsql.php -- the token pdo_pgsql names that class by
 * convention -- and an install writes `define( 'OWA_DB_TYPE', 'pdo_pgsql' );`.
 *
 * The work is the dialect, not the plumbing: MysqlDialect carries the
 * OWA_DTD and OWA_SQL constants OWA compiles its DDL from, and the
 * information_schema queries behind partition and index introspection. A
 * Postgres dialect must answer the same questions its own way -- and
 * supportsPartitioning() exists precisely so a dialect can decline.
 */

class PdoMysql extends Pdo
{
    use MysqlDialect;

    /**
     * The rows streamed from the server, with MySQL's buffered-query mode off
     * for the one statement: see Db::get_unbuffered_iterator().
     *
     * @param  string $sql
     * @return \Generator<int, array<string, string|null>, mixed, void>
     */
    public function get_unbuffered_iterator( $sql ) {

        if ( ! $this->connection_status && ! $this->connect() ) {

            return;
        }

        $this->e->debug( sprintf( 'Query (unbuffered): %s', $sql ) );
        $this->num_queries++;

        $statement = null;
        $this->connection->setAttribute( \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false );

        try {

            $statement = $this->connection->query( $sql );

            while ( $row = $statement->fetch( \PDO::FETCH_ASSOC ) ) {

                yield $row;
            }

        } catch ( \Throwable $e ) {

            $this->logQueryError( $e->getMessage(), $sql );

        } finally {

            if ( $statement instanceof \PDOStatement ) {

                $statement->closeCursor();
            }

            $this->connection->setAttribute( \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true );
        }
    }

    protected function dsn() {

        // charset in the DSN, not a later SET NAMES: PDO uses it for quote() as
        // well as for the connection, so setting it afterwards leaves escaping
        // working against the wrong charset.
        // The CONNECTION encoding has to match the schema's, and it is the
        // binding constraint of the two: a four-byte character is mangled in
        // transit by a three-byte connection no matter how the column is
        // declared. Both now come from the same constant.
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->getConnectionParam('host'),
            $this->getConnectionParam('port') ?: 3306,
            $this->getConnectionParam('name'),
            $this->connectionCharacterEncoding()
        );
    }
}
