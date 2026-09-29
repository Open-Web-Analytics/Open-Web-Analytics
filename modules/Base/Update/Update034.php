<?php

namespace OWA\Module\Base\Update;

/**
 * Create v2's two tables: owa_event_raw and owa_visitor_acquisition.
 *
 * Both are empty when this runs, so it adds storage and changes no behaviour. It
 * is the first schema change of the v2 work and deliberately the whole of it:
 * the cube and its build are a later phase, and neither is specified well
 * enough yet to be created and then altered.
 *
 * createTable() emits CREATE TABLE IF NOT EXISTS and builds the statement from
 * the entity, so up() is idempotent and converges with a fresh install, which
 * creates the same tables through Core\Module::install()'s entity loop rather
 * than through here.
 *
 * NOT CLI-ONLY. Both tables are created empty -- there is no data migration and
 * no ALTER of an existing fact table, so this costs a fresh install and an
 * upgrade the same two DDL statements. The updates that require CLI mode are
 * the ones that rewrite a partitioned table.
 *
 * owa_event_raw is created ALREADY PARTITIONED, on the current month plus a
 * year's lead, because Db::createTable() reads the entity's partition column.
 * That is the one thing worth doing here rather than later: partitioning a
 * table after it holds rows rewrites every one of them, and partition-init is
 * explicitly a conversion command for installations that predate partitioning.
 * A table created inside the lead never needs converting.
 *
 * FIRST, 32-BIT IDS ARE RE-KEYED (Classes\Migration\WideIds). This is the
 * first update a 1.14 installation runs, and every later one derives ids --
 * settings, goal events, visualizations -- so the installation stops deriving
 * 32-bit ids here, before any of them do, and the kept rows still holding one
 * are moved to 64-bit. It does not depend on the administrator having run
 * rederive-dimension-ids on 1.14, which v2 no longer has.
 */
class Update034 extends \OWA\Core\Update {

    var $schema_version = 34;

    var $is_cli_mode_required = false;

    /**
     * The entities this update creates, in creation order.
     *
     * @return string[]
     */
    private function tables() {

        return array( 'base.event_raw', 'base.visitor_acquisition' );
    }

    function up( $force = false ) {

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'use_32bit_hash' ) ) {

            \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash_before_v2', true );
        }

        // Cleared first: from here on every derivation, in this update and the
        // ones after it, is 64-bit.
        \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', false );

        $ids = new \OWA\Module\Base\Classes\Migration\WideIds();
        $ok  = $ids->widen();

        foreach ( $ids->report as $line ) {

            $this->e->notice( $line );
        }

        if ( ! $ok ) {

            return false;
        }

        foreach ( $this->tables() as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $entity->createTable() === false ) {

                $this->e->notice( sprintf( 'Create table %s failed', $entity->getTableName() ) );

                return false;
            }
        }

        return true;
    }

    /**
     * Drop both tables.
     *
     * A rollback of this update is a rollback of the release that introduced
     * v2 collection, so the code that writes these tables is going away with
     * it. Dropping them is therefore the exact inverse rather than a data loss
     * being glossed over -- anything they hold was collected by a feature that
     * is being removed, and v1's own tables are untouched and still
     * authoritative for the same period.
     *
     * DROP TABLE IF EXISTS, so a rollback that stopped part way can be re-run.
     * Reverse order of creation, which costs nothing here (there are no foreign
     * keys between them) and stays correct if one is ever added.
     */
    function down() {

        foreach ( array_reverse( $this->tables() ) as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $entity->dropTable() === false ) {

                $this->e->notice( sprintf( 'Drop table %s failed', $entity->getTableName() ) );

                return false;
            }
        }

        // An installation that arrived deriving 32-bit ids goes back to them.
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'use_32bit_hash_before_v2' ) ) {

            \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash_before_v2', false );

            // Before narrowing, so a settings row it writes is narrowed with the rest.
            \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', true );

            $ids = new \OWA\Module\Base\Classes\Migration\WideIds();
            $ok  = $ids->narrow();

            foreach ( $ids->report as $line ) {

                $this->e->notice( $line );
            }

            return $ok;
        }

        return true;
    }
}

?>
