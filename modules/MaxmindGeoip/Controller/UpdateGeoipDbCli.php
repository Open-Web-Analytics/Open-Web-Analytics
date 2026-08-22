<?php

namespace OWA\Module\MaxmindGeoip\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * Refresh the GeoLite2 city database that IP lookups resolve against.
 *
 *     php cli.php cmd=update-geoip-db
 *
 * The database ages badly in a way that is invisible: IP ranges are reassigned
 * between countries and cities continuously, and a stale file does not fail --
 * it answers, wrongly, and the reports look normal. MaxMind publish updates
 * twice a week.
 *
 * REGISTERED ONLY WHEN THIS MODULE IS ACTIVE, because it is registered by this
 * module's constructor and a module's constructor only runs for modules in the
 * active list. An installation not doing GeoIP lookups has no reason to be
 * offered a command for maintaining a database it does not read.
 *
 * A LICENCE KEY IS REQUIRED. MaxMind closed anonymous GeoLite2 downloads at the
 * end of 2019; the file is free but the download is authenticated. There is no
 * way to fetch it without one, so this asks for a key rather than failing at
 * the far end with an HTTP error nobody can act on.
 *
 * Writes to the directory the module already reads from, so a successful run
 * takes effect with nothing to configure.
 */
class UpdateGeoipDbCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $dry_run = (bool) $this->getParam( 'dry-run' );

        // The same answer the reader uses, so what is downloaded is what gets
        // read. edition= overrides for a one-off, but only to something the
        // module can actually read -- an unrecognised edition would download
        // happily and resolve nothing.
        $edition = (string) $this->getParam( 'edition' );

        if ( $edition && ! in_array( $edition, \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS, true ) ) {

            return $this->refuse( sprintf(
                '"%s" is not an edition this module reads. Choose one of: %s.',
                $edition,
                implode( ', ', \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS )
            ) );
        }

        $edition = $edition ?: \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition();

        $key = trim( (string) (
            $this->getParam( 'license-key' )
            ?: \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_license_key' )
            ?: \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_license_key' )
        ) );

        if ( ! $key ) {

            return $this->refuse(
                'A MaxMind licence key is required. GeoLite2 is free but its download has been '
              . 'authenticated since 2019. Create a key at maxmind.com, then either set '
              . 'db_license_key for the maxmind_geoip module or pass license-key=... to this '
              . 'command.'
            );
        }

        if ( ! class_exists( 'PharData' ) ) {

            return $this->fail(
                'The phar extension is required to unpack the download, and is not available.'
            );
        }

        $dir = $this->dataDir();

        // Before the download, and separately per failure: they need different
        // fixes, and one "permission denied" points at the wrong one.
        $problem = $this->whyNotWritable( $dir, $edition );

        if ( $problem ) {

            return $this->fail( $problem );
        }

        $url = sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=%s&license_key=%s&suffix=tar.gz',
            $edition,
            rawurlencode( $key )
        );

        $destination = $dir . $edition . '.mmdb';

        // MaxMind ask for this, and it is in their interest and ours: a HEAD
        // request reads Last-Modified WITHOUT consuming a download from the
        // account's limit. They rate-limit downloads and say so, so a command
        // that can be scheduled must not fetch tens of megabytes to discover it
        // already has them.
        //
        // --force skips the check, for the case where the local file is
        // suspect rather than merely old.
        $local = file_exists( $destination ) ? (int) filemtime( $destination ) : 0;

        // Asked for whenever the answer could change what happens next: when
        // there is a local copy to compare against, and on a dry run, which
        // exists to report rather than to act.
        $remote = ( $local && ! $this->getParam( 'force' ) ) || $dry_run
            ? $this->lastModified( $url )
            : 0;

        if ( $local && $remote && $remote <= $local && ! $this->getParam( 'force' ) ) {

            return $this->refuse( sprintf(
                'Already current: MaxMind last changed %s on %s, and the local copy is from %s. '
              . 'Nothing downloaded. Use --force to fetch it anyway.',
                $edition,
                gmdate( 'Y-m-d H:i:s', $remote ) . ' UTC',
                gmdate( 'Y-m-d H:i:s', $local ) . ' UTC'
            ) );
        }

        // A dry run stops HERE, before the transfer. It used to report after
        // downloading, which meant asking what would happen cost the same tens
        // of megabytes against the account's download limit as doing it --
        // the opposite of what a dry run is for. The HEAD above costs nothing.
        if ( $dry_run ) {

            return $this->refuse( sprintf(
                'Dry run: would download %s and write %s. %s Nothing was downloaded or changed.',
                $edition,
                $destination,
                $local
                    ? sprintf( 'The local copy is from %s and MaxMind %s.',
                        gmdate( 'Y-m-d H:i:s', $local ) . ' UTC',
                        $remote
                            ? 'last changed theirs on ' . gmdate( 'Y-m-d H:i:s', $remote ) . ' UTC'
                            : 'did not say when theirs changed' )
                    : 'No database is installed yet.'
            ) );
        }

        $this->write( sprintf( 'Downloading %s from MaxMind into %s', $edition, $dir ) );

        // To a temporary file, not to the destination: the archive is tens of
        // megabytes and the live database must stay readable and intact for
        // every request happening while this runs.
        // The name has to end in .tar.gz. PharData refuses to open a file whose
        // extension it does not recognise -- "file extension (or combination)
        // not recognised" -- and tempnam() produces a name with no extension at
        // all. Created through tempnam first so the file is still made
        // exclusively, then renamed rather than guessed at.
        $archive = tempnam( sys_get_temp_dir(), 'owa-geoip-' );

        if ( $archive ) {

            $named = $archive . '.tar.gz';

            if ( @rename( $archive, $named ) ) {

                $archive = $named;
            }
        }

        $bytes = $this->download( $url, $archive );

        if ( ! is_int( $bytes ) ) {

            @unlink( $archive );

            return $this->fail( $bytes );
        }

        $this->write( sprintf( 'Downloaded %s.', $this->readableSize( $bytes ) ) );

        $extracted = $this->extractDatabase( $archive, $dir, $edition );

        @unlink( $archive );

        if ( ! is_string( $extracted ) || ! file_exists( $extracted ) ) {

            return $this->fail( is_string( $extracted )
                ? $extracted
                : 'The archive did not contain a database file.' );
        }

        $this->write( sprintf(
            'Done. %s is %s, and lookups use it from the next request onward.',
            $extracted,
            $this->readableSize( (int) filesize( $extracted ) )
        ) );

        if ( file_exists( $extracted . '.previous' ) ) {

            $this->write( sprintf(
                'The database this replaced is kept at %s.previous (%s). Delete it once you are '
              . 'satisfied with the new one.',
                $extracted,
                $this->readableSize( (int) filesize( $extracted . '.previous' ) )
            ) );
        }

        return;
    }

    /**
     * Where the database lives.
     *
     * A method rather than an expression so a test can point it somewhere
     * disposable. The three things worth testing here -- unpacking a nested
     * archive, replacing the live file atomically, and cleaning up afterwards
     * -- all write to this directory, and a test that writes to the real one
     * would either destroy a working installation's database or need a
     * 60 MB fixture to put back.
     *
     * @return string
     */
    protected function dataDir() {

        return defined( 'OWA_MAXMIND_DATA_DIR' ) ? OWA_MAXMIND_DATA_DIR : OWA_DATA_DIR . 'maxmind/';
    }

    /**
     * @return int|string bytes written, or the reason it failed
     */
    protected function download( $url, $destination ) {

        // follow_location explicitly: MaxMind redirect the download to object
        // storage, and a client that does not follow ends up saving a redirect
        // page and naming it a database.
        $context = stream_context_create( [
            'http' => [ 'timeout' => 120, 'ignore_errors' => true,
                        'follow_location' => 1, 'max_redirects' => 5,
                        'user_agent' => 'Open Web Analytics' ],
        ] );

        $in = @fopen( $url, 'rb', false, $context );

        if ( ! $in ) {

            return 'Could not reach the MaxMind download service. The database has not been changed.';
        }

        // The service answers 401 with a body, so the status line is the only
        // thing that distinguishes a bad key from a real download -- writing
        // the body out would leave an error page named as a database.
        $status = $this->statusFrom( $http_response_header ?? [] );

        if ( $status === 401 || $status === 403 ) {

            fclose( $in );

            return 'MaxMind rejected the licence key. Check that it is current and permits '
                 . 'GeoLite2 downloads. The database has not been changed.';
        }

        if ( $status && $status >= 400 ) {

            fclose( $in );

            return sprintf(
                'MaxMind returned HTTP %d. The database has not been changed.', $status );
        }

        $out = @fopen( $destination, 'wb' );

        if ( ! $out ) {

            fclose( $in );

            return sprintf( 'Could not write a temporary file at %s.', $destination );
        }

        $bytes = stream_copy_to_stream( $in, $out );

        fclose( $in );
        fclose( $out );

        if ( ! $bytes ) {

            return 'The download was empty. The database has not been changed.';
        }

        return (int) $bytes;
    }

    /**
     * When MaxMind last changed this edition, or 0 if they will not say.
     *
     * A HEAD request, which MaxMind document as the way to check for updates
     * without spending a download from the account's limit. Returning 0 on any
     * doubt means the caller downloads -- being wrong in the direction of doing
     * the work is much better than skipping an update that was available.
     *
     * @param string $url
     * @return int unix timestamp, or 0
     */
    protected function lastModified( $url ) {

        $context = stream_context_create( [
            'http' => [ 'method' => 'HEAD', 'timeout' => 30, 'ignore_errors' => true,
                        'follow_location' => 1, 'max_redirects' => 5,
                        'user_agent' => 'Open Web Analytics' ],
        ] );

        $handle = @fopen( $url, 'rb', false, $context );

        if ( ! $handle ) {

            return 0;
        }

        $headers = $http_response_header ?? [];

        fclose( $handle );

        if ( $this->statusFrom( $headers ) >= 400 ) {

            // A rejected key answers here too. Say nothing and let the download
            // report it properly, rather than reporting "already current" for
            // a credential problem.
            return 0;
        }

        foreach ( $headers as $header ) {

            if ( stripos( $header, 'Last-Modified:' ) === 0 ) {

                $stamp = strtotime( trim( substr( $header, 14 ) ) );

                return $stamp ?: 0;
            }
        }

        return 0;
    }

    protected function statusFrom( array $headers ) {

        foreach ( $headers as $header ) {

            if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {

                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Unpack the .mmdb out of MaxMind's tarball.
     *
     * The archive nests the database inside a dated directory
     * (GeoLite2-City_20260821/GeoLite2-City.mmdb), so the file is located
     * rather than assumed, and moved into place only once it is whole -- a
     * half-extracted database would be read by the very next request.
     *
     * @return string|null the path written, or the reason it failed
     */
    protected function extractDatabase( $archive, $dir, $edition ) {

        $work = $dir . '.update-' . getmypid() . '/';

        if ( ! @mkdir( $work, 0755, true ) && ! is_dir( $work ) ) {

            return sprintf( 'Could not create a working directory at %s.', $work );
        }

        try {

            $phar = new \PharData( $archive );
            $phar->decompress();

            $tar = preg_replace( '/\.gz$/', '', $archive );

            ( new \PharData( $tar ) )->extractTo( $work, null, true );

            @unlink( $tar );

        } catch ( \Throwable $e ) {

            $this->removeTree( $work );

            return sprintf( 'Could not unpack the download: %s', $e->getMessage() );
        }

        $found = null;

        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $work ) ) as $file ) {

            if ( $file->isFile() && strtolower( $file->getExtension() ) === 'mmdb' ) {

                $found = $file->getPathname();
                break;
            }
        }

        if ( ! $found ) {

            $this->removeTree( $work );

            return null;
        }

        $destination = $dir . $edition . '.mmdb';

        $installed = $this->installDatabase( $found, $destination );

        $this->removeTree( $work );

        return $installed;
    }

    /**
     * Put the new database in place, keeping the one it replaces.
     *
     * The replacement is a rename, which on one filesystem is atomic, so no
     * request ever reads a half-written database. That was already true. What
     * was missing is that the file being replaced was simply gone -- 60MB
     * overwritten with no way back, on a file an installation may have been
     * relying on for years.
     *
     * That is not hypothetical: a run of this command on a live installation
     * replaced a database that had been in place since 2024, and nothing could
     * have restored it. A download that succeeds but produces a bad file, or a
     * MaxMind release with a regression in it, would have left no way back
     * either.
     *
     * So the existing file is moved aside first, and only then is the new one
     * moved in. If that second move fails, the original goes back -- a failed
     * update must not be able to leave an installation with no database at all,
     * which would be strictly worse than the stale one it started with.
     *
     * Exactly one previous copy is kept. Keeping more would accumulate 60MB at
     * a time for a file that is replaced twice a week.
     *
     * @return string|null the installed path, or the reason it failed
     */
    protected function installDatabase( $found, $destination ) {

        $previous   = $destination . '.previous';
        $had_existing = file_exists( $destination );

        if ( $had_existing ) {

            @unlink( $previous );

            if ( ! @rename( $destination, $previous ) ) {

                return sprintf(
                    'Could not set the existing database aside at %s, so it has been left alone '
                  . 'and nothing was replaced.', $previous );
            }
        }

        if ( ! @rename( $found, $destination ) ) {

            if ( $had_existing ) {

                // Back where it was. Better a stale database than none.
                @rename( $previous, $destination );
            }

            return sprintf( 'Could not move the new database into %s.%s',
                dirname( $destination ),
                $had_existing ? ' The database you had is still in place.' : '' );
        }

        @chmod( $destination, 0644 );

        return $destination;
    }

    protected function removeTree( $dir ) {

        if ( ! is_dir( $dir ) ) {

            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {

            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }

        @rmdir( $dir );
    }

    /**
     * Why the database cannot be written, or null if it can. Same three
     * distinct answers as the user-agent patterns command, for the same reason.
     *
     * @return string|null
     */
    protected function whyNotWritable( $dir, $edition = 'GeoLite2-City' ) {

        $whoami = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' )
            ? ( posix_getpwuid( posix_geteuid() )['name'] ?? 'unknown' )
            : 'the current user';

        if ( ! is_dir( $dir ) ) {

            $parent = dirname( rtrim( $dir, DIRECTORY_SEPARATOR ) );

            if ( ! is_dir( $parent ) ) {

                return sprintf( '%s does not exist, so %s cannot be created inside it.', $parent, $dir );
            }

            if ( ! is_writable( $parent ) ) {

                return sprintf( '%s must be writable by %s so that %s can be created, and it is not.',
                    $parent, $whoami, $dir );
            }

            return null;
        }

        if ( ! is_writable( $dir ) ) {

            return sprintf( '%s is not writable by %s.', $dir, $whoami );
        }

        $file = $dir . $edition . '.mmdb';

        if ( file_exists( $file ) && ! is_writable( $file ) ) {

            return sprintf(
                '%s exists but is not writable by %s, so it cannot be replaced.', $file, $whoami );
        }

        return null;
    }

    protected function readableSize( $bytes ) {

        return $bytes > 1048576
            ? sprintf( '%.1f MB', $bytes / 1048576 )
            : sprintf( '%.0f KB', $bytes / 1024 );
    }
}
