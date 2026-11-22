<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

use OWA\Core\CoreAPI;

/**
 * Step 1: release check. Finds the release this installation updates to,
 * stages it, judges it, and points staged.json at it when it can be
 * installed. Installs nothing: Steps 2-4 read staged.json.
 *
 * One listing of the releases feeds the notifications bell first, on every
 * run whatever follows (the job cmd=fetch-notifications used to be). Then, in
 * order, stopping at the first that applies:
 *   1. Not a full server role, or a version control checkout: nothing is
 *      staged, and staged.json is removed.
 *   2. Automatic updates off: staged.json removed.
 *   3. When the listing failed, or names nothing newer (Releases::choose()),
 *      staged.json is only repaired: removed when it names a staging that is
 *      gone.
 *   4. A staging an unfinished install uses (install/install.json) is the
 *      installer's: left as it is.
 *   5. Downloaded, checked against its SHA-256 and unpacked once
 *      (Staging::stage()), and again if GitHub's digest for it changes (the
 *      asset was replaced; staged.json records the SHA-256 it staged). A
 *      failure deletes it, and the next run tries again. The first failure
 *      puts a notice in the bell; a week of them e-mails the admins.
 *   6. The preflight, every run, from the staging on disk. Unmet:
 *      staged.json removed and the admins told, once per version and set of
 *      problems; the staging stays, to be judged again.
 *   7. An update this installation would run that must be installed by hand
 *      ($manual_install): staged.json removed and the admins told, once.
 *   8. Otherwise staged.json points at it, with the window it may start in,
 *      and the tarball's SHA-256, and the admins are told when it installs.
 *      A release it pointed at before is deleted unless an unfinished
 *      install uses it.
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
    const MANUAL    = 'manual_install';
    const STAGED    = 'staged';
    const BUSY      = 'install_running';
    const CHOSEN    = 'chosen';

    /** The notices' source (NotificationManager). */
    const SOURCE = 'auto_update';

    /** How long a staging may keep failing before the admins are e-mailed. */
    const FAILING_FOR = 604800;

    const DAYS = [ 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 ];

    /** @var Staging */
    private $staging;

    /** @var string */
    private $root;

    /** @var list<string> */
    private $lines = [];

    /** @var array<string, mixed> settings, facts and services a test replaces */
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
            'enabled'   => (bool) CoreAPI::getSetting( 'base', 'auto_update' ),
            'level'     => (string) CoreAPI::getSetting( 'base', 'auto_update_level' ),
            'days'      => (string) CoreAPI::getSetting( 'base', 'auto_update_days' ),
            'window'    => CoreAPI::getSetting( 'base', 'auto_update_window' ),
            'immediate' => (bool) CoreAPI::getSetting( 'base', 'auto_update_patch_immediately' ),
            'timezone'  => (string) ( CoreAPI::getSetting( 'base', 'timezone' ) ?: 'UTC' ),
            'url'       => (string) CoreAPI::getSetting( 'base', 'auto_update_releases_url' ),
            'releases'  => null,
            'env'       => null,
            // The services: the bell feed, a notice, when one was first
            // raised, and a module's stored schema version.
            'feed'      => static function ( array $list ) {

                return \OWA\Module\Base\Classes\NotificationManager::record(
                    \OWA\Module\Base\Classes\NotificationManager::fromGithubReleases( $list ),
                    \OWA\Module\Base\Classes\NotificationManager::SOURCE_GITHUB, '',
                    \OWA\Module\Base\Classes\NotificationManager::TYPE_RELEASE );
            },
            'notify'    => static fn ( array $notice ) => \OWA\Module\Base\Classes\NotificationManager::notify( $notice ),
            'raised_at' => static fn ( $key ) => \OWA\Module\Base\Classes\NotificationManager::raisedAt( self::SOURCE, $key ),
            'schema'    => static function ( $module ) {

                $v = CoreAPI::getSetting( $module, 'schema_version' );

                return $v === null || $v === '' || $v === false ? null : (int) $v;
            },
            'now'       => time(),
        ];
        $this->root = (string) $this->context['root'];
    }

    /**
     * @param  bool $dry_run choose only: nothing downloaded, written or announced
     * @return Outcome
     */
    public function run( $dry_run = false ) {

        $installed = (string) $this->context['installed'];
        $list      = null;
        $error     = '';

        try {

            $list = $this->context['releases'] ?? Releases::fetch( (string) $this->context['url'] );

        } catch ( \RuntimeException $e ) {

            $error = $e->getMessage();
        }

        if ( $list !== null && ! $dry_run ) {

            $created = (int) ( $this->context['feed'] )( $list );
            $this->lines[] = sprintf( '%d new release notification%s.', $created, $created === 1 ? '' : 's' );
        }

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

        // An install that failed and was restored left only the old release
        // to say so; the notices are raised once each (InstallNotices).
        if ( ! $dry_run ) {

            $install = $this->staging->install();

            if ( $install ) {

                InstallNotices::forInstall( $install, $this->context['notify'] );
            }
        }

        if ( ! $this->context['enabled'] ) {

            return $this->end( self::OFF, null, 'Automatic updates are off.', $dry_run );
        }

        if ( $list === null ) {

            return $this->repair( self::UNREACHED, $error, $dry_run );
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

        // The asset GitHub lists now is not the one staged: it was replaced.
        // staged.json goes first, so nothing installs the staging being redone.
        $before   = $this->staging->staged();
        $replaced = $this->staging->isStaged( $v ) && ( $before['version'] ?? null ) === $v
            && $release['sha256'] !== null && ( $before['sha256'] ?? null ) !== $release['sha256'];

        if ( $replaced && ! InstallLock::tryExclusive( fn () => $this->staging->removeStaged() ) ) {

            return $this->busy();
        }

        $sha = ( $before['version'] ?? null ) === $v && ! $replaced ? (string) ( $before['sha256'] ?? '' ) : (string) ( $release['sha256'] ?? '' );

        if ( $replaced || ! $this->staging->isStaged( $v ) ) {

            try {

                $sha = $this->staging->stage( $release );
                $this->lines[] = sprintf( 'Downloaded %s and checked it against its SHA-256%s.', $v, $replaced ? ', as the release\'s asset was replaced' : '' );

            } catch ( \RuntimeException $e ) {

                $this->stagingFailed( $v, $e->getMessage(), $release['url'] );

                return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Staging %s failed; the next run tries again.', $v ) );
            }

            $before = $this->staging->staged();
        }

        $problems = $this->preflight( $v );
        $manual   = $problems ? [] : $this->manualInstall( $v );

        if ( $problems || $manual ) {

            if ( ! InstallLock::tryExclusive( fn () => $this->staging->removeStaged() ) ) {

                return $this->busy();
            }

            if ( $problems ) {

                $this->notify( sprintf( 'unmet:%s:%s', $v, substr( md5( implode( "\n", $problems ) ), 0, 12 ) ), 'warning',
                    sprintf( CoreAPI::t( 'OWA %s cannot be installed automatically yet' ), $v ),
                    implode( "\n", $problems ) . "\n\n" . CoreAPI::t( 'It is checked again every six hours, and installs once these are fixed.' ),
                    $release['url'] );

                return $this->outcome( self::UNMET, $v, $problems, sprintf( '%s cannot be installed here yet; it is judged again at the next run.', $v ) );
            }

            $texts = array_map( static fn ( $u ) => sprintf( '%s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] ), $manual );

            $this->notify( 'manual:' . $v, 'warning',
                sprintf( CoreAPI::t( 'OWA %s needs to be installed by hand' ), $v ),
                implode( "\n", $texts ) . "\n\n" . sprintf( CoreAPI::t( 'It is downloaded and checked. Take the backup each update names, then install it: php cli.php cmd=self-update-install version=%s' ), $v ),
                $release['url'] );

            return $this->outcome( self::MANUAL, $v, $texts, sprintf( '%s has updates that must be installed by hand; it is staged but will not install itself.', $v ) );
        }

        $window = $this->window( $release['step'] );
        $wrote  = InstallLock::tryExclusive( function () use ( $v, $window, $before, $sha ) {

            if ( ( $before['version'] ?? null ) === $v ) {

                return;
            }

            $this->staging->writeStaged( $v, $window, $sha );

            $old = (string) ( $before['version'] ?? '' );

            if ( $old !== '' && $old !== $v && ! $this->staging->hasInstall( $old ) ) {

                Staging::remove( $this->staging->dir( $old ) );
                $this->lines[] = sprintf( 'Deleted the staging of %s, which %s replaces.', $old, $v );
            }
        } );

        if ( ! $wrote ) {

            return $this->busy();
        }

        if ( ( $before['version'] ?? null ) === $v ) {

            return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged, as it was.', $v ) );
        }

        $when = self::describeWindow( $window );

        $this->notify( 'staged:' . $v, 'info',
            sprintf( CoreAPI::t( 'OWA %1$s will be installed %2$s' ), $v, $when ),
            sprintf( CoreAPI::t( 'Automatic updates downloaded and checked OWA %1$s, a %2$s release. To stop it: php cli.php cmd=self-update-cancel' ), $v, $release['step'] ),
            $release['url'] );

        return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged to install %s.', $v, $when ) );
    }

    /**
     * cmd=self-update-stage: stage one named release by hand, as Step 1 would,
     * and judge it; staged.json is not touched, so it installs only when an
     * operator installs it (cmd=self-update-install). Any version in this
     * major the listing has, older and the installed one included; a major
     * release is a migration, done by hand. Nothing is announced: the operator
     * is reading the output.
     *
     * For an older release, the modules whose stored schema is newer than it
     * expects are listed with the rollback commands to run first, newest first:
     * older code cannot run a newer schema.
     *
     * @param  string $version
     * @return Outcome
     */
    public function stageByHand( $version ) {

        $installed = (string) $this->context['installed'];
        $checkout  = Vcs::checkout( $this->root );

        if ( $checkout !== null ) {

            return $this->outcome( self::CHECKOUT, null, [], sprintf( 'OWA runs from a version control checkout (%s): update it with that.', $checkout ) );
        }

        if ( Version::parse( $installed ) === null || Version::parse( (string) $version ) === null ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( 'Both versions must be release versions: installed %s, asked for %s.', $installed, $version ) );
        }

        if ( Version::step( $installed, $version ) === Version::MAJOR || Version::step( $version, $installed ) === Version::MAJOR ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( '%s is another major version: that is a migration, done by hand from its release notes.', $version ) );
        }

        try {

            $list = $this->context['releases'] ?? Releases::fetch( (string) $this->context['url'] );

        } catch ( \RuntimeException $e ) {

            return $this->outcome( self::UNREACHED, null, [ $e->getMessage() ], 'The releases could not be listed.' );
        }

        $release = Releases::find( $list, $version, $installed );

        if ( $release === null ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( 'There is no published release %s with its tarball.', $version ) );
        }

        $v = $release['version'];

        if ( InstallLock::isHeld() ) {

            return $this->busy();
        }

        if ( $this->staging->hasInstall( $v ) ) {

            return $this->outcome( self::CLAIMED, $v, [], sprintf( 'An install of %1$s is unfinished; its staging is the installer\'s. Carry it on with: php cli.php cmd=self-update-install version=%1$s', $v ) );
        }

        if ( ! $this->staging->isStaged( $v ) ) {

            try {

                $this->staging->stage( $release );
                $this->lines[] = sprintf( 'Downloaded %s and checked it against its SHA-256.', $v );

            } catch ( \Throwable $e ) {

                return $this->outcome( self::FAILED, $v, [ $e->getMessage() ], sprintf( 'Staging %s failed.', $v ) );
            }

        } else {

            $this->lines[] = sprintf( '%s was already downloaded and checked.', $v );
        }

        $problems = $this->preflight( $v );
        $manual   = $this->manualInstall( $v );
        $rollback = $this->rollbackFirst( $v );

        $step = Version::step( $installed, $v );
        $this->lines[] = $step === null
            ? sprintf( '%s is not newer than the installed %s: installing it goes back.', $v, $installed )
            : sprintf( '%s is a %s release after the installed %s.', $v, $step, $installed );

        foreach ( $manual as $u ) {

            $this->lines[] = sprintf( 'Read before installing -- %s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] );
        }

        if ( $rollback ) {

            return $this->outcome( self::UNMET, $v, $rollback, sprintf( 'This database is newer than %s expects. Undo these updates first, newest first, with the code installed now:', $v ) );
        }

        if ( $problems ) {

            return $this->outcome( self::UNMET, $v, $problems, sprintf( '%s cannot be installed here:', $v ) );
        }

        return $this->outcome( self::STAGED, $v, [], sprintf( '%s is staged. Install it with: php cli.php cmd=self-update-install version=%s', $v, $v ) );
    }

    /**
     * cmd=self-update-cancel. Without a version: staged.json goes, so nothing
     * installs until the next release check stages it again (unless automatic
     * updates are turned off). With one: that release's staging goes, its
     * install with it if one is failing, and staged.json if it names it.
     * Refused while an install runs.
     *
     * @param  string|null $version
     * @return Outcome
     */
    public function cancel( $version = null ) {

        $staged = $this->staging->staged();

        if ( $version === null ) {

            if ( $staged === null ) {

                return $this->outcome( self::CURRENT, null, [], 'Nothing is staged to install.' );
            }

            if ( ! InstallLock::tryExclusive( fn () => $this->staging->removeStaged() ) ) {

                return $this->busy();
            }

            return $this->outcome( self::OFF, (string) $staged['version'], [], sprintf( 'Cancelled the automatic install of %s.%s', $staged['version'],
                $this->context['enabled'] ? ' The next release check stages it again; set OWA_AUTO_UPDATE to false to stop that.' : '' ) );
        }

        if ( Version::parse( (string) $version ) === null || $this->staging->root() === '' || ! is_dir( $this->staging->dir( (string) $version ) ) ) {

            return $this->outcome( self::UNKNOWN, null, [], sprintf( 'Nothing of %s is staged.', $version ) );
        }

        // Its live tree may be half copied: only carrying the install on, or its own undo, settles it.
        if ( $this->staging->hasInstall( (string) $version ) ) {

            return $this->outcome( self::CLAIMED, (string) $version, [], sprintf( 'An install of %1$s is unfinished; it cannot be cancelled. Carry it on with: php cli.php cmd=self-update-install version=%1$s', $version ) );
        }

        $done = InstallLock::tryExclusive( function () use ( $version, $staged ) {

            if ( ( $staged['version'] ?? null ) === $version ) {

                $this->staging->removeStaged();
            }

            Staging::remove( $this->staging->dir( (string) $version ) );
        } );

        return $done
            ? $this->outcome( self::OFF, (string) $version, [], sprintf( 'Deleted the staging of %s.', $version ) )
            : $this->busy();
    }

    /**
     * cmd=self-update: what automatic updates will do, and every staging.
     *
     * @return list<string>
     */
    public function status() {

        $c     = $this->context;
        $lines = [ sprintf( 'Installed: %s', $c['installed'] ) ];
        $vcs   = Vcs::checkout( $this->root );

        if ( $vcs !== null ) {

            $lines[] = sprintf( 'Automatic updates: never, OWA runs from a version control checkout (%s).', $vcs );

        } elseif ( ! $c['enabled'] ) {

            $lines[] = 'Automatic updates: off (OWA_AUTO_UPDATE).';

        } else {

            $lines[] = sprintf( 'Automatic updates: %s releases, starting %s; patches %s.',
                $c['level'] === Version::PATCH ? 'patch' : 'patch and minor',
                self::describeWindow( $this->window( Version::MINOR ) ),
                $c['immediate'] ? 'install as soon as they are staged' : 'wait for the same window' );
        }

        $staged  = $this->staging->staged();
        $lines[] = $staged
            ? sprintf( 'Staged to install: %s, %s.', $staged['version'], self::describeWindow( $staged['window'] ?? null ) )
            : 'Staged to install: nothing.';

        if ( $this->staging->count() > 1 ) {

            $lines[] = 'There is more than one updates-* directory in the data directory: nothing installs until all but one are removed.';
        }

        foreach ( $this->staging->versions() as $v ) {

            if ( ! $this->staging->isStaged( $v ) ) {

                $lines[] = sprintf( '%s: an incomplete download.', $v );

                continue;
            }

            $problems = $this->preflight( $v );
            $lines[]  = sprintf( '%s: downloaded %s UTC; %s.', $v, gmdate( 'Y-m-d H:i', (int) filemtime( $this->staging->releaseDir( $v ) ) ),
                $problems ? 'cannot be installed yet' : 'can be installed' );

            foreach ( $problems as $p ) {

                $lines[] = '    ' . $p;
            }

            foreach ( $problems ? [] : $this->manualInstall( $v ) as $u ) {

                $lines[] = sprintf( '    Needs installing by hand -- %s update %d: %s', $u['module'], $u['sequence'], $u['manual_install'] );
            }
        }

        $install = $this->staging->install();

        if ( $install ) {

            $lines[] = 'Unfinished install: ' . json_encode( $install, JSON_UNESCAPED_SLASHES );
        }

        if ( InstallLock::isHeld() ) {

            $lines[] = 'An install is running now.';
        }

        return $lines;
    }

    /**
     * For an older release: the cmd=update rollback= commands that bring each
     * module's stored schema down to the one it expects, newest first.
     *
     * @param  string $v
     * @return list<string>
     */
    private function rollbackFirst( $v ) {

        $out = [];

        foreach ( (array) ( $this->staging->releaseInfo( $v )['modules'] ?? [] ) as $module => $expects ) {

            $stored = ( $this->context['schema'] )( (string) $module );

            if ( $expects === null || $stored === null ) {

                continue;
            }

            for ( $seq = $stored; $seq > (int) $expects; $seq-- ) {

                $out[] = sprintf( 'php cli.php cmd=update rollback=%s.%03d', $module, $seq );
            }
        }

        return $out;
    }

    /**
     * The updates this installation would run on the way to $v that must be
     * installed by hand: for each module it has, those above its stored
     * schema, up to the release's.
     *
     * @param  string $v
     * @return list<array{module: string, sequence: int, manual_install: string}>
     */
    private function manualInstall( $v ) {

        $release = (array) $this->staging->releaseInfo( $v );
        $modules = (array) ( $release['modules'] ?? [] );
        $out     = [];

        foreach ( (array) ( $release['updates'] ?? [] ) as $u ) {

            $module = (string) ( $u['module'] ?? '' );
            $seq    = (int) ( $u['sequence'] ?? 0 );
            $text   = trim( (string) ( $u['manual_install'] ?? '' ) );
            $stored = $text !== '' ? ( $this->context['schema'] )( $module ) : null;

            if ( $stored !== null && $seq > $stored && ( ! isset( $modules[ $module ] ) || $seq <= (int) $modules[ $module ] ) ) {

                $out[] = [ 'module' => $module, 'sequence' => $seq, 'manual_install' => $text ];
            }
        }

        return $out;
    }

    /**
     * A staging that failed: a notice in the bell the first time, and an
     * e-mail once it has kept failing for FAILING_FOR.
     *
     * @param  string $v
     * @param  string $why
     * @param  string $url
     * @return void
     */
    private function stagingFailed( $v, $why, $url ) {

        $first = ( $this->context['raised_at'] )( 'staging_failing:' . $v );

        if ( $first === null ) {

            $this->notify( 'staging_failing:' . $v, 'info',
                sprintf( CoreAPI::t( 'OWA %s could not be downloaded' ), $v ),
                $why . "\n\n" . CoreAPI::t( 'Automatic updates try again every six hours.' ), $url, false );

        } elseif ( (int) $this->context['now'] - $first >= self::FAILING_FOR ) {

            $this->notify( 'staging_failed:' . $v, 'warning',
                sprintf( CoreAPI::t( 'OWA %s has not downloaded for a week' ), $v ),
                $why . "\n\n" . CoreAPI::t( 'Automatic updates keep trying every six hours.' ), $url );
        }
    }

    /**
     * @param  string $key
     * @param  string $severity
     * @param  string $title
     * @param  string $body
     * @param  string $url
     * @param  bool   $email
     * @return void
     */
    private function notify( $key, $severity, $title, $body, $url, $email = true ) {

        ( $this->context['notify'] )( [
            'source'     => self::SOURCE,
            'source_key' => $key,
            'title'      => $title,
            'body'       => $body,
            'url'        => $url,
            'severity'   => $severity,
            'email'      => $email,
        ] );
    }

    /**
     * @param  string $v
     * @return list<string>
     */
    private function preflight( $v ) {

        $release  = (array) $this->staging->releaseInfo( $v );
        $req      = (array) ( $release['requirements'] ?? [] );
        $env      = $this->context['env'] ?? Preflight::environment( $req );

        return array_merge( Preflight::requirements( $req, $env ), Preflight::install( $this->staging->dir( $v ), $this->staging->releaseDir( $v ), $this->root ),
            $this->handoffProblems( $v ) );
    }

    /**
     * What would stop Step 2: hand-off running the release: the installed
     * release's updater.php (it runs before OWA loads) and the release's own.
     *
     * @param  string $v
     * @return list<string>
     */
    private function handoffProblems( $v ) {

        $problems = [];

        if ( ! is_file( $this->staging->releaseDir( $v ) . '/updater.php' ) ) {

            $problems[] = CoreAPI::t( 'The release has no updater.php, so it cannot install itself. Install it by hand.' );
        }

        if ( ! is_file( $this->root . '/updater.php' ) ) {

            $problems[] = CoreAPI::t( 'This installation has no updater.php, so it cannot install a release itself. Install it by hand.' );
        }

        return $problems;
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

            return CoreAPI::t( 'at the next scheduler run' );
        }

        $names = array_flip( self::DAYS );
        $days  = count( (array) $window['days'] ) === 7 ? CoreAPI::t( 'any day' )
            : implode( ', ', array_map( fn ( $d ) => ucfirst( $names[ (int) $d ] ), (array) $window['days'] ) );

        return sprintf( CoreAPI::t( 'between %1$s and %2$s (%3$s), %4$s' ), $window['start'], $window['end'], $window['timezone'], $days );
    }

    /**
     * Delete the stagings of versions already installed, and staged.json if
     * it names one: what an interrupted clean-up after an install leaves, and
     * a staging made by hand of a version not newer than this one.
     *
     * @param  string $installed
     * @return bool false when an install holds the lock
     */
    private function clearInstalled( $installed ) {

        // Not an unfinished install's: its staging is the installer's.
        $done = array_values( array_filter( $this->staging->versions(), fn ( $v ) => Version::compare( $v, $installed ) <= 0
            && ! $this->staging->hasInstall( $v ) ) );
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
