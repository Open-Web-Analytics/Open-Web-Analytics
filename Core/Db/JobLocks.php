<?php
namespace OWA\Core\Db;

/**
 * The scheduler's job locks: one row per held job in the base.job_lock table.
 *
 * Works on one Db and reaches the database only through it, so a driver that
 * overrides query(), get_row() or jobLockTable() is honoured. Db keeps a
 * delegating method for each public method here, and OWA calls those.
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 */
class JobLocks {

    /** @var \OWA\Core\Db */
    private $db;

    /**
     * @param \OWA\Core\Db $db
     */
    public function __construct( \OWA\Core\Db $db ) {

        $this->db = $db;
    }

    /**
     * The lock table, from Db::jobLockTable(), which is protected and which a
     * driver may override.
     *
     * @return string
     */
    private function jobLockTable() {

        return ( function () {

            return $this->jobLockTable();
        } )->call( $this->db );
    }

    /**
     * Take a job lock. Fails, without raising, when someone already holds it.
     *
     * A plain INSERT: job_name is the primary key, so the database itself
     * decides the race and the loser simply gets a falsy result. That is what
     * makes this portable -- no advisory locks, no SELECT ... FOR UPDATE, no
     * engine-specific syntax.
     *
     * @param string $job_name
     * @param string $owner
     * @param int    $now
     * @param int    $expires_at
     * @return bool
     */
    public function insertJobLock( $job_name, $owner, $now, $expires_at ) {

        return (bool) $this->db->query( sprintf(
            'INSERT INTO %s (job_name, owner, acquired_at, expires_at) VALUES (?, ?, ?, ?)',
            $this->jobLockTable()
        ), [ (string) $job_name, (string) $owner, (int) $now, (int) $expires_at ] );
    }

    /**
     * Clear an abandoned lock for one job.
     *
     * Scoped to a genuinely expired row, so it can never disturb a live holder.
     * This is the only thing that ever frees a lock left behind by a process
     * that died -- there is no reaper and no timer.
     *
     * @param string $job_name
     * @param int    $now
     * @return bool
     */
    public function deleteJobLock( $job_name, $now ) {

        return (bool) $this->db->query( sprintf(
            'DELETE FROM %s WHERE job_name = ? AND expires_at <= ?',
            $this->jobLockTable()
        ), [ (string) $job_name, (int) $now ] );
    }

    /**
     * Extend a lock we still hold.
     *
     * Scoped to the owner token: a run that has already been taken over must
     * not be able to extend a lock it no longer owns.
     *
     * @param string $job_name
     * @param string $owner
     * @param int    $expires_at
     * @return bool
     */
    public function refreshJobLock( $job_name, $owner, $expires_at ) {

        return (bool) $this->db->query( sprintf(
            'UPDATE %s SET expires_at = ? WHERE job_name = ? AND owner = ?',
            $this->jobLockTable()
        ), [ (int) $expires_at, (string) $job_name, (string) $owner ] );
    }

    /**
     * Release a lock, but only our own.
     *
     * A job that overran its lease and was taken over finds its token no longer
     * matches and deletes nothing, which is correct: a late finisher must not
     * yank the lock from the run that replaced it.
     *
     * @param string $job_name
     * @param string $owner
     * @return bool
     */
    public function releaseJobLock( $job_name, $owner ) {

        return (bool) $this->db->query( sprintf(
            'DELETE FROM %s WHERE job_name = ? AND owner = ?',
            $this->jobLockTable()
        ), [ (string) $job_name, (string) $owner ] );
    }

    /**
     * The lock row for a job, for reporting. Null when unheld.
     *
     * @param string $job_name
     * @return Row|null
     */
    public function getJobLock( $job_name ) {

        $row = $this->db->get_row( sprintf(
            'SELECT job_name, owner, acquired_at, expires_at FROM %s WHERE job_name = ?',
            $this->jobLockTable()
        ), [ (string) $job_name ] );

        return $row ? (array) $row : null;
    }
}
