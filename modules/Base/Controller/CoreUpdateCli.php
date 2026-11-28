<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Automatic updates, run once (Classes\AutoUpdate\ReleaseCheck).
 *
 *   php cli.php cmd=core-update             list, download, install if the settings allow
 *   php cli.php cmd=core-update --dry-run   only say which release that is
 *
 * The scheduler runs it every hour, on any server that runs the scheduler:
 * four of those runs a day list the releases for the bell, and only a full
 * server downloads and installs. An install's files are copied when the run
 * has finished, and the next run of cli.php finishes it. Runs while updates
 * are pending: a release that fixes a failed update is the one to install.
 * cmd=core-install installs one release by hand.
 */
class CoreUpdateCli extends \OWA\Core\Controller\Cli {

    /** May be queued (JobQueue::isQueueable()): The Updates screen's Check queues it. */
    const QUEUEABLE = true;

    /** Release check outcomes that fail the run. */
    const FAILS = [ ReleaseCheck::FAILED, ReleaseCheck::UNREACHED, ReleaseCheck::NO_TEMP, ReleaseCheck::FOREIGN ];

    /**
     * Of those, the ones that give up (Cli::fail( $msg, true )): a retry
     * within the hour meets them again.
     *
     *   NO_TEMP  the temporary directory cannot be written: someone fixes that.
     *   FOREIGN  files another user owns: likewise.
     *   FAILED   a download or install that failed. The release check keeps
     *            its own schedule for these -- "the next listing tries
     *            again", and a failed install is tried 6 hours on, 3 times
     *            (Install) -- and a retry would spend another tens-of-megabytes
     *            download from the update host.
     *
     * UNREACHED, the listing not answering, is retried: that is the network.
     */
    const GIVES_UP = [ ReleaseCheck::FAILED, ReleaseCheck::NO_TEMP, ReleaseCheck::FOREIGN ];

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Automatic updates: stores new OWA releases as notifications, then finds the release this installation updates to, downloads and verifies it, checks that it can be installed here, and installs it if the settings allow. Runs on the scheduler every hour as core-update, listing the releases four times a day. A copy that fails puts the installed release back. To install one release by hand: cmd=core-install.',
            'arguments'   => [
                '--dry-run' => [
                    'type'        => 'flag',
                    'description' => 'Only say which release automatic updates would install: nothing is downloaded, written or installed.',
                ],
                '--scheduled' => [
                    'type'        => 'flag',
                    'description' => 'Set by the scheduler: the releases are listed only in four of the day\'s hours, and nothing is said.',
                ],
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** A release that fixes a failed update installs while it is pending. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $scheduled = (bool) $this->param( 'scheduled' );
        $result    = $this->releaseCheck( $scheduled )->run( (bool) $this->param( 'dry-run' ) );

        if ( ! $scheduled ) {

            $this->write( $result['lines'] );
        }

        if ( in_array( $result['outcome'], self::FAILS, true ) ) {

            return $this->fail( sprintf( 'Release check: %s. %s', $result['outcome'], implode( ' ', $result['problems'] ) ),
                in_array( $result['outcome'], self::GIVES_UP, true ) );
        }
    }

    /**
     * A method so a test can answer for it.
     *
     * @param  bool $scheduled
     * @return ReleaseCheck
     */
    protected function releaseCheck( $scheduled ) {

        return new ReleaseCheck( null, [ 'scheduled' => $scheduled ] );
    }
}
