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
     * The floors are this release's, or another's (a release check judges the
     * release it would install against this server).
     *
     * @param string      $version
     * @param string|null $mysql_min   null: Core\Requirements::MYSQL_MIN
     * @param string|null $mariadb_min null: Core\Requirements::MARIADB_MIN
     * @return string|null
     */
    public static function problemFor( $version, $mysql_min = null, $mariadb_min = null ) {

        $version     = trim( (string) $version );
        $mysql_min   = $mysql_min ?? \OWA\Core\Requirements::MYSQL_MIN;
        $mariadb_min = $mariadb_min ?? \OWA\Core\Requirements::MARIADB_MIN;

        if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $version, $m ) ) {

            return sprintf( \OWA\Core\CoreAPI::t( 'OWA could not read the database server\'s version (%s). It needs MySQL %s '
              . 'or later, or MariaDB %s or later.' ),
                $version !== '' ? '"' . $version . '"' : \OWA\Core\CoreAPI::t( 'nothing was returned' ),
                $mysql_min, self::shortVersion( $mariadb_min ) );
        }

        $number = $m[1] . '.' . $m[2] . '.' . $m[3];

        if ( stripos( $version, 'mariadb' ) !== false ) {

            return version_compare( $number, $mariadb_min, '>=' ) ? null : sprintf(
                \OWA\Core\CoreAPI::t( 'This database server is MariaDB %s. OWA needs MariaDB %s or later -- the oldest '
              . 'MariaDB release still supported -- or MySQL %s or later.' ),
                $number, self::shortVersion( $mariadb_min ), $mysql_min );
        }

        return version_compare( $number, $mysql_min, '>=' ) ? null : sprintf(
            \OWA\Core\CoreAPI::t( 'This database server is MySQL %s. OWA needs MySQL %s or later -- its reporting build '
          . 'uses JSON_VALUE and window functions -- or MariaDB %s or later.' ),
            $number, $mysql_min, self::shortVersion( $mariadb_min ) );
    }

    /** @var bool|null whether named time zones convert, to answer with instead of the server's */
    protected static $assumed_zones = null;

    /**
     * Test seam: answer timeZoneWarning() as if the server did (true) or did
     * not (false) convert named time zones; null to ask it again.
     *
     * @param  bool|null $convert
     * @return void
     */
    public static function assumeTimeZones( $convert ) {

        self::$assumed_zones = $convert === null ? null : (bool) $convert;
    }

    /**
     * Why the clock dimensions report (not set) on this server, or null.
     * Asks the connected server (Db::namedTimeZonesConvert()).
     *
     * A warning, not a refusal: every other report works without the time
     * zone tables.
     *
     * @return string|null
     */
    public static function timeZoneWarning() {

        $convert = self::$assumed_zones ?? \OWA\Core\CoreAPI::dbSingleton()->namedTimeZonesConvert();

        return $convert ? null : self::timeZoneWarningText();
    }

    /**
     * The warning and its fix.
     *
     * @return string
     */
    public static function timeZoneWarningText() {

        return \OWA\Core\CoreAPI::t( 'The database server\'s time zone tables are not loaded, so hour, minute and '
          . 'date-hour report as (not set). Load them on the database server with: '
          . 'mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql. On a managed database, ask your hosting '
          . 'provider to load them.' );
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
