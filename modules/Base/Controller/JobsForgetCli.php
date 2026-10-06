<?php
namespace OWA\Module\Base\Controller;

/** cli.php cmd=jobs-forget: see JobsCli. */
class JobsForgetCli extends JobsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Deletes one job from the one-off job queue.',
            'arguments'   => array(
                'id=<id>' => 'Required. The job to delete.',
            ),
        );
    }

    const MODE = 'forget';
}

?>
