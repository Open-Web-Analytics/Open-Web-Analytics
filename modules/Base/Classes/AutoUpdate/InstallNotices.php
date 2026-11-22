<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The notices an install's install.json calls for, each raised once
 * (NotificationManager::notify(), source auto_update).
 *
 *   install_failed:<v>          warning, at the first failed attempt
 *   install_stuck:<v>           critical, when the weekly back-off starts
 *                               (the fourth failed attempt)
 *   install_stuck:<v>:<n>       a reminder at each weekly attempt after that
 *
 * Raised by Step 4: finish when the new release booted and failed, and by
 * Step 1: release check for what it finds on disk: a copy error or a release
 * that did not boot, restored, leaves only the old release to say so.
 */
class InstallNotices {

    /** The failed attempt at which the back-off becomes weekly. */
    const WEEKLY_FROM = 4;

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
        $body  = $error . "\n\n" . sprintf( CoreAPI::t( 'Automatic updates try again after %s. Until the install succeeds, tracking is queued and reports wait. To try now: php cli.php cmd=self-update-install version=%s' ), $retry, $v );

        if ( $attempts < self::WEEKLY_FROM ) {

            $notify( self::notice( 'install_failed:' . $v, 'warning', sprintf( CoreAPI::t( 'Installing OWA %s failed' ), $v ), $body ) );

            return;
        }

        $key = $attempts === self::WEEKLY_FROM ? 'install_stuck:' . $v : sprintf( 'install_stuck:%s:%d', $v, $attempts );

        $notify( self::notice( $key, 'critical', sprintf( CoreAPI::t( 'OWA %s still has not installed' ), $v ),
            $body . "\n\n" . CoreAPI::t( 'It is tried once a week from now on. Fix the cause and run php cli.php cmd=update; a newer release still installs as usual.' ) ) );
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
