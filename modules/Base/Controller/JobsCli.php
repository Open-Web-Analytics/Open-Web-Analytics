<?php
namespace OWA\Module\Base\Controller;

/**
 * The one-off job queue, from the shell.
 *
 *   cli.php cmd=jobs [status=pending|running|failed|done] [limit=50]
 *   cli.php cmd=jobs-retry id=<id>|all     a failed job, due now, attempts cleared
 *   cli.php cmd=jobs-forget id=<id>        delete one job
 *   cli.php cmd=jobs-prune                 done after 7 days, failed after 30
 */
class JobsCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Lists the one-off job queue.',
            'arguments'   => [
                'status' => [
                    'type'        => 'select',
                    'options'     => [ 'pending', 'running', 'failed', 'done' ],
                    'description' => 'List only jobs in this state.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'min'         => 1,
                    'default'     => 50,
                    'description' => 'How many to list.',
                ],
            ],
        ];
    }

    /** Which of the four this command is; the thin subclasses set it. */
    const MODE = 'list';

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function action() {

        $Q = '\OWA\Module\Base\Classes\JobQueue';

        switch ( static::MODE ) {

            case 'retry':

                $id = (string) $this->param( 'id' );

                $retried = $Q::retry( $id );

                \OWA\Core\CoreAPI::notice( sprintf( '%d failed job%s made due again.', $retried, $retried === 1 ? '' : 's' ) );

                return;

            case 'forget':

                $id = (string) $this->param( 'id' );

                if ( ! $Q::forget( $id ) ) {

                    return $this->fail( "No job $id." );
                }

                \OWA\Core\CoreAPI::notice( "Job $id deleted." );

                return;

            case 'prune':

                $pruned = $Q::prune();

                \OWA\Core\CoreAPI::notice( sprintf( '%d finished job%s pruned.', $pruned, $pruned === 1 ? '' : 's' ) );

                return;
        }

        $rows   = $Q::listJobs( $this->param( 'status' ), (int) $this->param( 'limit' ) );
        $q      = $Q::stats();

        $lines = [ sprintf( 'Queued jobs: due %d, delayed %d, running %d, failed %d, done %d.',
            $q['due'], $q['delayed'], $q['running'], $q['failed'], $q['done'] ) ];

        foreach ( $rows as $row ) {

            $lines[] = sprintf( '%s  %-8s %-24s attempt %d/%d  queued %s%s',
                $row['id'], $row['status'], $row['command'],
                $row['attempts'], $row['max_attempts'],
                date( 'Y-m-d H:i', (int) $row['created_at'] ),
                $row['last_error'] ? "\n    " . $row['last_error'] : '' );
        }

        $this->write( $lines );
    }
}
