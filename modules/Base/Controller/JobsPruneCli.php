<?php
namespace OWA\Module\Base\Controller;

/** cli.php cmd=jobs-prune: see JobsCli. */
class JobsPruneCli extends JobsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => sprintf( 'Deletes finished one-off jobs: done ones after %d days, failed ones after %d. Runs on the scheduler as prune-job-queue.',
                \OWA\Module\Base\Classes\JobQueue::KEEP_DONE / 86400, \OWA\Module\Base\Classes\JobQueue::KEEP_FAILED / 86400 ),
            'arguments'   => array(),
        );
    }

    const MODE = 'prune';
}

?>
