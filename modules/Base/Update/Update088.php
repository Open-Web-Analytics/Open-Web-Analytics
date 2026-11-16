<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Profile and Property domains as host[:port]: the scheme and trailing slashes
 * 1.x stored are removed from owa_site.domain and owa_property.domain.
 *
 * 1.x stored a site's domain as the wizard built it, `https://www.example.com`.
 * Every save now stores SiteManager::domainOnly() of what was typed, and this
 * applies the same function to the rows saved before. A row it returns
 * unchanged is not written, so a second run changes nothing.
 *
 * down() changes nothing: the code before this update reads a domain without a
 * scheme as well.
 *
 * RAW SQL, so a test can point the update at scratch tables.
 *
 * NOT CLI-ONLY. One row per Profile and per Property.
 */
class Update088 extends \OWA\Core\Update {

    var $schema_version = 88;

    var $is_cli_mode_required = false;

    var $down_does_not_restore = 'the scheme 1.x stored on each Profile and Property domain.';

    /** The entities whose domain column this rewrites, by key. */
    const ENTITIES = array( 'site' => 'base.site', 'property' => 'base.property' );

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $count = 0;

        foreach ( array_keys( self::ENTITIES ) as $key ) {

            $rows = (array) $db->get_results( sprintf(
                "SELECT id, domain FROM %s WHERE domain IS NOT NULL AND domain != ''", $this->tableFor( self::ENTITIES[ $key ] ) ) );

            foreach ( $rows as $row ) {

                $row  = (array) $row;
                $from = (string) $row['domain'];
                $to   = \OWA\Module\Base\Classes\SiteManager::domainOnly( $from );

                if ( $to === $from ) {

                    continue;
                }

                if ( $db->query( sprintf( 'UPDATE %s SET domain = ? WHERE id = ?', $this->tableFor( self::ENTITIES[ $key ] ) ),
                        array( $to, (string) $row['id'] ) ) === false ) {

                    $this->e->notice( sprintf( 'Rewriting the domain of %s %s failed.', $key, $row['id'] ) );

                    return false;
                }

                $count++;
            }
        }

        if ( $count ) {

            $this->e->notice( sprintf( 'Domains: %d %s stored without a scheme.', $count,
                \OWA\Core\Lib::plural( $count, 'row', 'rows' ) ) );
        }

        return true;
    }

    function down() {

        return true;
    }
}

?>
