<?php
namespace OWA\Core\Db;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

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
 * plus `'owa_db_pdo_pgsql' => PdoPgsql::class` in owa_compat_aliases.php, and an
 * install writes `define( 'OWA_DB_TYPE', 'pdo_pgsql' );`.
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
