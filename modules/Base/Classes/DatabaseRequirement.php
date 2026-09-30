<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Which database servers OWA 2.0 runs on: MySQL 8.0.21 or later, or MariaDB
 * 10.11 or later.
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

    const MYSQL_MIN   = '8.0.21';
    const MARIADB_MIN = '10.11.0';

    /**
     * Why this server will not do, or null. Asks the connected server.
     *
     * @return string|null
     */
    public static function problem() {

        if ( self::$assumed !== null ) {

            return self::problemFor( self::$assumed );
        }

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( 'SELECT VERSION() AS version' );

        return self::problemFor( is_array( $row ) ? (string) ( $row['version'] ?? '' ) : '' );
    }

    /** @var string|null a VERSION() string to answer with instead of the server's */
    protected static $assumed = null;

    /**
     * Test seam: answer as if the server reported this version; null to ask it
     * again. What lets a test show each enforcement point refusing without an
     * old server to run against.
     *
     * @param string|null $version
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

            return sprintf( 'OWA could not read the database server\'s version (%s). It needs MySQL %s '
              . 'or later, or MariaDB %s or later.',
                $version !== '' ? '"' . $version . '"' : 'nothing was returned',
                self::MYSQL_MIN, self::shortVersion( self::MARIADB_MIN ) );
        }

        $number = $m[1] . '.' . $m[2] . '.' . $m[3];

        if ( stripos( $version, 'mariadb' ) !== false ) {

            return version_compare( $number, self::MARIADB_MIN, '>=' ) ? null : sprintf(
                'This database server is MariaDB %s. OWA needs MariaDB %s or later -- the oldest '
              . 'MariaDB release still supported -- or MySQL %s or later.',
                $number, self::shortVersion( self::MARIADB_MIN ), self::MYSQL_MIN );
        }

        return version_compare( $number, self::MYSQL_MIN, '>=' ) ? null : sprintf(
            'This database server is MySQL %s. OWA needs MySQL %s or later -- its reporting build '
          . 'uses JSON_VALUE and window functions -- or MariaDB %s or later.',
            $number, self::MYSQL_MIN, self::shortVersion( self::MARIADB_MIN ) );
    }

    /** "10.11.0" as "10.11". */
    protected static function shortVersion( $version ) {

        return preg_replace( '/\.0$/', '', (string) $version );
    }
}

?>
