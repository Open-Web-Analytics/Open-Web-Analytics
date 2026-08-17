<?php
namespace OWA\Module\Base\Classes;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * A per-job lock, so a job can never overlap itself.
 *
 * The row's EXISTENCE is the lock and the primary key supplies the atomicity:
 * acquiring is a plain INSERT, and the loser of a race gets a constraint
 * violation. No transaction, no SELECT ... FOR UPDATE, no engine-specific
 * syntax. Every database enforces uniqueness, so this behaves identically
 * everywhere -- which is why it is used in preference to MySQL's GET_LOCK, that
 * being a one-driver solution to a problem core scheduling should not have.
 *
 * This is Laravel's DatabaseLock design, minus the parts that solve problems we
 * do not have: no pruning lottery, because job_name is the primary key so the
 * table is bounded by the registry rather than by arbitrary cache keys.
 */
class JobLease {

    /** @var string */
    protected $job_name;

    /** @var string */
    protected $owner;

    /** @var int */
    protected $expires_at = 0;

    /**
     * @param string $job_name
     */
    function __construct( $job_name ) {

        $this->job_name = (string) $job_name;

        // Unique per attempt, so a taker-over and the original holder can never
        // be confused for one another.
        $this->owner = bin2hex( random_bytes( 8 ) );
    }

    /**
     * @return string
     */
    public function getOwner() {

        return $this->owner;
    }

    /**
     * Take the lock, or report that someone else holds it.
     *
     * @param int $lease  seconds before a dead holder's lock may be taken over
     * @return bool
     */
    public function acquire( $lease ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $now = time();

        $this->expires_at = $now + max( 60, (int) $lease );

        // Clear an abandoned lock first. Scoped to THIS job and to a genuinely
        // expired row, so it can never disturb a live holder.
        $db->deleteJobLock( $this->job_name, $now );

        return (bool) $db->insertJobLock(
            $this->job_name, $this->owner, $now, $this->expires_at
        );
    }

    /**
     * Push the expiry out, for a job that can prove it is still alive.
     *
     * Scoped to our own owner token, so a run that has already been taken over
     * cannot extend a lock it no longer holds.
     *
     * Returns whether we STILL HOLD IT, read back rather than inferred from the
     * update: an UPDATE that matches no rows is still a successful statement,
     * and getAffectedRows() would tell us the difference on MySQL only -- which
     * is the portability this whole design is avoiding. The read-back costs one
     * query on a call that is infrequent by nature, and turns a return value
     * that could only ever say "the statement ran" into one a long job can act
     * on by stopping early when it has been superseded.
     *
     * @param int $lease
     * @return bool  false when the lock is no longer ours
     */
    public function refresh( $lease ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->expires_at = time() + max( 60, (int) $lease );

        $db->refreshJobLock( $this->job_name, $this->owner, $this->expires_at );

        $row = $db->getJobLock( $this->job_name );

        return $row && isset( $row['owner'] ) && $row['owner'] === $this->owner;
    }

    /**
     * Release, but only our own.
     *
     * A job that overran its lease and was taken over will find its token no
     * longer matches, and delete nothing -- which is correct. A late finisher
     * must not yank the lock out from under the run that replaced it.
     *
     * @return void
     */
    public function release() {

        \OWA\Core\CoreAPI::dbSingleton()->releaseJobLock( $this->job_name, $this->owner );
    }
}
