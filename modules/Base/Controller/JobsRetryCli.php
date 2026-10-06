<?php
namespace OWA\Module\Base\Controller;

/** cli.php cmd=jobs-retry: see JobsCli. */
class JobsRetryCli extends JobsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Makes a failed one-off job due now, with its attempts cleared.',
            'arguments'   => array(
                'id=<id|all>' => 'Required. The job to retry, or all for every failed job.',
            ),
        );
    }

    const MODE = 'retry';
}

?>
