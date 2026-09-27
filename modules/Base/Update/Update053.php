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
 * A SAVED CUSTOM REPORT MAY NAME elementPath, and a dimension the registry no
 * longer knows is refused rather than ignored -- so the definitions are walked
 * first. There is no replacement to map it onto, unlike Update052's lossless
 * browser -> browser_type, so the dimension is REMOVED from the widget that asked
 * for it. Measured here: no saved report on this install names it, and other
 * people's might.
 *
 * NOT CLI-ONLY: one column off and one column on, on raw and on every cube.
 */
class Update053 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 53;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        if ( $this->stripDimensionFromReports( 'elementPath' ) === false ) {

            return false;
        }

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
     * Take one dimension out of every saved custom report that names it.
     *
     * A definition is JSON: a list of widgets, each with a `query` whose
     * `dimensions` is a comma-separated string of registered names. The name is
     * removed from that string; a widget left with none keeps the key absent
     * rather than an empty string, which is what an unspecified dimension looks
     * like everywhere else.
     *
     * IDEMPOTENT: a second run finds no definition containing the name.
     *
     * Read and written through the entity rather than with raw SQL, for the
     * reason Update052 records -- a raw read of a table answered zero rows once
     * during that work and made every row look absent.
     *
     * @param string $dimension
     * @return bool
     */
    protected function stripDimensionFromReports( $dimension ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        /*
         * '=@' is "contains" -- LOCATE() through the dialect. NOT 'LIKE', which
         * is not in Db::ALLOWED_OPERATORS: the operator is interpolated unquoted,
         * so anything outside that set is refused and logged, and the query would
         * have silently come back unfiltered.
         */
        $db->where( 'definition', $dimension, '=@' );

        foreach ( (array) $db->getAllRows() as $row ) {

            $report = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
            $report->load( $row['id'] );

            if ( ! $report->wasPersisted() ) {

                continue;
            }

            $definition = json_decode( (string) $report->get( 'definition' ), true );

            if ( ! is_array( $definition ) ) {

                continue;
            }

            $stripped = $this->withoutDimension( $definition, $dimension );

            if ( $stripped === $definition ) {

                continue;
            }

            $report->set( 'definition', json_encode( $stripped ) );

            if ( $report->update() === false ) {

                $this->e->notice( sprintf(
                    'Removing %s from custom report %s failed', $dimension, $row['id'] ) );

                return false;
            }

            $this->e->notice( sprintf(
                'Custom report %s no longer groups by %s', $row['id'], $dimension ) );
        }

        return true;
    }

    /**
     * The same definition with one dimension name gone from every widget query.
     *
     * @param array  $definition
     * @param string $dimension
     * @return array
     */
    protected function withoutDimension( array $definition, $dimension ) {

        if ( ! isset( $definition['widgets'] ) || ! is_array( $definition['widgets'] ) ) {

            return $definition;
        }

        foreach ( $definition['widgets'] as $i => $widget ) {

            if ( ! isset( $widget['query']['dimensions'] ) ) {

                continue;
            }

            $names = array_values( array_filter(
                array_map( 'trim', explode( ',', (string) $widget['query']['dimensions'] ) ),
                function ( $name ) use ( $dimension ) {

                    return $name !== '' && $name !== $dimension;
                } ) );

            if ( $names ) {

                $definition['widgets'][ $i ]['query']['dimensions'] = implode( ',', $names );

            } else {

                unset( $definition['widgets'][ $i ]['query']['dimensions'] );
            }

            /*
             * A sort naming the dropped dimension would be a sort on a column
             * the query no longer selects, which the resolver refuses -- so it
             * goes with it. The trailing '-' is the descending marker.
             */
            if ( isset( $widget['query']['sort'] )
                 && rtrim( (string) $widget['query']['sort'], '-' ) === $dimension ) {

                unset( $definition['widgets'][ $i ]['query']['sort'] );
            }
        }

        return $definition;
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
