<?php
namespace OWA\Module\Base\Classes;

/**
 * Whether the database server is one OWA runs on: MySQL or MariaDB at or above
 * Core\Requirements::MYSQL_MIN and MARIADB_MIN (8.0.21 and 10.11 as written).
 *
 * The floors are what the code uses, not a guess. MySQL: window functions (the
 * cube build's session values, 8.0.2), JSON_VALUE (custom dimensions in the
 * build, 8.0.21), CAST AS DOUBLE (8.0.17). MariaDB: the same features arrived
 * earlier, and 10.11 is its oldest long-term release still supported (to
 * February 2028). The typed JSON reads avoid RETURNING, which MariaDB lacks
 * (Core\Db\MysqlDialect). CI runs the database-backed suites and the e2e on
 * both.
 *
 * Asked where tables would be created or changed -- the install wizard's
 * database step, InstallManager::installSchema() and the Base update -- so an
 * unsupported server is refused before anything is written, rather than
 * failing partway through a build.
 */
class DatabaseRequirement {

    /**
     * Why this server will not do, or null. Asks the connected server.
     *
     * @return string|null
     */
    public static function problem() {

        if ( self::$assumed !== null ) {

            return self::problemFor( self::$assumed );
        }

        return self::problemFor( \OWA\Core\CoreAPI::dbSingleton()->serverVersion() );
    }

    /** @var string|null a VERSION() string to answer with instead of the server's */
    protected static $assumed = null;

    /**
     * Test seam: answer as if the server reported this version; null to ask it
     * again. What lets a test show each enforcement point refusing without an
     * old server to run against.
     *
     * @param  string|null $version
     * @return void
     */
    public static function assume( $version ) {

        self::$assumed = $version === null ? null : (string) $version;
    }

    /**
     * problem()'s answer from a VERSION() string. Pure, so every server a
     * version string can describe is assertable.
     *
     * MariaDB reports itself in the string ("10.11.6-MariaDB-1:10.11.6+maria~ubu2204");
     * MySQL and its compatible builds -- Percona, Aurora MySQL 3 -- report a
     * plain MySQL version ("8.0.35-27", "8.4.10").
     *
     * @param string $version
     * @return string|null
     */
    public static function problemFor( $version ) {

        $version = trim( (string) $version );

        if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $version, $m ) ) {

            return sprintf( \OWA\Core\CoreAPI::t( 'OWA could not read the database server\'s version (%s). It needs MySQL %s '
              . 'or later, or MariaDB %s or later.' ),
                $version !== '' ? '"' . $version . '"' : \OWA\Core\CoreAPI::t( 'nothing was returned' ),
                \OWA\Core\Requirements::MYSQL_MIN, self::shortVersion( \OWA\Core\Requirements::MARIADB_MIN ) );
        }

        $number = $m[1] . '.' . $m[2] . '.' . $m[3];

        if ( stripos( $version, 'mariadb' ) !== false ) {

            return version_compare( $number, \OWA\Core\Requirements::MARIADB_MIN, '>=' ) ? null : sprintf(
                \OWA\Core\CoreAPI::t( 'This database server is MariaDB %s. OWA needs MariaDB %s or later -- the oldest '
              . 'MariaDB release still supported -- or MySQL %s or later.' ),
                $number, self::shortVersion( \OWA\Core\Requirements::MARIADB_MIN ), \OWA\Core\Requirements::MYSQL_MIN );
        }

        return version_compare( $number, \OWA\Core\Requirements::MYSQL_MIN, '>=' ) ? null : sprintf(
            \OWA\Core\CoreAPI::t( 'This database server is MySQL %s. OWA needs MySQL %s or later -- its reporting build '
          . 'uses JSON_VALUE and window functions -- or MariaDB %s or later.' ),
            $number, \OWA\Core\Requirements::MYSQL_MIN, self::shortVersion( \OWA\Core\Requirements::MARIADB_MIN ) );
    }

    /**
     * "10.11.0" as "10.11".
     *
     * @param  string $version
     * @return string
     */
    protected static function shortVersion( $version ) {

        return preg_replace( '/\.0$/', '', (string) $version );
    }
}
