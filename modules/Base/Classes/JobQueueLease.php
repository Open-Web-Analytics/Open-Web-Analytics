<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

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

    public function __construct( $id, $lease_seconds ) {

        $this->id            = (string) $id;
        $this->lease_seconds = max( 1, (int) $lease_seconds );
    }

    /**
     * Push lease_until forward by the longer of the job's own lease and what
     * the command asks for.
     *
     * @param  int $seconds
     * @return bool
     */
    public function refresh( $seconds ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query( sprintf( 'UPDATE %s SET lease_until = ? WHERE id = ? AND status = ?',
            \OWA\Module\Base\Classes\JobQueue::table() ),
            array( \OWA\Module\Base\Classes\JobQueue::now() + max( $this->lease_seconds, (int) $seconds ), $this->id, 'running' ) );

        return (int) $db->getAffectedRows() > 0;
    }

    /** The queue records the outcome; there is nothing to release here. */
    public function release() {

        return true;
    }
}

?>
