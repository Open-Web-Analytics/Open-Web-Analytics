<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * Step 1: release check. Finds the release this installation updates to,
 * stages it, judges it, and points staged.json at it when it can be
 * installed. Installs nothing: Steps 2-4 read staged.json.
 *
 * In order, stopping at the first that applies:
 *   1. Not a full server role, or a version control checkout: nothing is
 *      staged, and staged.json is removed.
 *   2. Automatic updates off: staged.json removed.
 *   3. One listing of the releases (Releases::choose()). When it cannot be
 *      fetched, or names nothing newer, staged.json is only repaired: removed
 *      when it names a version already installed or a staging that is gone.
 *   4. A staging the installer has claimed (install.json) is the installer's:
 *      left as it is.
 *   5. Downloaded, verified and unpacked once (Staging::stage()); a failure
 *      deletes it, and the next run tries again.
 *   6. The preflight, every run, from the staging on disk. Its result goes in
 *      stage.json. Unmet: staged.json removed; the staging stays, to be
 *      judged again next run.
 *   7. Met: staged.json points at it, with the window it may start in. A
 *      release it pointed at before is deleted unless the installer claimed it.
 *
 * Every run first deletes stagings for versions already installed. Writes to
 * staged.json and deletions happen holding the install lock, taken without
 * waiting; when an install holds it, the run changes nothing.
 *
 * @phpstan-import-type Release from Releases
 * @phpstan-type Outcome array{outcome: string, version: string|null, lines: list<string>, problems: list<string>}
 */
class ReleaseCheck {

    const ROLE      = 'not_full_role';
    const CHECKOUT  = 'vcs_checkout';
    const OFF       = 'auto_update_off';
    const UNKNOWN   = 'unknown_version';
    const UNREACHED = 'listing_failed';
    const CURRENT   = 'up_to_date';
    const CLAIMED   = 'installing';
    const FAILED    = 'staging_failed';
    const UNMET     = 'requirements_unmet';
    const STAGED    = 'staged';
    const BUSY      = 'install_running';
    const CHOSEN    = 'chosen';

    const DAYS = [ 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 ];

    /** @var Staging */
    private $staging;

    /** @var string */
    private $root;

    /** @var list<string> */
    private $lines = [];

    /** @var array<string, mixed> settings and facts a test replaces */
    public $context;

    /**
     * @param Staging|null         $staging
     * @param array<string, mixed> $context in place of what is read from the installation, for a test
     */
    public function __construct( $staging = null, array $context = [] ) {

        $this->staging = $staging ?? new Staging();
        $this->context = $context + [
            'root'      => \OWA\Core\Env::root(),
            'installed' => \OWA\Core\Env::version(),
            'role'      => \OWA\Core\ServerRole::current()->name(),
            'enabled'   => (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'auto_update' ),
            'level'     => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'auto_update_level' ),
            'days'      => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'auto_update_days' ),
            'window'    => \OWA\Core\CoreAPI::getSetting( 'base', 'auto_update_window' ),
            'immediate' => (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'auto_update_patch_immediately' ),
            'timezone'  => (string) ( \OWA\Core\CoreAPI::getSetting( 'base', 'timezone' ) ?: 'UTC' ),
            'url'       => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'auto_update_releases_url' ),
            'releases'  => null,
            'env'       => null,
        ];
        $this->root = (string) $this->context['root'];
    }

    /**
     * @param  bool $dry_run choose only: nothing downloaded or written
     * @return Outcome
     */
    public function run( $dry_run = false ) {

        $installed = (string) $this->context['installed'];

        if ( $this->context['role'] !== \OWA\Core\ServerRole::FULL ) {

            return $this->end( self::ROLE, null, sprintf( 'This server\'s role is %s; automatic updates run on a full server only.', $this->context['role'] ), $dry_run );
        }

        $checkout = Vcs::checkout( $this->root );

        if ( $checkout !== null ) {

            return $this->end( self::CHECKOUT, null, sprintf( 'OWA runs from a version control checkout (%s): it is updated with that, never automatically.', $checkout ), $dry_run );
        }

        if ( Version::parse( $installed ) === null ) {

            return $this->end( self::UNKNOWN, null, sprintf( 'This installation\'s version, %s, is not a release version.', $installed ), $dry_run );
        }

        if ( ! $dry_run && ! $this->clearInstalled( $installed ) ) {

            return $this->busy();
        }

        if ( ! $this->context['enabled'] ) {

            return $this->end( self::OFF, null, 'Automatic updates are off.', $dry_run );
        }

        try {

            $list = $this->context['releases'] ?? Releases::fetch( (string) $this->context['url'] );

        } catch ( \RuntimeException $e ) {

            return $this->repair( self::UNREACHED, $e->getMessage(), $dry_run );
        }

        $level   = $this->context['level'] === Version::PATCH ? Version::PATCH : Version::MINOR;
        $release = Releases::choose( $list, $installed, $level );

        if ( $release === null ) {

            return $this->repair( self::CURRENT, sprintf( 'No release newer than %s for automatic %s updates.', $installed,
                $level === Version::PATCH ? 'patch' : 'patch and minor' ), $dry_run );
        }

        $v = $release['version'];
        $this->lines[] = sprintf( 'The release to update to is %s (a %s release): %s', $v, $release['step'], $release['url'] );

        if ( $dry_run ) {

            return $this->outcome( self::CHOSEN, $v );
        }

        if ( $this->staging->hasInstall( $v ) ) {

            return $this->outcome( self::CLAIMED, $v, [], sprintf( '%s is being installed; its staging is the installer\'s.', $v ) );
        }

        if ( ! $this->staging->isStaged( $v ) ) {

            try {

                $this->staging->stage( $release );
                $this->lines[] = sprintf( 'Downloaded %s, checked it against its SHA-256 and every file against its manifest.', $v );

            } catch ( \RuntimeException $e ) {

                return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Staging %s failed; the next run tries again.', $v ) );
            }
        }

        $problems = $this->preflight( $v );
        $info     = (array) $this->staging->stageInfo( $v );
        $info['preflight'] = [ 'checked_at' => time(), 'passed' => ! $problems, 'problems' => $problems ];
        $this->staging->writeStageInfo( $v, $info );

        if ( $problems ) {

            $removed = InstallLock::tryExclusive( fn () => $this->staging->removeStaged() );

            return $removed
                ? $this->outcome( self::UNMET, $v, $problems, sprintf( '%s cannot be installed here yet; it is judged again at the next run.', $v ) )
                : $this->busy();
        }

        $window = $this->window( $release['step'] );
        $before = $this->staging->staged();
        $wrote  = InstallLock::tryExclusive( function () use ( $v, $window, $before ) {

            if ( ( $before['version'] ?? null ) === $v ) {

                return;
            }

            $this->staging->writeStaged( $v, $window );

            $old = (string) ( $before['version'] ?? '' );

            if ( $old !== '' && $old !== $v && ! $this->staging->hasInstall( $old ) ) {

                Staging::remove( $this->staging->dir( $old ) );
                $this->lines[] = sprintf( 'Deleted the staging of %s, which %s replaces.', $old, $v );
            }
        } );

        if ( ! $wrote ) {

            return $this->busy();
        }

        $staged = $this->staging->staged();

        return $this->outcome( self::STAGED, $v, [], ( $before['version'] ?? null ) === $v
            ? sprintf( '%s is staged, as it was.', $v )
            : sprintf( '%s is staged to install %s.', $v, self::describeWindow( $staged['window'] ?? null ) ) );
    }

    /**
     * @param  string $v
     * @return list<string>
     */
    private function preflight( $v ) {

        $release  = (array) $this->staging->releaseInfo( $v );
        $req      = (array) ( $release['requirements'] ?? [] );
        $manifest = (array) $this->staging->readJson( $this->staging->releaseDir( $v ) . '/' . \OWA\Core\ReleaseManifest::FILE );
        $env      = $this->context['env'] ?? Preflight::environment( $req );

        return array_merge( Preflight::requirements( $req, $env ), Preflight::install( $this->staging->dir( $v ), $manifest, $this->root ) );
    }

    /**
     * The window a release may start in, from the settings; null when it may
     * start at once (a patch while patches install immediately, or no window).
     *
     * @param  string $step
     * @return array{days: list<int>, start: string, end: string, timezone: string}|null
     */
    public function window( $step ) {

        $window = (string) ( $this->context['window'] ?: '' );

        if ( ( $step === Version::PATCH && $this->context['immediate'] )
                || ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])$/', $window ) ) {

            return null;
        }

        [ $start, $end ] = explode( '-', $window );
        $days = [];

        foreach ( preg_split( '/\s*,\s*/', strtolower( trim( (string) $this->context['days'] ) ) ) ?: [] as $day ) {

            if ( isset( self::DAYS[ $day ] ) ) {

                $days[] = self::DAYS[ $day ];
            }
        }

        $days = $days ? array_values( array_unique( $days ) ) : array_values( self::DAYS );
        sort( $days );

        return [ 'days' => $days, 'start' => $start, 'end' => $end, 'timezone' => (string) $this->context['timezone'] ];
    }

    /**
     * @param  array<string, mixed>|null $window
     * @return string
     */
    public static function describeWindow( $window ) {

        if ( ! $window ) {

            return 'at the next scheduler run';
        }

        $names = array_flip( self::DAYS );
        $days  = count( (array) $window['days'] ) === 7 ? 'any day' : implode( ', ', array_map( fn ( $d ) => ucfirst( $names[ (int) $d ] ), (array) $window['days'] ) );

        return sprintf( 'between %s and %s (%s), %s', $window['start'], $window['end'], $window['timezone'], $days );
    }

    /**
     * Delete the stagings of versions already installed, and staged.json if
     * it names one: what an interrupted clean-up after an install leaves.
     *
     * @param  string $installed
     * @return bool false when an install holds the lock
     */
    private function clearInstalled( $installed ) {

        $done = array_values( array_filter( $this->staging->versions(), fn ( $v ) => Version::compare( $v, $installed ) <= 0 ) );
        $named = (string) ( $this->staging->staged()['version'] ?? '' );

        if ( ! $done && ( $named === '' || Version::compare( $named, $installed ) > 0 ) ) {

            return true;
        }

        return InstallLock::tryExclusive( function () use ( $done, $named, $installed ) {

            foreach ( $done as $v ) {

                Staging::remove( $this->staging->dir( $v ) );
                $this->lines[] = sprintf( 'Deleted the staging of %s, which is installed.', $v );
            }

            if ( $named !== '' && Version::compare( $named, $installed ) <= 0 ) {

                $this->staging->removeStaged();
            }
        } );
    }

    /**
     * When no release is chosen: keep staged.json only if it names a staged
     * release newer than this one.
     *
     * @param  string $outcome
     * @param  string $why
     * @param  bool   $dry_run
     * @return Outcome
     */
    private function repair( $outcome, $why, $dry_run ) {

        $staged = $this->staging->staged();
        $v      = $staged['version'] ?? null;

        if ( ! $dry_run && $v !== null && ! $this->staging->isStaged( (string) $v ) ) {

            if ( ! InstallLock::tryExclusive( fn () => $this->staging->removeStaged() ) ) {

                return $this->busy();
            }

            $this->lines[] = sprintf( 'Removed staged.json: it named %s, whose staging is gone.', $v );
            $v = null;
        }

        return $this->outcome( $outcome, $v, $outcome === self::UNREACHED ? [ $why ] : [], $why );
    }

    /**
     * An outcome that ends automatic updates here: staged.json goes.
     *
     * @param  string      $outcome
     * @param  string|null $v
     * @param  string      $why
     * @param  bool        $dry_run
     * @return Outcome
     */
    private function end( $outcome, $v, $why, $dry_run ) {

        if ( ! $dry_run && $this->staging->staged() !== null
                && ! InstallLock::tryExclusive( fn () => $this->staging->removeStaged() ) ) {

            return $this->busy();
        }

        return $this->outcome( $outcome, $v, [], $why );
    }

    /** @return Outcome */
    private function busy() {

        return $this->outcome( self::BUSY, null, [], 'An install is running; this run changed nothing.' );
    }

    /**
     * @param  string       $outcome
     * @param  string|null  $v
     * @param  list<string> $problems
     * @param  string|null  $line
     * @return Outcome
     */
    private function outcome( $outcome, $v, array $problems = [], $line = null ) {

        if ( $line !== null ) {

            $this->lines[] = $line;
        }

        foreach ( $problems as $p ) {

            $this->lines[] = '  ' . $p;
        }

        return [ 'outcome' => $outcome, 'version' => $v, 'lines' => $this->lines, 'problems' => $problems ];
    }
}
