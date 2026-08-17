<?php
namespace OWA\Module\Base\Update;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * The scheduler's two tables.
 *
 * owa_scheduled_job holds per-job state -- above all last_run_slot, which is
 * what lets the scheduler stay due until it has actually run rather than only
 * at the exact minute an expression matches.
 *
 * owa_job_lock holds one row per running job, where the row's existence IS the
 * lock.
 *
 * Creating two small tables is fast and takes no locks worth worrying about, so
 * this does not require CLI mode -- unlike the updates that rewrite fact tables.
 *
 * The DDL is CREATE TABLE IF NOT EXISTS, so this is idempotent and converges
 * with a fresh install, which creates both through Core\Module::install()'s
 * entity loop rather than through here.
 *
 * It deliberately creates the tables and NOT their contents. Rows are written
 * only by the dispatcher, which is what lets "any state row exists" stand as
 * proof that the dispatcher has run at least once -- the check schedule-status
 * uses to tell a missing crontab entry from a healthy but idle installation.
 */
class Update015 extends \OWA\Core\Update {

    var $schema_version = 15;

    var $is_cli_mode_required = false;

    function up($force = false) {

        foreach ( array( 'scheduled_job', 'job_lock' ) as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.' . $name );

            if ( $entity->createTable() === false ) {

                $this->e->notice( sprintf( 'Create table %s failed', $name ) );

                return false;
            }
        }

        return true;
    }

    function down() {

        foreach ( array( 'scheduled_job', 'job_lock' ) as $name ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.' . $name )->dropTable();
        }

        return true;
    }
}
