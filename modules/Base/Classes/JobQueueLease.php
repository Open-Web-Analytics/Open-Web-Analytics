<?php
namespace OWA\Module\Base\Classes;

/**
 * A queued job's lease, handed to its controller so heartbeat() extends it
 * exactly as it extends a scheduled job's JobLease.
 *
 * A long job that calls heartbeat() at its safe points keeps its claim; one
 * that stops calling it -- because its process died -- loses the claim when
 * the lease runs out, and the next tick recovers the job.
 */
class JobQueueLease {

    /** @var string */
    private $id;

    /** @var int */
    private $lease_seconds;

    /** @var int|null the attempt this run claimed; null for any */
    private $attempt;

    /**
     * @param string          $id             the job's
     * @param int|string      $lease_seconds  as the job's row holds it
     * @param int|string|null $attempt        the claimed row's attempts: a later claim counts another
     */
    public function __construct( $id, $lease_seconds, $attempt = null ) {

        $this->id            = (string) $id;
        $this->lease_seconds = max( 1, (int) $lease_seconds );
        $this->attempt       = $attempt === null ? null : (int) $attempt;
    }

    /**
     * Push lease_until forward by the longer of the job's own lease and what
     * the command asks for, while the job is running on this run's claim.
     *
     * Whether it still is, read back rather than taken from the affected-row
     * count: an UPDATE that writes the value already there changes no row,
     * and a claim taken over after the lease ran out is still 'running', with
     * one more attempt.
     *
     * @param  int $seconds
     * @return bool false when the claim is no longer this run's
     */
    public function refresh( $seconds ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Module\Base\Classes\JobQueue::table();
        $ours  = $this->attempt === null ? '' : ' AND attempts = ' . $this->attempt;

        $db->query( sprintf( 'UPDATE %s SET lease_until = ? WHERE id = ? AND status = ?%s', $table, $ours ),
            [ \OWA\Module\Base\Classes\JobQueue::now() + max( $this->lease_seconds, (int) $seconds ), $this->id, 'running' ] );

        $row = $db->get_row( sprintf( 'SELECT status, attempts FROM %s WHERE id = ?', $table ), [ $this->id ] );

        return is_array( $row ) && $row['status'] === 'running'
            && ( $this->attempt === null || (int) $row['attempts'] === $this->attempt );
    }

    /**
     * The queue records the outcome; there is nothing to release here.
     *
     * @return bool
     */
    public function release() {

        return true;
    }
}
