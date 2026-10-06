<?php
namespace OWA\Module\Base\Controller;

/** cli.php cmd=jobs-prune: see JobsCli. */
class JobsPruneCli extends JobsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Deletes finished one-off jobs: done ones after 7 days, failed ones after 30. Runs on the scheduler as prune-job-queue.',
            'arguments'   => array(),
        );
    }

    const MODE = 'prune';
}

?>
