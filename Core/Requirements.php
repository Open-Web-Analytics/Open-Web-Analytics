<?php
namespace OWA\Core;

/**
 * What this version of OWA needs of the server it runs on: the PHP floor, the
 * PHP extensions, the database servers, and the rules owa-config.php is held
 * to. The one place each is written.
 *
 * Read by the installer's and System Health's environment checks
 * (Classes\EnvironmentCheck), the database version check
 * (Classes\DatabaseRequirement), the configuration checks (ServerRole), the
 * wiki's generated tables, and release.json, which a release check reads to
 * judge a newer release against this server before installing it (toArray()).
 *
 * DATA ONLY. Constants and a pure toArray(): no settings, no database, no
 * CoreAPI, so it answers before OWA boots and in a checkout with no
 * owa-config.php. Messages stay plain English literals (translated where
 * they are shown, Strings::VALUE_TABLES).
 *
 * The PHP floor is composer.json's require.php too, which a test holds equal
 * to PHP_MIN. composer.json does not ship in a release tarball, so this is
 * the copy an install reads.
 *
 * @phpstan-type RequirementsArray array{php: array{min: string, int_size: int, extensions: list<string>, database_drivers: list<string>}, database: array{mysql: string, mariadb: string}, config: array{database_constants: list<string>, url_constants: list<string>, secret_constants: list<string>, secret_min_length: int, retired_constants: list<string>}}
 */
class Requirements {

    /** The oldest PHP OWA runs on. composer.json's require.php says the same. */
    const PHP_MIN = '8.2';

    /** PHP_INT_SIZE OWA needs: a 64-bit build. Ids are 64-bit integers. */
    const PHP_INT_SIZE = 8;

    /**
     * The PHP extensions OWA needs beyond a database driver, and what each is
     * for. The installer and System Health check each.
     */
    const PHP_EXTENSIONS = [
        'json'     => 'Encoding stored report definitions and API responses.',
        'mbstring' => 'Handling non-ASCII page titles, URLs and search terms.',
        'pcre'     => 'Matching URLs, goal conditions and constraint values.',
        'zlib'     => 'Compressing and reading session recordings.',
    ];

    /**
     * Either of these reaches the database: pdo_mysql, or mysqli built with
     * mysqlnd (EnvironmentCheck::databaseDriver()).
     */
    const DATABASE_DRIVERS = [ 'pdo_mysql', 'mysqli+mysqlnd' ];

    /** The oldest MySQL: the reporting build uses JSON_VALUE and window functions. */
    const MYSQL_MIN = '8.0.21';

    /** The oldest MariaDB release still supported by MariaDB. */
    const MARIADB_MIN = '10.11.0';

    /** What a server role with a database needs in owa-config.php. */
    const DATABASE_CONSTANTS = [
        'OWA_DB_NAME', 'OWA_DB_HOST', 'OWA_DB_USER', 'OWA_DB_PASSWORD',
        'OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT',
    ];

    /**
     * What a role with a database also needs, not empty: absolute links,
     * mail, the published tracking bundles and signed REST requests are built
     * from it. A collector builds none of them.
     */
    const URL_CONSTANTS = [ 'OWA_PUBLIC_URL' ];

    /**
     * The keys nonces, overlay tokens, session cookies and application
     * passwords are signed with. Each must be a random string of at least
     * SECRET_MIN_LENGTH characters: not empty, and not owa-config-dist.php's
     * example value.
     */
    const SECRET_CONSTANTS = [ 'OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT' ];

    /** The shortest secret accepted. The installer writes 64 characters. */
    const SECRET_MIN_LENGTH = 32;

    /** owa-config-dist.php's example values of SECRET_CONSTANTS. */
    const SECRET_PLACEHOLDERS = [ 'yournoncekeygoeshere', 'yournoncesaltgoeshere', 'yourauthkeygoeshere', 'yourauthsaltgoeshere' ];

    /**
     * Retired constants, and what replaces them. Each notice says whether
     * the 1.x name is still read (Settings::applyConfigConstants()).
     */
    const RETIRED_CONSTANTS = [
        'OWA_QUEUE_EVENTS'              => 'The 1.x name of OWA_QUEUE_TRACKING_EVENTS, still read while that is not set. Rename it.',
        'OWA_USE_STATIC_CONFIG_ONLY'    => 'The 1.x switch, still read: stored settings are not read at boot. Set OWA_SERVER_ROLE instead, collector for a server that only receives beacons.',
        'OWA_QUEUE_TRACKER_INGEST'      => 'No longer read. Use OWA_QUEUE_TRACKING_EVENTS.',
        'OWA_TRACKER_INGEST_QUEUE_TYPE' => 'No longer read. The queue backend follows from the queue module\'s constant, such as OWA_SQS_QUEUE_URL.',
        'OWA_TRACKER_INGEST_DRAIN'      => 'No longer read. The servers whose role runs the scheduler drain the queue.',
        'OWA_SCHEDULER_ENABLED'         => 'No longer read. The role decides: full and worker run the scheduler.',
        'OWA_ACTIVE_MODULES'            => 'No longer read. A module with a constant of its own is active while it is set, such as OWA_SQS_QUEUE_URL.',
        'OWA_ERROR_HANDLER'             => 'No longer read. Set OWA_DEBUG to true for debug logging.',
        'OWA_LOG_PHP_ERRORS'            => 'No longer read. OWA_DEBUG also sends PHP\'s own errors to OWA\'s error log.',
    ];

    /**
     * Everything above as data: release.json's requirements. Names and
     * floors only; what each extension is for and what replaces a retired
     * constant are words for the screens, not facts to check.
     *
     * @return RequirementsArray
     */
    public static function toArray() {

        return [
            'php' => [
                'min'              => self::PHP_MIN,
                'int_size'         => self::PHP_INT_SIZE,
                'extensions'       => array_keys( self::PHP_EXTENSIONS ),
                'database_drivers' => self::DATABASE_DRIVERS,
            ],
            'database' => [
                'mysql'   => self::MYSQL_MIN,
                'mariadb' => self::MARIADB_MIN,
            ],
            'config' => [
                'database_constants' => self::DATABASE_CONSTANTS,
                'url_constants'      => self::URL_CONSTANTS,
                'secret_constants'   => self::SECRET_CONSTANTS,
                'secret_min_length'  => self::SECRET_MIN_LENGTH,
                'retired_constants'  => array_keys( self::RETIRED_CONSTANTS ),
            ],
        ];
    }
}
