<?php
namespace OWA\Core\Controller;

/**
 * Thrown by Cli::heartbeat() when the job's lease is no longer this run's:
 * it ran out and another run took the job over. The run stops, and the
 * scheduler records no outcome for it: the run holding the lease does.
 */
class JobLeaseLost extends \RuntimeException {

}
