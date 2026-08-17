<?php
namespace OWA\Module\Base\Entity;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * One lock per scheduled job, so a job can never overlap itself.
 *
 * THE ROW'S EXISTENCE IS THE LOCK, and its primary key supplies the atomicity.
 * Acquiring is a plain INSERT; the loser of a race gets a constraint violation
 * and simply does not run. No transaction, no SELECT ... FOR UPDATE, no
 * engine-specific syntax -- every database enforces uniqueness, so this behaves
 * identically everywhere. This is Laravel's cache_locks design, and it is why
 * MySQL's GET_LOCK was rejected: that works on one driver only, and baking a
 * driver dependency into core scheduling is the portability regression to avoid.
 *
 * Per job rather than one global scheduler lock, so a long queue drain cannot
 * starve partition-rotate while still guaranteeing no drain overlaps another.
 *
 * NOTHING PRUNES THIS TABLE, because nothing accumulates: job_name is the
 * primary key, so there is at most one row per job and the table is bounded by
 * the registry. An expired row is not litter either -- the next acquire for that
 * name takes it over rather than leaving it behind. Laravel needs a pruning
 * lottery because cache_locks is keyed by arbitrary strings and is unbounded;
 * that condition does not hold here. The only row that can genuinely linger
 * belongs to a job that no longer exists, which schedule-run --prune-orphans
 * removes along with its state row.
 */
class JobLock extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('job_lock');

        // The lock itself. Not an auto-increment id: uniqueness of the JOB NAME
        // is the mutual exclusion, so the name must be the primary key.
        $job_name = new \OWA\Module\Base\Classes\DbColumn( 'job_name', OWA_DTD_VARCHAR255 );
        $job_name->setPrimaryKey();
        $this->setProperty($job_name);

        // A token unique to the acquiring process. Release deletes only your
        // own row, so a process that took over an expired lock cannot be
        // released by the original holder finishing late and tidying up.
        $owner = new \OWA\Module\Base\Classes\DbColumn( 'owner', OWA_DTD_VARCHAR255 );
        $this->setProperty($owner);

        $acquired_at = new \OWA\Module\Base\Classes\DbColumn( 'acquired_at', OWA_DTD_INT );
        $this->setProperty($acquired_at);

        // A CRASH-RECOVERY TIMEOUT, NOT A RUNTIME BUDGET. Nothing enforces it:
        // there is no timer and no reaper. It is consulted only when another
        // process tries to acquire -- present and unexpired means skip, present
        // and expired means treat as abandoned and take over. A job that
        // outruns its lease is not killed; it keeps going, unaware, while a
        // later tick stops respecting its lock. Too short duplicates a long
        // job; too long delays recovery after a crash. Asymmetric, so the
        // default errs long.
        $expires_at = new \OWA\Module\Base\Classes\DbColumn( 'expires_at', OWA_DTD_INT );
        $this->setProperty($expires_at);

    }
}
