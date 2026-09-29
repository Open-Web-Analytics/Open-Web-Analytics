<?php

namespace OWA\Module\Base\Update;

/**
 * element_path goes; is_outbound arrives.
 *
 * ELEMENT_PATH READ NOTHING BACK. The tracker built a CSS selector by walking up
 * to eight ancestors with :nth-of-type() indexes and stored it in a VARCHAR(512)
 * on raw and on every cube. No report, widget, view or overlay ever grouped by
 * the `elementPath` dimension it fed -- the click overlay places clicks by
 * coordinate -- and as a group-by it could not have worked: two identical buttons
 * in a list are distinct values, and a template edit renumbers every path on the
 * site at once. Not aggregable, and not stable across a deploy.
 *
 * IS_OUTBOUND IS WHERE "THE CLICK LEFT THE SITE" NOW LIVES. It was nowhere:
 * Tracker.js carried an isOutboundUrl() with no caller but a unit test, no wire
 * field, no column, no dimension, and a docblock in classifyClickTarget()
 * claiming outbound "rides as a param" that nothing implemented. It is target_url's
 * host against page_location's -- two readings of one row, so no join, and
 * derived at ingest rather than sent.
 *
 * NOT NULL DEFAULT 0, like is_goal_event. The flag answers a question asked of
 * every row -- "is this an outbound click?" -- so a page_view answering 0 is
 * true. A nullable boolean would group as three buckets while the boolean
 * formatter renders NULL and 0 both as 'No', which is the isNewVisitor pie with
 * two slices called New.
 *
 * NO SAVED CUSTOM REPORT IS REWRITTEN, and an earlier draft of this did. A report
 * naming `elementPath` would be refused rather than ignored, so walking the saved
 * definitions looked like the careful thing -- but that dimension only ever existed
 * on the v2 branch, WHICH HAS NOT SHIPPED. No install in the world can carry such
 * a report: the only v2 install is the one this was written on, and it has 1159
 * saved reports and none of them names it. Update052 migrated goal conditions
 * because `browser` shipped in 1.x and other people's installs really do have
 * them. This is the same shape with none of the exposure, so the code went rather
 * than being kept as insurance against a state that cannot exist.
 *
 * NOT CLI-ONLY: one column off and one column on, on raw and on every cube.
 */
class Update053 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 53;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        if ( $this->dropCubeColumn( 'element_path' ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, 'element_path' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.element_path failed', $raw->getTableName() ) );

            return false;
        }

        // Raw and every cube, each the way it needs -- see the CubeColumn trait.
        return $this->addRawColumn( 'is_outbound' );
    }

    /**
     * What element_path was, spelled out because nothing declares it any more.
     *
     * addRawColumn() cannot serve down() here: it asks the ENTITY for the
     * definition, and up() is the release that takes the column out of the
     * entity.
     */
    const DEFINITION = 'VARCHAR(512)';

    /**
     * element_path comes back empty; is_outbound goes.
     *
     * THE VALUES CANNOT COME BACK. element_path was the only record of itself --
     * unlike Update052's browser, which was a copy of a surviving column -- so
     * the inverse restores the shape and nothing else. The custom-report edit has
     * no inverse either: the dimension the widget asked for is the value that was
     * removed.
     *
     * is_outbound is dropped rather than kept, because a rollback puts back a
     * dimension registry that does not declare it and a row builder that does not
     * fill it; leaving the column would leave every later row at the default.
     */
    function down() {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropCubeColumn( 'is_outbound' ) === false ) {

            return false;
        }

        if ( $this->dropColumnIfPresent( $raw, 'is_outbound' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.is_outbound failed', $raw->getTableName() ) );

            return false;
        }

        if ( $this->addColumnIfMissing( $raw, 'element_path', self::DEFINITION ) === false ) {

            $this->e->notice( sprintf(
                'Adding %s.element_path back failed', $raw->getTableName() ) );

            return false;
        }

        /*
         * ALGORITHM=INPLACE on the cube, as every cube add has to be: MySQL 8's
         * instant ADD COLUMN leaves row-format metadata a CREATE TABLE ... LIKE
         * staging table can never have, and EXCHANGE PARTITION then refuses the
         * pair with error 1731 -- so every later build fails having published
         * nothing. See the CubeColumn trait.
         */
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $existing = (array) $db->get_results( sprintf(
                "SHOW COLUMNS FROM %s LIKE 'element_path'", $table ) );

            if ( $existing ) {

                continue;
            }

            if ( ! $db->addColumnRebuilding( $table, 'element_path', self::DEFINITION )
              && $this->addColumnIfMissing(
                     $this->cubeEntity( $table ), 'element_path', self::DEFINITION ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.element_path back failed', $table ) );

                return false;
            }
        }

        return $this->clearCubeInstantColumns();
    }
}

?>
