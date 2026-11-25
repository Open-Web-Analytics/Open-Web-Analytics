<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * What stops a release before anything changes: the releases cannot be
 * listed, a download fails, a requirement is not met, a file automatic
 * updates keep belongs to another user, the CLI's PHP cannot write its
 * temporary directory, or the install is refused before the copy. Nothing
 * is undone, and nothing is given up: the release check keeps checking, and
 * the release installs once it is fixed.
 *
 * Kept while it lasts in base.auto_update_problem (ReleaseCheck reads and
 * writes it), from the first check that finds it, so System Health, the
 * Updates screen and cmd=core-update-status show it from then on. It is a
 * notice and an e-mail once it has lasted NOTICE_AFTER, as long as three
 * checks six hours apart, the copy's own retries (updater.php `retries`,
 * `retry_wait`); once for each time it starts. A check that gets past it
 * clears it, except that a run that did not list the releases cannot say a
 * listing or a download works again.
 */
class Problem {

    /** The setting that keeps it. */
    const SETTING = 'auto_update_problem';

    /** How long it lasts before it is a notice: three checks, six hours apart. */
    const NOTICE_AFTER = 43200;

    /** The stages, by the release check's outcome. A failed download and a refused install share theirs, and say which. */
    const STAGES = [
        ReleaseCheck::UNREACHED => 'listing',
        ReleaseCheck::FAILED    => 'download',
        ReleaseCheck::UNMET     => 'requirements',
        ReleaseCheck::FOREIGN   => 'files',
        ReleaseCheck::NO_TEMP   => 'temp',
    ];

    /** The stages only a run that lists the releases tries again. */
    const LISTING = [ 'listing', 'download' ];

    /**
     * The one kept, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function stored() {

        $p = CoreAPI::getSetting( 'base', self::SETTING );

        return is_array( $p ) && ! empty( $p['stage'] ) ? $p : null;
    }

    /**
     * Keep $p, or clear it with null.
     *
     * @param  array<string, mixed>|null $p
     * @return void
     */
    public static function store( $p ) {

        CoreAPI::configSingleton()->persistSetting( 'base', self::SETTING, $p ?? [] );
    }

    /**
     * The problem a check found, carried on from $was when it is the same
     * stage and release: when it started, and whether it was noticed.
     *
     * @param  array<string, mixed>|null $was
     * @param  string                    $stage
     * @param  string|null               $v         the release it stops, when there is one
     * @param  string                    $installed
     * @param  string                    $error     what failed, and why
     * @param  list<string>              $log       what the check did
     * @param  int                       $now
     * @return array<string, mixed>
     */
    public static function next( $was, $stage, $v, $installed, $error, array $log, $now ) {

        $same = is_array( $was ) && ( $was['stage'] ?? null ) === $stage && ( $was['version'] ?? null ) === $v;

        return [
            'stage'    => (string) $stage,
            'version'  => $v === null ? null : (string) $v,
            'from'     => (string) $installed,
            'error'    => (string) $error,
            'first_at' => $same ? (int) $was['first_at'] : (int) $now,
            'at'       => (int) $now,
            'noticed'  => $same && ! empty( $was['noticed'] ),
            'log'      => array_slice( array_values( $log ), -100 ),
        ];
    }

    /**
     * What $p says, wherever it is shown, and whether it is to be noticed now.
     *
     * @param  array<string, mixed> $p
     * @return array{title: string, lines: list<string>, log: list<string>, notify: bool, key: string}
     */
    public static function describe( array $p ) {

        $v     = (string) ( $p['version'] ?? '' );
        $stage = (string) ( $p['stage'] ?? '' );
        $error = rtrim( trim( (string) ( $p['error'] ?? '' ) ), '.' ) . '.';

        $where = [
            'listing'      => CoreAPI::t( 'The releases could not be listed' ),
            'download'     => sprintf( CoreAPI::t( 'Downloading OWA %s failed' ), $v ),
            'requirements' => sprintf( CoreAPI::t( 'OWA %s\'s requirements are not met' ), $v ),
            'files'        => CoreAPI::t( 'Files automatic updates keep belong to another user' ),
            'temp'         => CoreAPI::t( 'The CLI\'s PHP cannot write its temporary directory' ),
            'prepare'      => sprintf( CoreAPI::t( 'Installing OWA %s was refused before anything changed' ), $v ),
        ][ $stage ] ?? CoreAPI::t( 'Automatic updates failed' );

        $state = sprintf( CoreAPI::t( 'Nothing was changed: OWA %1$s runs as before. It has failed since %2$s UTC. Automatic updates keep %3$s, and install %4$s once this is fixed.' ),
            (string) ( $p['from'] ?? '' ), gmdate( 'Y-m-d H:i', (int) ( $p['first_at'] ?? 0 ) ),
            in_array( $stage, self::LISTING, true ) ? CoreAPI::t( 'trying four times a day' ) : CoreAPI::t( 'checking every hour' ),
            $v !== '' ? sprintf( CoreAPI::t( 'OWA %s' ), $v ) : CoreAPI::t( 'the next release' ) );

        return [
            'title'  => $v !== '' && ! in_array( $stage, [ 'listing', 'files', 'temp' ], true )
                ? sprintf( CoreAPI::t( 'OWA %s cannot be installed yet' ), $v )
                : CoreAPI::t( 'Automatic updates cannot run' ),
            'lines'  => [ $where . ': ' . $error, $state ],
            'log'    => array_values( array_map( 'strval', (array) ( $p['log'] ?? [] ) ) ),
            'notify' => empty( $p['noticed'] ) && (int) ( $p['at'] ?? 0 ) - (int) ( $p['first_at'] ?? 0 ) >= self::NOTICE_AFTER,
            'key'    => sprintf( 'cannot_install:%s:%s:%d', $stage, $v, (int) ( $p['first_at'] ?? 0 ) ),
        ];
    }

    /**
     * The notice for $p, by $notify.
     *
     * @param  array<string, mixed>                  $p
     * @param  callable(array<string, mixed>): mixed $notify
     * @return void
     */
    public static function notify( array $p, callable $notify ) {

        $d = self::describe( $p );

        $notify( [
            'source'     => ReleaseCheck::SOURCE,
            'source_key' => $d['key'],
            'title'      => $d['title'],
            'body'       => implode( "\n\n", $d['lines'] ) . ( $d['log'] ? "\n\n" . CoreAPI::t( 'What the check did:' ) . "\n" . implode( "\n", $d['log'] ) : '' ),
            'severity'   => 'warning',
            'email'      => true,
        ] );
    }
}
