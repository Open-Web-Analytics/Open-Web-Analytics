<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * What a failed install says (last-failure.json), the same wherever it is
 * shown (describe()): the notice and its e-mail, the Updates screen, System
 * Health and the CLI. What failed and why, then either that the install was
 * reverted, with whether it is tried again, or that it is broken and needs
 * attention now, with what to do; the notice adds the install's log, which
 * says where it stopped.
 *
 * Raised once (NotificationManager::notify(), source auto_update): for a
 * reverted install only once it is not tried again
 * (install_failed:<version>), and for a broken one at once
 * (install_broken:<version>:<step>).
 *
 * A failure is recorded before OWA can say so: by the copy, in the process
 * that may load nothing more; by the next run of cli.php putting the
 * installed release back, before OWA loads; or by Finish, when one of its
 * steps fails. The next booted run of cli.php raises it (fromDisk()), and so
 * does the release check.
 */
class InstallNotices {

    /**
     * Raise an automatic update's notice, its body without the install's
     * paths (withoutPaths()): notices go to the bell and are e-mailed.
     *
     * @param  array<string, mixed> $notice NotificationManager::notify()'s
     * @return mixed its answer
     */
    public static function send( array $notice ) {

        return \OWA\Module\Base\Classes\NotificationManager::notify(
            [ 'body' => self::withoutPaths( (string) ( $notice['body'] ?? '' ) ) ] + $notice );
    }

    /**
     * $text with paths in the OWA directory given relative to it, and paths in
     * a data directory elsewhere relative to "the data directory".
     *
     * @param  string      $text
     * @param  string|null $root a test's; else OWA's
     * @param  string|null $data a test's; else OWA's
     * @return string
     */
    public static function withoutPaths( $text, $root = null, $data = null ) {

        $root = rtrim( $root ?? \OWA\Core\Env::root(), '/' );
        $data = rtrim( $data ?? \OWA\Core\Env::dataDir(), '/' );
        $map  = [ $root . '/' => '', $root => CoreAPI::t( 'the OWA directory' ) ];

        if ( strpos( $data . '/', $root . '/' ) !== 0 ) {

            $map += [ $data . '/' => CoreAPI::t( 'the data directory' ) . '/', $data => CoreAPI::t( 'the data directory' ) ];
        }

        // strtr() replaces the longest match first: the data directory inside a path before the root.
        return strtr( (string) $text, $map );
    }

    /**
     * What $failure says: its title, its lines (what failed and why; then
     * reverted or broken, and what happens or what to do), its log, and how
     * it is raised.
     *
     * @param  array<string, mixed> $failure last-failure.json
     * @return array{title: string, lines: list<string>, log: list<string>, broken: bool, notify: bool, key: string, severity: string}
     */
    public static function describe( array $failure ) {

        $v      = (string) ( $failure['version'] ?? '' );
        $from   = (string) ( $failure['from'] ?? '' );
        $error  = rtrim( trim( (string) ( $failure['error'] ?? '' ) ), '.' ) . '.';
        $broken = ! empty( $failure['booted'] );
        $retry  = (int) ( $failure['retry_after'] ?? 0 );
        $step   = (string) ( $failure['step'] ?? '' ) ?: 'schema';

        if ( $broken ) {

            $where = [
                'schema'    => CoreAPI::t( 'Its schema updates did not complete' ),
                'trackers'  => CoreAPI::t( 'Republishing its tracking bundles failed' ),
                'self_test' => CoreAPI::t( 'Its own release check failed' ),
            ][ $step ] ?? CoreAPI::t( 'Its schema updates did not complete' );

            $state = [
                'schema'    => CoreAPI::t( 'The install is broken and needs attention now: OWA %1$s\'s files are installed, and until its schema updates complete, tracking is queued and reports wait. Fix the cause, then run php cli.php cmd=core-update-schema, which finishes the install.' ),
                'trackers'  => CoreAPI::t( 'The install is broken and needs attention now: OWA %1$s runs, and its tracking bundles were not republished. Fix the cause, then run php cli.php cmd=core-update-schema, which finishes the install.' ),
                'self_test' => CoreAPI::t( 'The install is broken and needs attention now: OWA %1$s runs, but it may not install the release that fixes this. Install that release by hand once it is out: php cli.php cmd=core-install --version=<version>' ),
            ][ $step ] ?? null;

            $lines = [ $where . ': ' . $error, sprintf( $state ?? CoreAPI::t( 'The install is broken and needs attention now: OWA %1$s\'s files are installed, and until its schema updates complete, tracking is queued and reports wait. Fix the cause, then run php cli.php cmd=core-update-schema, which finishes the install.' ), $v ) ];

        } elseif ( $retry > 0 ) {

            $lines = [ $error, sprintf( CoreAPI::t( 'The install was reverted: OWA %1$s runs as before. It is tried again after %2$s UTC, %3$d attempts in all. To try it now: php cli.php cmd=core-install --version=%4$s' ),
                $from, gmdate( 'Y-m-d H:i', $retry ), (int) Downloads::updater()['retries'], $v ) ];

        } else {

            $lines = [ $error, sprintf( CoreAPI::t( 'The install was reverted: OWA %1$s runs as before. It is not tried again: automatic updates skip OWA %2$s until a newer release. To try it again: php cli.php cmd=core-install --version=%2$s' ),
                $from, $v ) ];
        }

        return [
            'title'    => sprintf( CoreAPI::t( 'Installing OWA %s failed' ), $v ),
            'lines'    => $lines,
            'log'      => array_values( array_map( 'strval', (array) ( $failure['log'] ?? [] ) ) ),
            'broken'   => $broken,
            // A reverted install once it is not tried again; a broken one at once.
            'notify'   => $broken || $retry === 0,
            'key'      => $broken ? sprintf( 'install_broken:%s:%s', $v, $step ) : 'install_failed:' . $v,
            'severity' => $broken ? 'critical' : 'warning',
        ];
    }

    /**
     * @param  array<string, mixed>                  $failure last-failure.json
     * @param  callable(array<string, mixed>): mixed $notify  NotificationManager::notify() or a stand-in
     * @return void
     */
    public static function forFailure( array $failure, callable $notify ) {

        if ( (string) ( $failure['version'] ?? '' ) === '' || trim( (string) ( $failure['error'] ?? '' ) ) === '' ) {

            return;
        }

        $d = self::describe( $failure );

        if ( ! $d['notify'] ) {

            return;
        }

        $notify( [
            'source'     => ReleaseCheck::SOURCE,
            'source_key' => $d['key'],
            'title'      => $d['title'],
            'body'       => implode( "\n\n", $d['lines'] ) . ( $d['log'] ? "\n\n" . CoreAPI::t( 'What the install did:' ) . "\n" . implode( "\n", $d['log'] ) : '' ),
            'severity'   => $d['severity'],
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

                self::forFailure( $failure, [ self::class, 'send' ] );
            }

        } catch ( \Throwable $e ) {

            CoreAPI::debug( 'Install notices: ' . $e->getMessage() );
        }
    }
}
