<?php
namespace OWA\Module\Base\Entity;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * What the scheduler knows about each job it has run.
 *
 * One row per job, materialised by the dispatcher. It exists primarily for ONE
 * column -- last_run_slot -- which is what makes the scheduler level-triggered:
 * without it the only question that could be asked is "does the expression match
 * this minute", and a missed minute would mean a missed month. Everything else
 * here is observability, and is what schedule-status reads.
 *
 * NO SCHEDULE OR PARAMS COLUMNS. Both are resolved fresh at every tick from the
 * module registry plus OWA_SCHEDULED_JOBS, so there is no second source of truth
 * to drift and nothing to migrate when either changes. last_params is a record
 * of what a past run used, never an input to the next one.
 *
 * EVERY COLUMN IS EITHER A NON-EMPTY STRING OR MONOTONIC. That is a constraint,
 * not an accident: Entity::set() skips falsy values, and partialUpdate() carries
 * the same guard, so nothing in the entity layer can write 0, '' or null. A
 * counter that had to be reset to zero would be unwritable. Hence
 * last_success_at / last_failure_at rather than a consecutive_failures field --
 * which also carries more information: "currently failing" is
 * last_failure_at > last_success_at, and "failing since" is last_failure_at.
 */
class ScheduledJob extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('scheduled_job');

        // Derived from the job name so a duplicate state row is impossible at
        // the storage layer. NEVER USED AS A LOOKUP KEY: Lib::setStringGuid()
        // branches on the use_64bit_hash setting, so flipping that on a live
        // install would change what generateId() returns for the same name, and
        // a read by id would miss the existing row and then insert a second one
        // colliding on job_name but not on id. Every read and write keys on
        // job_name.
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty($id);

        $job_name = new \OWA\Module\Base\Classes\DbColumn( 'job_name', OWA_DTD_VARCHAR255 );
        $job_name->setIndex();
        $this->setProperty($job_name);

        // The occurrence the last run satisfied -- NOT when it ran. The due
        // test reads this and nothing else. Keeping it separate from
        // last_run_at makes the decision independent of how long a run took.
        $last_run_slot = new \OWA\Module\Base\Classes\DbColumn( 'last_run_slot', OWA_DTD_INT );
        $this->setProperty($last_run_slot);

        $last_run_at = new \OWA\Module\Base\Classes\DbColumn( 'last_run_at', OWA_DTD_INT );
        $this->setProperty($last_run_at);

        // last_finished_at < last_run_at means the process died mid-job. A
        // crash detector that needs no shutdown handler -- which could itself
        // fail to run, which is the whole problem with shutdown handlers.
        $last_finished_at = new \OWA\Module\Base\Classes\DbColumn( 'last_finished_at', OWA_DTD_INT );
        $this->setProperty($last_finished_at);

        $last_duration = new \OWA\Module\Base\Classes\DbColumn( 'last_duration', OWA_DTD_INT );
        $this->setProperty($last_duration);

        // 'ok' | 'refused' | 'failed'. Always non-empty.
        $last_status = new \OWA\Module\Base\Classes\DbColumn( 'last_status', OWA_DTD_VARCHAR255 );
        $this->setProperty($last_status);

        $last_message = new \OWA\Module\Base\Classes\DbColumn( 'last_message', OWA_DTD_VARCHAR255 );
        $this->setProperty($last_message);

        // The arguments the last run actually used. Scheduling never reads it;
        // it is here so the history can answer WHY a run behaved as it did. Add
        // keep=24 in config and a rotate starts deleting partitions -- without
        // this the row would say 'ok' and nothing else.
        $last_params = new \OWA\Module\Base\Classes\DbColumn( 'last_params', OWA_DTD_VARCHAR255 );
        $this->setProperty($last_params);

        $last_success_at = new \OWA\Module\Base\Classes\DbColumn( 'last_success_at', OWA_DTD_INT );
        $this->setProperty($last_success_at);

        $last_failure_at = new \OWA\Module\Base\Classes\DbColumn( 'last_failure_at', OWA_DTD_INT );
        $this->setProperty($last_failure_at);

        $run_count = new \OWA\Module\Base\Classes\DbColumn( 'run_count', OWA_DTD_INT );
        $this->setProperty($run_count);

        $failure_count = new \OWA\Module\Base\Classes\DbColumn( 'failure_count', OWA_DTD_INT );
        $this->setProperty($failure_count);

    }
}
