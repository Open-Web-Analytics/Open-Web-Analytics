<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One queued admin job (PLAN 2.30.5): a registered CLI command and its
 * arguments, run once by the scheduler.
 *
 * Read and written by Classes\JobQueue with its own SQL -- the claim needs
 * FOR UPDATE SKIP LOCKED and conditional updates the entity layer does not
 * express. This declares the table so install and the update create it.
 */
class JobQueue extends \OWA\Core\Entity {

    /** The index the claim reads due jobs through. */
    const DUE_INDEX = 'status_run_after';

    /** The index the claim reads expired leases through. */
    const LEASE_INDEX = 'status_lease_until';

    /** One pending job per key. */
    const KEY_INDEX = 'dedupe_key_unique';

    function __construct() {

        $this->setTableName( 'job_queue' );

        // Random, assigned at enqueue, kept for the job's life through retries.
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        // A registered CLI command, as cli.php cmd= names it.
        $command = new \OWA\Module\Base\Classes\DbColumn( 'command', OWA_DTD_VARCHAR128 );
        $command->setNotNull();
        $this->setProperty( $command );

        // Its arguments, JSON. Never serialized PHP.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'params', OWA_DTD_TEXT ) );

        // Unique while pending; released when the job is claimed.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'dedupe_key', OWA_DTD_VARCHAR255 ) );
        $this->addUniqueIndex( self::KEY_INDEX, array( 'dedupe_key' ) );

        // pending | running | done | failed
        $status = new \OWA\Module\Base\Classes\DbColumn( 'status', OWA_DTD_VARCHAR16 );
        $status->setNotNull();
        $this->setProperty( $status );

        foreach ( array( 'run_after', 'lease_until' ) as $name ) {
            $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( $name, OWA_DTD_INT ) );
        }

        $this->addCompositeIndex( self::DUE_INDEX, array( 'status', 'run_after' ) );
        $this->addCompositeIndex( self::LEASE_INDEX, array( 'status', 'lease_until' ) );

        // The policy, snapshotted from the command's declaration at enqueue.
        foreach ( array( 'attempts', 'max_attempts', 'lease_seconds' ) as $name ) {
            $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( $name, OWA_DTD_INT ) );
        }

        // Seconds between attempts, comma separated; the last repeats.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'backoff', OWA_DTD_VARCHAR255 ) );

        // Bounded by JobQueue::ERROR_LENGTH: STRICT mode refuses longer.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'last_error', OWA_DTD_VARCHAR1024 ) );

        foreach ( array( 'created_at', 'started_at', 'finished_at' ) as $name ) {
            $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( $name, OWA_DTD_INT ) );
        }
    }
}

?>
