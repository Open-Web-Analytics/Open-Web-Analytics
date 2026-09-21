<?php

namespace OWA\Module\Base\Update;

/**
 * Add the referrer's query string to owa_event_raw.
 *
 * The evidence a search term is read from. Parsed at ingest beside
 * referer_host by the same parseUrl() call, which already returns it.
 *
 * It is a raw column rather than a cube one because it is an OBSERVATION --
 * what arrived -- while the term pulled out of it is a reading, and readings
 * live where a rebuild can re-apply them. Pulling a named parameter out and
 * percent-decoding it is PHP, which is what the cube's compute steps exist
 * for; without this column that reading had nowhere to go but ingest, the one
 * layer a correction never reaches.
 *
 * Both tables: the cube is raw's columns verbatim plus the derived ones, so a
 * column that reaches only raw leaves the two disagreeing and every later build
 * fails on "Unknown column in field list". The trait does each the way it needs
 * -- instant on raw, a rebuild on the cube.
 */
class Update038 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 38;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return $this->addRawColumn( 'referer_query' );
    }

    function down() {

        foreach ( array( 'base.event', 'base.event_raw' ) as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $this->dropColumnIfPresent( $entity, 'referer_query' ) === false ) {

                $this->e->notice( sprintf(
                    'Dropping %s.referer_query failed', $entity->getTableName() ) );

                return false;
            }
        }

        return $this->clearCubeInstantColumns();
    }
}

?>
