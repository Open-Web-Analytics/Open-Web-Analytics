<?php
namespace OWA\Module\Base\Controller;

use OWA\Core\CoreAPI;
use OWA\Module\Base\Classes\PropertyArchive;
use OWA\Module\Base\Classes\SiteManager;

/**
 * cmd=property: a Property's state, and archiving or restoring it.
 *
 *   cli.php cmd=property id=<property id> --status
 *   cli.php cmd=property domain=<domain> --archive
 *   cli.php cmd=property id=<property id> --restore
 *
 * --archive is the Property screen's delete (Classes\PropertyArchive::archive()),
 * --restore its inverse (PropertyArchive::restore()). Each says what it
 * changed, and a second run changes nothing.
 *
 * domain= is compared by host (Spam\Hosts::of()). When it matches more than
 * one Property, live or archived, each one's status is printed and nothing
 * is done: id= says which. An app Property has no domain, and is named by id=.
 *
 * No capability is asked for: cli.php runs as the administrator.
 *
 * Runs while updates are pending, so the duplicates Update103 refuses can be
 * archived.
 */
class PropertyCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Shows a Property\'s state, or archives or restores it. Archiving archives its Profiles and removes their tracking bundles, as the Property screen\'s delete does; restoring brings back the Profiles archived with it. Name the Property by id= or by domain=, and give one of --status, --archive or --restore.',
            'arguments'   => [
                'id' => [
                    'type'        => 'property',
                    'value'       => '<property id>',
                    'description' => 'The Property. Required for an app Property, which has no domain.',
                ],
                'domain' => [
                    'type'        => 'string',
                    'value'       => '<domain>',
                    'description' => 'The Property with this domain, compared as OWA compares hosts: case, a scheme, a port and a leading www. are ignored. When more than one Property has it, each is shown and nothing is done.',
                ],
                '--status' => [
                    'type'        => 'flag',
                    'description' => 'Print the Property: its name, whether it is live or archived, its Profiles and its cube. Changes nothing.',
                ],
                '--archive' => [
                    'type'        => 'flag',
                    'description' => 'Archive the Property and its live Profiles, and remove the Profiles\' tracking bundles.',
                ],
                '--restore' => [
                    'type'        => 'flag',
                    'description' => 'Restore an archived Property and the Profiles archived with it, and publish their tracking bundles. Refused while another live Property has its domain.',
                ],
            ],
        ];
    }

    /** The action flags, of which exactly one is given. */
    const ACTIONS = [ 'status', 'archive', 'restore' ];

    /** Update103 refuses live duplicates, which this archives. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $problems = [];

        if ( $this->param( 'id' ) === null && $this->param( 'domain' ) === null ) {

            $problems['id'] = CoreAPI::t( 'Give id=<property id> or domain=<domain>.' );

        } elseif ( $this->param( 'id' ) !== null && $this->param( 'domain' ) !== null ) {

            $problems['id'] = CoreAPI::t( 'Give id= or domain=, not both.' );
        }

        $actions = array_values( array_filter( self::ACTIONS, fn ( $a ) => $this->param( $a ) === true ) );

        if ( count( $actions ) !== 1 ) {

            $problems['action'] = CoreAPI::t( 'Give one of --status, --archive or --restore.' );
        }

        if ( $problems ) {

            return $this->usageError( self::usageMessage( 'property', $problems ) );
        }

        $schema = (int) CoreAPI::getSetting( 'base', 'schema_version' );

        if ( $schema < V1DropCli::MIGRATED_SCHEMA ) {

            return $this->refuse( sprintf( CoreAPI::t( 'This installation\'s tables are still 1.x (schema %d). Run cli.php cmd=core-update-schema first.' ), $schema ) );
        }

        $id = $this->resolve();

        if ( $id === null ) {

            return null;
        }

        switch ( $actions[0] ) {

            case 'archive':

                return $this->archive( $id );

            case 'restore':

                return $this->restore( $id );
        }

        $this->write( $this->statusLines( $id ) );

        return null;
    }

    /**
     * The Property id= or domain= names; null when the command line names
     * none, or more than one, which has been reported.
     *
     * @return string|null
     */
    protected function resolve() {

        if ( $this->param( 'id' ) !== null ) {

            return (string) $this->param( 'id' );
        }

        $domain = (string) $this->param( 'domain' );
        $rows   = SiteManager::propertiesWithHost( $domain );

        if ( ! $rows ) {

            $this->usageError( self::usageMessage( 'property', [
                'domain' => sprintf( CoreAPI::t( 'There is no Property with the domain %s.' ), $domain ) ] ) );

            return null;
        }

        if ( count( $rows ) > 1 ) {

            $lines = [ sprintf( CoreAPI::t( '%1$d Properties have the domain %2$s:' ), count( $rows ), $domain ) ];

            foreach ( $rows as $row ) {

                $lines[] = '';
                $lines   = array_merge( $lines, $this->statusLines( (string) $row['id'] ) );
            }

            $lines[] = '';

            $this->write( $lines );
            $this->usageError( CoreAPI::t( 'Run the command again with id=<property id>.' ) );

            return null;
        }

        return (string) $rows[0]['id'];
    }

    /**
     * @param  string $id
     * @return null
     */
    protected function archive( $id ) {

        $done = PropertyArchive::archive( $id );

        if ( ! $done['found'] ) {

            return $this->fail( sprintf( CoreAPI::t( 'There is no Property %s.' ), $id ), true );
        }

        if ( ! $done['property'] && ! $done['profiles'] ) {

            CoreAPI::notice( sprintf( CoreAPI::t( 'Property %1$s was archived already, on %2$s. Nothing changed.' ),
                $id, self::time( $done['archived_date'] ) ) );

            return null;
        }

        CoreAPI::notice( $done['property']
            ? sprintf( CoreAPI::t( 'Property %s archived.' ), $id )
            : sprintf( CoreAPI::t( 'Property %s was archived already.' ), $id ) );

        CoreAPI::notice( self::profilesLine( CoreAPI::t( 'Profiles archived, tracking bundles removed: %s.' ), $done['profiles'] ) );

        return null;
    }

    /**
     * @param  string $id
     * @return null
     */
    protected function restore( $id ) {

        $done = PropertyArchive::restore( $id );

        if ( ! $done['found'] ) {

            return $this->fail( sprintf( CoreAPI::t( 'There is no Property %s.' ), $id ), true );
        }

        if ( $done['refused'] !== '' ) {

            return $this->fail( sprintf( CoreAPI::t( 'Property %1$s was not restored. %2$s' ), $id, $done['refused'] ), true );
        }

        if ( ! $done['property'] ) {

            CoreAPI::notice( sprintf( CoreAPI::t( 'Property %s is live. Nothing changed.' ), $id ) );

            return null;
        }

        CoreAPI::notice( sprintf( CoreAPI::t( 'Property %s restored.' ), $id ) );
        CoreAPI::notice( self::profilesLine( CoreAPI::t( 'Profiles restored, tracking bundles published: %s.' ), $done['profiles'] ) );

        return null;
    }

    /**
     * A Property as --status prints it.
     *
     * @param  string $id
     * @return list<string>
     */
    protected function statusLines( $id ) {

        $property = CoreAPI::entityFactory( 'base.property' );
        $property->load( $id );

        $lines = [ sprintf( CoreAPI::t( 'Property %1$s: %2$s' ), $id, (string) $property->get( 'name' ) ) ];

        $lines[] = '  ' . sprintf( CoreAPI::t( 'Domain: %s' ), $property->isWebProperty()
            ? (string) $property->get( 'domain' ) : CoreAPI::t( 'none, an app' ) );

        $lines[] = '  ' . sprintf( CoreAPI::t( 'State: %s' ), self::state( $property->get( 'archived_date' ) ) );

        $profiles = PropertyArchive::profiles( $id );

        $lines[] = '  ' . sprintf( CoreAPI::t( 'Profiles: %d' ), count( $profiles ) );

        foreach ( $profiles as $profile ) {

            $lines[] = sprintf( '    %s  %s  %s  %s', (string) $profile->get( 'site_id' ), (string) $profile->get( 'name' ),
                $profile->getStreamType(), self::state( $profile->get( 'archived_date' ) ) );
        }

        $lines[] = '  ' . sprintf( CoreAPI::t( 'Cube: %s' ), self::cube( $id ) );

        return $lines;
    }

    /**
     * The cube's table, row count and newest day.
     *
     * @param  string $id
     * @return string
     */
    protected static function cube( $id ) {

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $id );
        $db    = CoreAPI::dbSingleton();

        if ( $table === '' || ! $db->tableExists( $table ) ) {

            return CoreAPI::t( 'none yet' );
        }

        $row = (array) $db->get_row( sprintf( 'SELECT COUNT(*) AS n, MAX(yyyymmdd) AS d FROM %s', $table ) );

        return sprintf( CoreAPI::t( '%1$s, %2$d rows, newest day %3$s' ), $table, (int) ( $row['n'] ?? 0 ),
            ! empty( $row['d'] ) ? \OWA\Module\Base\Classes\Cube\Status::day( $row['d'] ) : CoreAPI::t( 'none' ) );
    }

    /**
     * live, or archived with its date.
     *
     * @param  mixed $archived_date
     * @return string
     */
    protected static function state( $archived_date ) {

        return $archived_date
            ? sprintf( CoreAPI::t( 'archived %s' ), self::time( $archived_date ) )
            : CoreAPI::t( 'live' );
    }

    /**
     * @param  mixed $timestamp
     * @return string
     */
    protected static function time( $timestamp ) {

        return date( 'Y-m-d H:i', (int) $timestamp );
    }

    /**
     * @param  string       $format  one %s: the site_ids, or none
     * @param  list<string> $site_ids
     * @return string
     */
    protected static function profilesLine( $format, array $site_ids ) {

        return sprintf( $format, $site_ids ? implode( ', ', $site_ids ) : CoreAPI::t( 'none' ) );
    }
}
