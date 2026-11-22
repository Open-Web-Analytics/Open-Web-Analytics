<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The notices an install's install.json calls for, each raised once
 * (NotificationManager::notify(), source auto_update).
 *
 *   install_failed:<v>          warning, at the first failed attempt
 *   install_stuck:<v>           critical, when the weekly back-off starts
 *                               (the seventh failed attempt)
 *   install_stuck:<v>:<n>       a reminder at each weekly attempt after that
 *
 * Raised by Step 4: finish when the new release booted and failed, and for
 * what is on disk by every booted CLI run (fromDisk(), from cli.php) and by
 * Step 1: release check. An undone copy error leaves the old release to say
 * so straight after, in the same run; a release that did not boot, at the
 * next run, a minute later.
 */
class InstallNotices {

    /** The failed attempt at which the back-off becomes weekly (updater.php `backoff`). */
    const WEEKLY_FROM = 7;

    /**
     * @param  array<string, mixed>       $install install.json
     * @param  callable(array<string, mixed>): mixed $notify NotificationManager::notify() or a stand-in
     * @return void
     */
    public static function forInstall( array $install, callable $notify ) {

        $v        = (string) ( $install['version'] ?? '' );
        $attempts = (int) ( $install['attempts'] ?? 0 );
        $error    = (string) ( $install['error'] ?? '' );

        if ( ( $install['state'] ?? null ) !== 'installing' || $error === '' || $v === '' || $attempts < 1 ) {

            return;
        }

        $retry = gmdate( 'Y-m-d H:i', (int) ( $install['retry_after'] ?? 0 ) ) . ' UTC';
        // Before the new release booted the old one was put back, and runs as before.
        $body  = $error . "\n\n" . sprintf( empty( $install['booted'] )
            ? CoreAPI::t( 'Automatic updates try again after %s; the installed release runs as before until then. To try now: php cli.php cmd=self-update-install' )
            : CoreAPI::t( 'Automatic updates try again after %s. Until the install succeeds, tracking is queued and reports wait. To try now: php cli.php cmd=self-update-install' ), $retry );

        if ( $attempts < self::WEEKLY_FROM ) {

            $notify( self::notice( 'install_failed:' . $v, 'warning', sprintf( CoreAPI::t( 'Installing OWA %s failed' ), $v ), $body ) );

            return;
        }

        $key = $attempts === self::WEEKLY_FROM ? 'install_stuck:' . $v : sprintf( 'install_stuck:%s:%d', $v, $attempts );

        $notify( self::notice( $key, 'critical', sprintf( CoreAPI::t( 'OWA %s still has not installed' ), $v ),
            $body . "\n\n" . CoreAPI::t( 'It is tried once a week from now on. Fix the cause and run the command above; a newer release still installs as usual.' ) ) );
    }

    /**
     * The notices the unfinished install on disk calls for, if there is one:
     * one is_file() when there is not.
     *
     * @return void
     */
    public static function fromDisk() {

        try {

            $install = ( new Staging() )->install();

            if ( $install ) {

                self::forInstall( $install, static fn ( array $n ) => \OWA\Module\Base\Classes\NotificationManager::notify( $n ) );
            }

        } catch ( \Throwable $e ) {

            CoreAPI::debug( 'Install notices: ' . $e->getMessage() );
        }
    }

    /**
     * @param  string $key
     * @param  string $severity
     * @param  string $title
     * @param  string $body
     * @return array<string, mixed>
     */
    private static function notice( $key, $severity, $title, $body ) {

        return [
            'source'     => ReleaseCheck::SOURCE,
            'source_key' => $key,
            'title'      => $title,
            'body'       => $body,
            'severity'   => $severity,
            'email'      => true,
        ];
    }
}
