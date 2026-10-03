<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A database OWA creates for an install, named for the install's
 * Organization: owa_org_<organization id>.
 *
 * Named so a database belongs to one Organization from the start, which is
 * what a database per Organization later needs. The id is minted when the
 * installer offers to create the database, before the Organization exists;
 * the Organization takes it from the database's name when install creates it
 * (SiteManager::ensureOrganization()), so nothing else has to carry it.
 */
final class InstallDatabase {

    /** The name's prefix; the rest is the Organization's id. */
    const PREFIX = 'owa_org_';

    /** A new Organization id: random, unlike the fixed one an existing database's install derives. */
    public static function mintOrganizationId() {

        return (string) random_int( 1, PHP_INT_MAX );
    }

    /** @return string */
    public static function nameFor( $organization_id ) {

        return self::PREFIX . (string) $organization_id;
    }

    /**
     * The Organization id a database's name carries, or null when it is not
     * one OWA named.
     *
     * @param string $name
     * @return string|null
     */
    public static function organizationIdFrom( $name ) {

        return preg_match( '/^' . self::PREFIX . '([1-9]\d{0,18})$/', (string) $name, $m ) ? $m[1] : null;
    }

    /**
     * Create the database, refusing one that exists: an install that chose to
     * create a database never writes into somebody else's.
     *
     * @param string $name
     * @return array ok (bool), error (string|null)
     */
    public static function create( $name ) {

        if ( self::organizationIdFrom( $name ) === null ) {

            return array( 'ok' => false, 'error' => sprintf( '"%s" is not a name OWA gives a database.', $name ) );
        }

        $server = self::server();

        if ( ! $server ) {

            return array( 'ok' => false, 'error' => 'Could not connect to the database server with these details.' );
        }

        if ( $server->databaseExists( $name ) ) {

            return array( 'ok' => false, 'error' => sprintf(
                'A database named %s already exists on this server. Choose "Use an existing database" to install into it.',
                $name ) );
        }

        if ( ! $server->createDatabase( $name ) ) {

            return array( 'ok' => false, 'error' => sprintf(
                'The server refused to create the database: %s. Either give this user permission to create databases, '
              . 'or have an administrator run CREATE DATABASE `%s` CHARACTER SET utf8mb4; GRANT ALL ON `%s`.* TO '
              . '<this user>; and choose "Use an existing database".',
                $server->lastQueryError() ?: 'permission denied', $name, $name ) );
        }

        return array( 'ok' => true, 'error' => null );
    }

    /** Drop a database this install created and could not use. */
    public static function dropCreated( $name ) {

        $server = self::organizationIdFrom( $name ) !== null ? self::server() : null;

        return $server ? $server->dropDatabase( $name ) : false;
    }

    /** "MariaDB 10.11.6" or "MySQL 8.4.3": the server install connected to. */
    public static function serverDescription() {

        $row     = \OWA\Core\CoreAPI::dbSingleton()->get_row( 'SELECT VERSION() AS v' );
        $version = (string) ( $row['v'] ?? '' );

        if ( $version === '' ) {

            return null;
        }

        return stripos( $version, 'mariadb' ) !== false
            ? 'MariaDB ' . preg_replace( '/-.*$/', '', $version )
            : 'MySQL ' . preg_replace( '/-.*$/', '', $version );
    }

    /**
     * A connection to the server with no database selected: the settings'
     * credentials, without their database name.
     *
     * @return \OWA\Core\Db|null
     */
    private static function server() {

        $was = \OWA\Core\CoreAPI::getSetting( 'base', 'db_name' );

        try {

            \OWA\Core\CoreAPI::setSetting( 'base', 'db_name', '' );
            $db = \OWA\Core\CoreAPI::dbFactory();

        } finally {

            \OWA\Core\CoreAPI::setSetting( 'base', 'db_name', $was );
        }

        if ( ! $db ) {

            return null;
        }

        $db->connect();

        return $db->connection_status ? $db : null;
    }
}

?>
