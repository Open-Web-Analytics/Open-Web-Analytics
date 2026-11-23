<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * The notice a failed install calls for (last-failure.json), raised once
 * (NotificationManager::notify(), source auto_update, key
 * install_failed:<version>).
 *
 * A failure is recorded before OWA can say so: by the copy, in the process
 * that may load nothing more; by the next run of cli.php putting the
 * installed release back, before OWA loads; or by Finish, when the schema
 * updates did not complete. The next booted run of cli.php raises it
 * (fromDisk()), and so does the release check.
 */
class InstallNotices {

    /**
     * @param  array<string, mixed>                  $failure last-failure.json
     * @param  callable(array<string, mixed>): mixed $notify  NotificationManager::notify() or a stand-in
     * @return void
     */
    public static function forFailure( array $failure, callable $notify ) {

        $v     = (string) ( $failure['version'] ?? '' );
        $error = trim( (string) ( $failure['error'] ?? '' ) );

        if ( $v === '' || $error === '' ) {

            return;
        }

        $body = $error . "\n\n" . ( empty( $failure['booted'] )
            ? sprintf( CoreAPI::t( 'The installed release runs as before. Automatic updates skip OWA %1$s; a newer release installs as usual. To try it again: php cli.php cmd=core-update-install version=%1$s' ), $v )
            : CoreAPI::t( 'Its files are installed, and its schema updates did not complete. Until they do, tracking is queued and reports wait: fix the cause, then run php cli.php cmd=core-update-schema' ) );

        $notify( [
            'source'     => ReleaseCheck::SOURCE,
            'source_key' => 'install_failed:' . $v,
            'title'      => sprintf( CoreAPI::t( 'Installing OWA %s failed' ), $v ),
            'body'       => $body,
            'severity'   => 'warning',
            'email'      => true,
        ] );
    }

    /**
     * The notice the failure on disk calls for, if there is one: one
     * is_file() when there is not.
     *
     * @return void
     */
    public static function fromDisk() {

        try {

            $failure = ( new Downloads() )->failure();

            if ( $failure ) {

                self::forFailure( $failure, static fn ( array $n ) => \OWA\Module\Base\Classes\NotificationManager::notify( $n ) );
            }

        } catch ( \Throwable $e ) {

            CoreAPI::debug( 'Install notices: ' . $e->getMessage() );
        }
    }
}
