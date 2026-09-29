<?php

namespace OWA\Module\Base\Update;

/**
 * owa_event_raw.browser goes, because browser_type is the same reading.
 *
 * Both columns were written from one property -- the row builder read
 * browser_type and stored it twice -- and only one of them is reported on:
 * config/dimensions.php declares `browserType` against browser_type, and nothing
 * anywhere reads `browser`. So it is a second copy of a value, 128 bytes wide,
 * on every row of raw and of every cube.
 *
 * THE ROW LIMIT IS WHY THIS IS WORTH A MIGRATION rather than being left alone. A
 * MySQL row cannot exceed 65,535 bytes and the cube is deliberately wide, so
 * every column that carries nothing new is budget a real dimension cannot have.
 *
 * A GOAL CONDITION MAY NAME IT, so conditions are migrated before the column is
 * dropped. The two values are identical -- the same property wrote both -- so
 * `browser` -> `browser_type` is lossless, unlike Update049's many-to-one
 * mapping. Measured here: no install has a condition on it, and other people's
 * might.
 *
 * NOT CLI-ONLY: one column off raw and off every cube.
 */
class Update052 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 52;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        if ( $this->migrateConditions() === false ) {

            return false;
        }

        if ( $this->dropCubeColumn( 'browser' ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, 'browser' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.browser failed', $raw->getTableName() ) );

            return false;
        }

        return true;
    }

    /**
     * A condition on `browser` becomes a condition on browser_type.
     *
     * Read through the query builder rather than raw SQL: a raw read of this
     * table answered zero rows once during this work and made every condition
     * look orphaned.
     *
     * IDEMPOTENT: a second run finds no condition naming the old column.
     *
     * @return bool
     */
    protected function migrateConditions() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'condition_property', 'browser' );

        foreach ( (array) $db->getAllRows() as $row ) {

            $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
            $condition->load( $row['id'] );

            if ( ! $condition->wasPersisted() ) {

                continue;
            }

            $condition->set( 'condition_property', 'browser_type' );

            if ( $condition->update() === false ) {

                $this->e->notice( sprintf(
                    'Migrating goal condition %s off the browser column failed',
                    $row['id'] ) );

                return false;
            }

            $this->e->notice( sprintf(
                'Goal condition %s now reads browser_type', $row['id'] ) );
        }

        return true;
    }

    /**
     * What the column was, spelled out because nothing declares it any more.
     *
     * addRawColumn() cannot serve down() here: it asks the ENTITY for the
     * definition, and up() is the release that takes the column out of the
     * entity. So the definition has to be a literal in the update that removed
     * it -- which is the case addColumnIfMissing()'s explicit-definition
     * argument exists for.
     */
    const DEFINITION = 'VARCHAR(128)';

    /**
     * The column comes back, on raw and on every cube.
     *
     * The VALUES do not, and cannot: they were a copy of browser_type, so a
     * down() that wanted them back would read the surviving column -- which is
     * what up() established is the same reading. Restoring the shape is the
     * whole inverse there is, and `UPDATE ... SET browser = browser_type` is one
     * statement away for anyone who wants the values too.
     *
     * The condition migration has no inverse either, for the same reason it was
     * safe: both spellings named one value, so there is nothing to put back.
     */
    function down() {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->addColumnIfMissing( $raw, 'browser', self::DEFINITION ) === false ) {

            $this->e->notice( sprintf(
                'Adding %s.browser back failed', $raw->getTableName() ) );

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
                "SHOW COLUMNS FROM %s LIKE 'browser'", $table ) );

            if ( $existing ) {

                continue;
            }

            if ( ! $db->addColumnRebuilding( $table, 'browser', self::DEFINITION )
              && $this->addColumnIfMissing(
                     $this->cubeEntity( $table ), 'browser', self::DEFINITION ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.browser back failed', $table ) );

                return false;
            }
        }

        return true;
    }
}

?>
