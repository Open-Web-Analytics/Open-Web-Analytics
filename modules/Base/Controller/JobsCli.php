<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The one-off job queue (PLAN 2.30.5), from the shell.
 *
 *   cli.php cmd=jobs [status=pending|running|failed|done] [limit=50]
 *   cli.php cmd=jobs-retry id=<id>|all     a failed job, due now, attempts cleared
 *   cli.php cmd=jobs-forget id=<id>        delete one job
 *   cli.php cmd=jobs-prune                 done after 7 days, failed after 30
 */
class JobsCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Lists the one-off job queue.',
            'arguments'   => array(
                'status=<pending|running|failed|done>' => 'List only jobs in this state.',
                'limit=<n>'                            => 'How many to list. Defaults to 50.',
            ),
        );
    }

    /** Which of the four this command is; the thin subclasses set it. */
    const MODE = 'list';

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $Q = '\OWA\Module\Base\Classes\JobQueue';

        switch ( static::MODE ) {

            case 'retry':

                $id = (string) $this->getParam( 'id' );

                if ( $id === '' ) {

                    return $this->refuse( 'Say which job: cmd=jobs-retry id=<id>, or id=all for every failed job.' );
                }

                \OWA\Core\CoreAPI::notice( sprintf( '%d failed job(s) made due again.', $Q::retry( $id ) ) );

                return;

            case 'forget':

                $id = (string) $this->getParam( 'id' );

                if ( $id === '' ) {

                    return $this->refuse( 'Say which job: cmd=jobs-forget id=<id>.' );
                }

                \OWA\Core\CoreAPI::notice( $Q::forget( $id ) ? "Job $id deleted." : "No job $id." );

                return;

            case 'prune':

                \OWA\Core\CoreAPI::notice( sprintf( '%d finished job(s) pruned.', $Q::prune() ) );

                return;
        }

        $status = $this->getParam( 'status' ) ?: null;
        $rows   = $Q::listJobs( $status, (int) ( $this->getParam( 'limit' ) ?: 50 ) );
        $q      = $Q::stats();

        $lines = array( sprintf( 'Queued jobs: due %d, delayed %d, running %d, failed %d, done %d.',
            $q['due'], $q['delayed'], $q['running'], $q['failed'], $q['done'] ) );

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

?>
