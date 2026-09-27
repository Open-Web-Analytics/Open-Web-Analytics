<?php

namespace OWA\Module\Base\Update;

/**
 * file_name and file_extension become columns. The rest of the params stay put.
 *
 * WHY THESE TWO AND NOT THE OTHER NINE. Eleven first-class properties had a
 * `param` destination rather than a `column`, all of them set by OWA rather than
 * by the site. In v2 a params key is UNREPORTABLE until someone registers it as a
 * custom dimension -- Classes\Cube\Dimensions is the whole path from collected to
 * queryable -- so leaving these in the bag meant every install that wanted a
 * downloads report spending one of its 20 registration slots on a value OWA set
 * itself. That is the mistake GA makes with form_id and form_name, which have no
 * standard dimension and must be registered as customEvent:form_id.
 *
 * The element params (element_class, element_name, element_text), the form params
 * and the two commerce ones STAY IN THE BAG, deliberately. Most installs will
 * never group by an element class or a form name, and a column is width on every
 * row of every Property whether anyone reads it or not. MEASURED on MySQL 8.4:
 * promoting nine of them took the custom-dimension ceiling from 62 to 37. A site
 * that does want one registers it, which is the same route its own values take.
 * ct_line_items cannot be a column at all -- it is a nested array, which is why
 * GA ships `items` as its own repeated record rather than an event parameter.
 *
 * NO BACKFILL, AND NONE IS POSSIBLE FROM THE ROW. Rows already stored carry the
 * two values inside `params`, so the new columns are NULL for them -- but a cube
 * REBUILD does not help either, because the cube is built from raw and raw is
 * where the values sit in JSON. What would recover them is an UPDATE reading
 * params, which is a data migration over every retained partition and is the
 * operator's decision, not a side effect of a schema bump. Stated rather than
 * attempted: `cmd=cube-rebuild` will not do it, and thinking it might is the
 * trap.
 *
 * NO CUSTOM REGISTRATION IS CLEANED UP, and an earlier draft of this did that too.
 * A registration for `file_name` would read params.file_name, which nothing writes
 * from here on, so it would fill with NULL while still costing one of the
 * Property's twenty slots -- real, but unreachable: custom dimensions exist only on
 * the v2 branch and THE BRANCH HAS NOT SHIPPED. The only v2 install has one
 * registration and it is `plan`. Nor could it ever have collided: columnFor()
 * prefixes cd_, so the key was ever only cd_file_name.
 *
 * NOT CLI-ONLY: two columns on raw and on every cube.
 */
class Update054 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 54;

    var $is_cli_mode_required = false;

    /** The keys that stop reaching `params` in this release. */
    const PROMOTED = array( 'file_name', 'file_extension' );

    function up( $force = false ) {

        foreach ( self::PROMOTED as $column ) {

            if ( $this->addRawColumn( $column ) === false ) {

                return false;
            }
        }

        return true;
    }

    /**
     * The columns go; the values do not come back.
     *
     * A rollback restores a registry that declares these as params, so ingest
     * writes them into the JSON again from that point -- and the rows written
     * WHILE they were columns keep them in the columns being dropped. That is the
     * inverse this can offer: the shape, not the history. The de-registration has
     * no inverse either, because a registration is a Property's own declaration
     * and re-creating one would be inventing it.
     */
    function down() {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( self::PROMOTED as $column ) {

            if ( $this->dropCubeColumn( $column ) === false ) {

                return false;
            }

            if ( $this->dropColumnIfPresent( $raw, $column ) === false ) {

                $this->e->notice( sprintf(
                    'Dropping %s.%s failed', $raw->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
