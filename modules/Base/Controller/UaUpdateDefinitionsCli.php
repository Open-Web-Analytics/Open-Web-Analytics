<?php

namespace OWA\Module\Base\Controller;

use UAParser\Util\Converter;
use UAParser\Util\Fetcher;

/**
 * Refresh the user-agent patterns OWA identifies browsers and crawlers with.
 *
 *     php cli.php cmd=ua-update-definitions
 *
 * WHY THIS EXISTS
 * The patterns come from uap-core, which is a separate project from the PHP
 * library that reads them. uap-core updates its data roughly monthly and has
 * not tagged a release since 2019, so consumers follow its master branch. The
 * PHP library bundles a copy taken whenever ITS maintainers cut a release --
 * the most recent being July 2025.
 *
 * So the bundled patterns are as old as the library's last release, however
 * new the library is, and updating the composer dependency does not help: there
 * is nothing newer to update to. Every browser and crawler added upstream since
 * is invisible until the file is replaced.
 *
 * WHERE IT PUTS THE FILE
 * owa-data/conf/ua-parser/ (Core\Overrides), which updates leave alone. It is
 * PHP the cron user runs, so it goes where only the owner of OWA's files can
 * write, and is used only while that holds: run this as that owner. Writing
 * inside vendor/ would work until the next composer install erased it.
 *
 * Browscap prefers this file whenever it exists, so a successful run takes
 * effect immediately with nothing to configure.
 */
class UaUpdateDefinitionsCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Downloads the latest user-agent patterns OWA identifies browsers and crawlers with, into owa-data/conf/ua-parser/.',
            'arguments'   => [
                '--dry-run' => 'Report what would be downloaded, and write nothing.',
            ],
        ];
    }

    public function __construct( $params ) {

        // Same capability as the other maintenance commands: this rewrites a
        // file the whole installation parses every request with, which is
        // administrator territory even though it reads as a data refresh.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function action() {

        $dry_run = (bool) $this->getParam( 'dry-run' );

        if ( ! class_exists( Fetcher::class ) || ! class_exists( Converter::class ) ) {

            return $this->fail(
                'The user-agent parser tools are not installed. Run composer install to get them.'
            );
        }

        if ( ! class_exists( 'Symfony\\Component\\Yaml\\Yaml' ) ) {

            return $this->fail(
                'symfony/yaml is required to read the upstream patterns, and is not installed. '
              . 'Run composer install.'
            );
        }

        $configured = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'ua_regexes_dir' );
        $dir        = $configured !== '' ? \OWA\Core\Env::withSlash( $configured ) : \OWA\Core\Overrides::dir() . 'ua-parser/';
        $top        = $configured !== '' ? $dir : \OWA\Core\Env::dataDir();
        $file       = $dir . 'regexes.php';

        // Permissions BEFORE the download. Finding out that owa-data cannot be
        // written after fetching 200KB is a worse experience than being told
        // first, and the answer does not depend on the download.
        //
        // The installer checks owa-data/logs/, but not
        // owa-data itself -- nothing needed to create a directory there until
        // now. So this cannot assume a passing install check covers it.
        $problem = $this->whyNotWritable( $dir );

        if ( $problem ) {

            return $this->fail( $problem );
        }

        $this->write( sprintf( 'Fetching user-agent patterns from uap-core into %s', $dir ) );

        // Downloaded before the directory is touched, so a failed download
        // leaves whatever is already in place working.
        try {

            $yaml = ( new Fetcher() )->fetch();

        } catch ( \Throwable $e ) {

            return $this->fail( sprintf(
                'Could not download the patterns: %s. The installation keeps using the patterns '
              . 'it already has.',
                $e->getMessage()
            ) );
        }

        if ( ! $yaml ) {

            return $this->fail( 'The download returned nothing. Nothing has been changed.' );
        }

        $this->write( sprintf( 'Downloaded %s of patterns.', $this->readableSize( strlen( $yaml ) ) ) );

        if ( $dry_run ) {

            return $this->refuse( sprintf(
                'Dry run: %s would be written. Nothing was changed.', $file ) );
        }

        /*
         * Made 0755, not shared: the patterns are PHP the cron user runs, so
         * only this user (the owner of OWA's files) may write where they are
         * (Core\Overrides).
         */
        if ( ! \OWA\Core\SafeFile::makeDirs( $dir, 0755 ) ) {

            // Reachable despite the preflight: something else could have
            // changed underneath, and a race is not a reason to write a
            // half-file.
            return $this->fail( sprintf( 'Could not create %s.', $dir ) );
        }

        try {

            // The converter writes the file the parser reads. Its second
            // argument keeps a backup of the previous one, which is what makes
            // a bad upstream day recoverable.
            ( new Converter( $dir ) )->convertString( $yaml, true );

        } catch ( \Throwable $e ) {

            return $this->fail( sprintf( 'Could not convert the patterns: %s', $e->getMessage() ) );
        }

        if ( ! file_exists( $file ) ) {

            return $this->fail( sprintf(
                'The conversion reported success but %s is not there.', $file ) );
        }

        $untrusted = \OWA\Core\SafeFile::trustProblem( $file, $top );

        if ( $untrusted !== null ) {

            return $this->fail( sprintf(
                '%s was written, but OWA will not use it: %s Only the owner of OWA\'s files may be able '
              . 'to write it and the directories above it, up to %s.', $file, $untrusted, $top ) );
        }

        $this->write( sprintf(
            'Done. %s now holds %s of patterns, and Browscap will prefer it over the bundled copy '
          . 'from the next request onward.',
            $file,
            $this->readableSize( (int) filesize( $file ) )
        ) );

        return;
    }

    /**
     * Why the patterns cannot be written, or null if they can.
     *
     * Three distinct answers, because they need three different fixes and a
     * single "permission denied" would send someone to the wrong one:
     *
     *   - the directory does not exist yet, so owa-data itself must be writable
     *     for it to be created
     *   - the directory exists but is not writable
     *   - the directory is writable but the existing file is not, which happens
     *     when a file was placed by root and the web user runs the refresh
     *
     * Reported with the path and the user, since the usual cause is running the
     * command as a different user than the one that owns owa-data.
     *
     * @param string $dir
     * @return string|null
     */
    protected function whyNotWritable( $dir ) {

        $whoami = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' )
            ? ( posix_getpwuid( posix_geteuid() )['name'] ?? 'unknown' )
            : 'the current user';

        if ( ! is_dir( $dir ) ) {

            // The nearest directory that exists, not the data directory: the
            // location is configurable, and conf/ under the data directory may
            // be missing too. Checking a different directory than the one the
            // first mkdir will use gives a confident answer about the wrong thing.
            $parent = dirname( rtrim( $dir, DIRECTORY_SEPARATOR ) );

            while ( ! is_dir( $parent ) && dirname( $parent ) !== $parent ) {

                $parent = dirname( $parent );
            }

            if ( ! is_writable( $parent ) ) {

                return sprintf(
                    '%s must be writable by %s so that %s can be created, and it is not. '
                  . 'Either grant write access, or create %s yourself and make it writable.',
                    $parent, $whoami, $dir, $dir
                );
            }

            return null;
        }

        if ( ! is_writable( $dir ) ) {

            return sprintf( '%s is not writable by %s.', $dir, $whoami );
        }

        $file = $dir . 'regexes.php';

        if ( file_exists( $file ) && ! is_writable( $file ) ) {

            return sprintf(
                '%s exists but is not writable by %s, so it cannot be replaced. This usually means '
              . 'it was written by a different user than the one running this command.',
                $file, $whoami
            );
        }

        return null;
    }
}
