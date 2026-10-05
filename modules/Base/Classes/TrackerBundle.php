<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A Profile's tracking bundle (PLAN 2.24): one static file at
 * public/tracker/<site_id>.js holding the Profile's configuration, the lazy
 * chunks it uses, and the tracker.
 *
 * COMPOSED, NEVER COMPILED. Webpack builds the tracker and its chunks once per
 * OWA build; this joins what it wrote, in the order the measurement in 2.24.3
 * requires:
 *
 *     header + preamble     the Profile's commands
 *     <chunk>.js ...        each lazy plugin the Profile turns on
 *     owa.tracker.js        as built
 *
 * A chunk goes BEFORE the core: the core calls import() while it is still
 * executing, and a chunk already on the shared webpackChunkowa array then
 * satisfies it with no request.
 *
 * ONLY PUBLIC COMMANDS. The preamble configures the tracker exactly as a
 * snippet can, so a bundle is a pre-written snippet and anything it does a page
 * owner can do by hand.
 *
 * THE FILE IS ITS OWN RECORD. Its first line carries a hash of the config and
 * of the build files it was made from. isCurrent() recomputes both, so a saved
 * setting, a new Profile and an OWA update are all just a header that no longer
 * matches -- nothing marks a bundle stale and nothing stores publish state.
 */
class TrackerBundle {

    /** The bundle format; part of the header, so a change republishes every bundle. */
    const FORMAT = 1;

    /** Where bundles are written, under OWA_DIR. */
    const DIR = 'public/tracker/';

    /** The built tracker's directory and its manifest, under OWA_DIR. */
    const DIST = 'public/base/dist/';
    const MANIFEST = 'owa.tracker.manifest.json';

    /** A site id that may be a file name. */
    const SITE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Absolute directories in place of OWA_DIR's, for tests. Null in use. */
    public static $distDir = null;
    public static $outDir  = null;

    private static function distDir() {

        return self::$distDir ?? OWA_DIR . self::DIST;
    }

    private static function outDir() {

        return self::$outDir ?? OWA_DIR . self::DIR;
    }

    /**
     * @param  string $site_id
     * @return string|null the bundle's path, or null for an id that cannot be a file name
     */
    public static function path( $site_id ) {

        return preg_match( self::SITE_ID_PATTERN, (string) $site_id )
            ? self::outDir() . $site_id . '.js'
            : null;
    }

    /**
     * @param  string $site_id
     * @return string the URL a snippet loads it from
     */
    public static function url( $site_id ) {

        return rtrim( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), '/' )
            . '/' . self::DIR . $site_id . '.js';
    }

    /**
     * The built tracker's manifest: its file and each lazy chunk's, with their
     * SHA-256 (written by webpack.config.js).
     *
     * @return array|null null when the tracker has not been built
     */
    public static function buildManifest() {

        $file = self::distDir() . self::MANIFEST;

        if ( ! is_readable( $file ) ) {

            return null;
        }

        $manifest = json_decode( (string) file_get_contents( $file ), true );

        return is_array( $manifest ) && isset( $manifest['core']['file'] ) ? $manifest : null;
    }

    /**
     * What a Profile's tracker is told: three lists of commands, in the order
     * the preamble queues them, and the lazy plugins to inline.
     *
     *   options   configuration, before the page's own commands
     *   features  what to track, after them, so a page's setPageTitle or
     *             setUserId reaches the page view
     *   plugins   lazy plugin names whose chunks join the bundle
     *
     * A module adds its own through the tracker_bundle_config filter.
     *
     * @param  string $site_id
     * @return array
     */
    public static function config( $site_id ) {

        $get = function ( $key ) use ( $site_id ) {

            return \OWA\Core\CoreAPI::getSetting( 'base', $key, 'profile', (string) $site_id );
        };

        $options = array(
            array( 'setSiteId', (string) $site_id ),
            // The install's, so the tracker's session window is the cube's.
            array( 'setOption', 'sessionLength', (int) \OWA\Core\CoreAPI::getSetting( 'base', 'session_length' ) ),
            array( 'setOption', 'stateStoreExpirations', array(
                'v' => (int) $get( 'tracker_visitor_cookie_days' ),
                's' => (int) $get( 'tracker_session_cookie_days' ),
            ) ),
            array( 'setSearchQueryParams', self::listOf( $get( 'tracker_site_search_params' ) ) ),
            array( 'setOption', 'scrollThresholds', array_map( 'intval', self::listOf( $get( 'tracker_scroll_thresholds' ) ) ) ),
            array( 'setOption', 'downloadExtensions', array_map( 'strtolower', self::listOf( $get( 'tracker_download_extensions' ) ) ) ),
        );

        $domain = trim( (string) $get( 'tracker_cookie_domain' ) );

        if ( $domain !== '' ) {

            $options[] = array( 'setCookieDomain', $domain );
        }

        if ( $get( 'tracker_url_fragments' ) ) {

            $options[] = array( 'setTrackUrlFragments', true );
        }

        if ( \OWA\Core\Lib::inDebug() ) {

            $options[] = array( 'setDebug', true );
        }

        $features = array();

        foreach ( array(
            'tracker_page_views'    => 'trackPageView',
            'tracker_clicks'        => 'trackClicks',
            'tracker_forms'         => 'trackForms',
            'tracker_scroll'        => 'trackScroll',
            'tracker_site_search'   => 'trackSiteSearch',
            'tracker_exceptions'    => 'trackExceptions',
            'tracker_route_changes' => 'trackRouteChanges',
        ) as $setting => $command ) {

            if ( $get( $setting ) ) {

                $features[] = array( $command );
            }
        }

        $config = \OWA\Core\CoreAPI::filter( 'tracker_bundle_config',
            array( 'options' => $options, 'features' => $features, 'plugins' => array() ),
            (string) $site_id );

        return array(
            'options'  => array_values( (array) ( $config['options'] ?? array() ) ),
            'features' => array_values( (array) ( $config['features'] ?? array() ) ),
            'plugins'  => array_values( array_unique( (array) ( $config['plugins'] ?? array() ) ) ),
        );
    }

    /** What each of Base's feature commands records, as the Tracking Tag screen names it. */
    const FEATURE_LABELS = array(
        'trackPageView'     => 'Page views',
        'trackClicks'       => 'Clicks',
        'trackForms'        => 'Forms',
        'trackScroll'       => 'Scroll depth',
        'trackSiteSearch'   => 'Site search',
        'trackExceptions'   => 'JavaScript errors',
        'trackRouteChanges' => 'Route changes',
    );

    /**
     * What this Profile's tracker records, from its saved settings: a label for
     * each feature config() turns on, in the order it turns them on.
     *
     * A module labels the features it adds through the tracker_feature_labels
     * filter; one it does not label is named by its command.
     *
     * @param  string $site_id
     * @return string[]
     */
    public static function trackedEvents( $site_id ) {

        $labels = (array) \OWA\Core\CoreAPI::filter( 'tracker_feature_labels', self::FEATURE_LABELS );
        $out    = array();

        foreach ( self::config( $site_id )['features'] as $feature ) {

            $command = (string) ( $feature[0] ?? '' );

            if ( $command !== '' ) {

                $out[ $command ] = (string) ( $labels[ $command ] ?? $command );
            }
        }

        return array_values( $out );
    }

    /**
     * The first line of a bundle made from this config and this build.
     *
     * @param  array $config   from config()
     * @param  array $manifest from buildManifest()
     * @return string
     */
    public static function header( array $config, array $manifest ) {

        return sprintf( '/* owa-bundle %d config=%s build=%s */',
            self::FORMAT,
            hash( 'sha256', json_encode( array( $config, (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ) ) ) ),
            self::manifestHash( $manifest ) );
    }

    /**
     * The build's identity, as a bundle's header records it: a hash of the
     * manifest, which names every built file by its content.
     */
    /**
     * The built tracker, as a hash of the manifest the build writes beside it:
     * the SHA-256 of the core and of each chunk. A new build is a new hash.
     *
     * What the update gate compares (Module::isUpToDate()) -- the build itself,
     * so no PR has a version to bump. Empty when there is no build.
     *
     * @return string
     */
    public static function buildHash() {

        $manifest = self::buildManifest();

        return $manifest ? self::manifestHash( $manifest ) : '';
    }

    private static function manifestHash( array $manifest ) {

        return hash( 'sha256', json_encode( $manifest ) );
    }

    /**
     * The bundle's source, or null when it cannot be composed: no build, a
     * plugin the build did not produce, or a built file that is not the one
     * the manifest describes -- a build half-written, or a deploy mid-copy.
     *
     * @param  string $site_id
     * @return string|null
     */
    public static function compose( $site_id ) {

        $manifest = self::buildManifest();

        if ( ! $manifest ) {

            \OWA\Core\CoreAPI::notice( 'Tracker bundle: the tracker has not been built (no ' . self::MANIFEST . ').' );

            return null;
        }

        $config = self::config( $site_id );
        $parts  = array();

        foreach ( $config['plugins'] as $plugin ) {

            if ( empty( $manifest['plugins'][ $plugin ]['file'] ) ) {

                \OWA\Core\CoreAPI::notice( "Tracker bundle: the build has no '$plugin' plugin." );

                return null;
            }

            $parts[] = $manifest['plugins'][ $plugin ];
        }

        $parts[] = $manifest['core'];

        $files = array();

        foreach ( $parts as $part ) {

            $source = self::builtFile( $part );

            if ( $source === null ) {

                return null;
            }

            $files[] = $source;
        }

        return self::header( $config, $manifest ) . "\n"
             . self::preamble( $config ) . "\n"
             . implode( "\n", $files ) . "\n";
    }

    /**
     * A built file, checked against the hash the manifest gives it.
     *
     * @param  array $part  file, sha256
     * @return string|null
     */
    private static function builtFile( array $part ) {

        $name = (string) ( $part['file'] ?? '' );

        if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $name ) ) {

            return null;
        }

        $source = @file_get_contents( self::distDir() . $name );

        if ( $source === false || hash( 'sha256', $source ) !== ( $part['sha256'] ?? '' ) ) {

            \OWA\Core\CoreAPI::notice( "Tracker bundle: $name does not match the build manifest." );

            return null;
        }

        return $source;
    }

    /**
     * The commands, queued around the page's own.
     *
     * The page's queued commands run between the bundle's options and its
     * features, and a page that queued its own trackPageView -- a custom URL, a
     * single-page app -- suppresses the bundle's, so a view is never counted
     * twice. If a tracker is already running (owa_cmds is its live queue), the
     * commands are pushed to it.
     *
     * @param  array $config
     * @return string
     */
    public static function preamble( array $config ) {

        $json = function ( $value ) {

            return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
        };

        $base = rtrim( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), '/' ) . '/';

        return '(function(w){'
             . 'var o=' . $json( $config['options'] ) . ',f=' . $json( $config['features'] ) . ',p=w.owa_cmds||[],i;'
             . 'if(!w.owa_baseUrl){w.owa_baseUrl=' . $json( $base ) . ';'
             . 'if(w.location&&w.location.protocol==="https:"){w.owa_baseUrl=w.owa_baseSecUrl||w.owa_baseUrl.replace(/^http:/,"https:");}}'
             . 'if(!Array.isArray(p)){o.concat(f).forEach(function(c){p.push(c);});return;}'
             . 'for(i=0;i<p.length;i++){if(p[i]&&p[i][0]==="trackPageView"){'
             . 'f=f.filter(function(c){return c[0]!=="trackPageView";});break;}}'
             . 'w.owa_cmds=o.concat(p,f);'
             . '})(window);';
    }

    /**
     * The Profile's commands as snippet lines, for the classic tag: the same
     * options and features the bundle bakes in, so a classic tag copied from
     * the Tracking Tag screen does what the Profile is set to do then.
     *
     * @param  string $site_id
     * @return string[]
     */
    public static function commandLines( $site_id ) {

        $config = self::config( $site_id );
        $lines  = array();

        foreach ( array_merge( $config['options'], $config['features'] ) as $command ) {

            $lines[] = 'owa_cmds.push(' . json_encode( $command, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ');';
        }

        return $lines;
    }

    /**
     * Whether the Profile's bundle on disk is the one it should have.
     *
     * @param  string $site_id
     * @return bool
     */
    public static function isCurrent( $site_id ) {

        $path     = self::path( $site_id );
        $manifest = self::buildManifest();

        if ( ! $path || ! $manifest || ! is_readable( $path ) ) {

            return false;
        }

        $handle = fopen( $path, 'r' );
        $first  = $handle ? rtrim( (string) fgets( $handle ), "\r\n" ) : '';

        if ( $handle ) {

            fclose( $handle );
        }

        return $first === self::header( self::config( $site_id ), $manifest );
    }

    /**
     * Write a Profile's bundle: to a temporary file beside it, then renamed
     * into place, so a request never reads half of one.
     *
     * @param  string $site_id
     * @return bool
     */
    public static function publish( $site_id ) {

        $path = self::path( $site_id );

        if ( ! $path ) {

            \OWA\Core\CoreAPI::notice( "Tracker bundle: '$site_id' cannot be a file name; not published." );

            return false;
        }

        $source = self::compose( $site_id );

        if ( $source === null ) {

            return false;
        }

        $dir = dirname( $path );

        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {

            \OWA\Core\CoreAPI::notice( "Tracker bundle: cannot create $dir." );

            return false;
        }

        $tmp = $path . '.' . getmypid() . '.tmp';

        if ( @file_put_contents( $tmp, $source ) !== strlen( $source ) ) {

            @unlink( $tmp );
            \OWA\Core\CoreAPI::notice( "Tracker bundle: cannot write $tmp." );

            return false;
        }

        @chmod( $tmp, 0644 );

        if ( ! @rename( $tmp, $path ) ) {

            @unlink( $tmp );
            \OWA\Core\CoreAPI::notice( "Tracker bundle: cannot move the bundle into $path." );

            return false;
        }

        return true;
    }

    /**
     * Every live web Profile's site id.
     *
     * @return string[]
     */
    public static function siteIds() {

        $out = array();

        foreach ( (array) \OWA\Core\CoreAPI::getSitesList() as $row ) {

            if ( ! empty( $row['archived_date'] ) ) {

                continue;
            }

            $type = (string) ( $row['stream_type'] ?? '' );

            if ( $type !== '' && $type !== \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

                continue;
            }

            $out[] = (string) $row['site_id'];
        }

        return $out;
    }

    /**
     * Publish every bundle that is not current, or every bundle when forced.
     *
     * @param  bool          $force
     * @param  string[]|null $site_ids  null for every live web Profile
     * @return array site id => 'current' | 'published' | 'failed' | 'removed'
     */
    public static function publishStale( $force = false, $site_ids = null ) {

        $out  = array();
        $live = $site_ids === null ? self::siteIds() : (array) $site_ids;

        foreach ( $live as $site_id ) {

            if ( ! $force && self::isCurrent( $site_id ) ) {

                $out[ $site_id ] = 'current';
                continue;
            }

            $out[ $site_id ] = self::publish( $site_id ) ? 'published' : 'failed';
        }

        // A full run also takes down the bundle of a Profile that is gone:
        // deleted, archived, or no longer a web stream.
        if ( $site_ids === null ) {

            foreach ( self::removeOrphans( $live ) as $site_id ) {

                $out[ $site_id ] = 'removed';
            }
        }

        return $out;
    }

    /**
     * Delete bundle files whose Profile is not in $live.
     *
     * Only names this class writes -- <site id>.js and its temporary files --
     * so nothing else that might sit in the directory is touched.
     *
     * @param  string[] $live
     * @return string[] the site ids whose bundles were removed
     */
    public static function removeOrphans( array $live ) {

        $removed = array();
        $keep    = array_flip( $live );

        foreach ( (array) glob( self::outDir() . '*.js' ) as $file ) {

            $site_id = basename( $file, '.js' );

            if ( preg_match( self::SITE_ID_PATTERN, $site_id ) && ! isset( $keep[ $site_id ] ) && @unlink( $file ) ) {

                $removed[] = $site_id;
            }
        }

        // A temporary file left by a run that died between write and rename.
        foreach ( (array) glob( self::outDir() . '*.js.*.tmp' ) as $file ) {

            if ( filemtime( $file ) < time() - 3600 ) {

                @unlink( $file );
            }
        }

        return $removed;
    }

    /**
     * Take down one Profile's bundle: it was deleted, archived, or stopped
     * being a web stream.
     *
     * @param  string $site_id
     * @return bool whether there was one
     */
    public static function remove( $site_id ) {

        $path = self::path( $site_id );

        return $path && is_file( $path ) && @unlink( $path );
    }

    /**
     * Publish one Profile's bundle now, as a save of its settings does
     * (PLAN 2.30.7); if that cannot be done, queue it rather than leave the
     * Profile on its old settings until the daily check.
     *
     * @param  string $site_id
     * @return bool whether it was published now
     */
    public static function publishNow( $site_id ) {

        if ( self::publish( $site_id ) ) {

            return true;
        }

        \OWA\Core\CoreAPI::enqueueJob( 'publish-trackers', array( 'site' => (string) $site_id ),
            'publish-trackers:' . $site_id );

        return false;
    }

    /**
     * Queue one publish-trackers run for every Profile (PLAN 2.30.7): what a
     * save above a single Profile asks for -- a Property's or the install's
     * tag settings. Saves within the minute leave one job, and a run rewrites
     * only the bundles that are stale.
     *
     * Before the job queue exists -- an install mid-upgrade -- there is
     * nothing to queue on, and the daily publish-trackers covers it.
     *
     * @return string|false the job's id
     */
    public static function scheduleFullPublish() {

        try {

            $db = \OWA\Core\CoreAPI::dbSingleton();

            if ( ! $db->tableExists( \OWA\Module\Base\Classes\JobQueue::table() ) ) {

                return false;
            }

            return \OWA\Core\CoreAPI::enqueueJob( 'publish-trackers', array(), 'publish-trackers:all' );

        } catch ( \Throwable $t ) {

            return false;
        }
    }

    /**
     * What the Tracking Tag screen says about a Profile's bundle.
     *
     * @param  string $site_id
     * @return array state ('published' | 'waiting' | 'unbuilt'), published_at (unix time or null)
     */
    public static function status( $site_id ) {

        $path = self::path( $site_id );

        if ( ! self::buildManifest() ) {

            return array( 'state' => 'unbuilt', 'published_at' => null );
        }

        $at = $path && is_file( $path ) ? (int) filemtime( $path ) : null;

        return array(
            'state'        => self::isCurrent( $site_id ) ? 'published' : 'waiting',
            'published_at' => $at,
        );
    }

    /**
     * Fetch a bundle's URL and read back the cache header it is served with
     * (PLAN 2.24.6). The .htaccess sets Cache-Control: no-cache where Apache
     * can, but a stock Apache may not read .htaccess or may lack the modules,
     * so the answer is whatever the server actually sent. Stored at install
     * scope for the Tracking Tag screen.
     *
     * @param  string $site_id
     * @return array checked_at, url, status (HTTP, 0 unreachable), ok (bool, or null
     *               when the answer was not the file), cache_control
     */
    public static function checkCacheHeaders( $site_id ) {

        $url     = self::url( $site_id );
        $context = stream_context_create( array( 'http' => array(
            'method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true,
            'header' => "User-Agent: Open Web Analytics tracker bundle check\r\n",
        ) ) );

        $headers = @get_headers( $url, true, $context );
        $result  = array( 'checked_at' => time(), 'url' => $url, 'status' => 0, 'ok' => null, 'cache_control' => '' );

        if ( is_array( $headers ) ) {

            $value = '';

            foreach ( $headers as $name => $v ) {

                // Status lines are numbered, one per response when there were
                // redirects; the last is the file's.
                if ( is_int( $name ) && preg_match( '#^HTTP/\S+\s+(\d{3})#', (string) $v, $m ) ) {

                    $result['status'] = (int) $m[1];
                }

                if ( is_string( $name ) && strtolower( $name ) === 'cache-control' ) {

                    $value = is_array( $v ) ? (string) end( $v ) : (string) $v;
                }
            }

            /*
             * Only a 2xx is the file. Anything else -- a firewall refusing the
             * server's own request, a 404 before the first publish -- says
             * nothing about the header visitors get, so the answer is "unknown",
             * not "missing".
             */
            if ( $result['status'] >= 200 && $result['status'] < 300 ) {

                $result['cache_control'] = $value;
                $result['ok'] = (bool) preg_match( '/\b(no-cache|max-age=0)\b/i', $value );
            }
        }

        \OWA\Core\CoreAPI::configSingleton()->persistSetting( 'base', 'tracker_cache_headers', $result );
        \OWA\Core\CoreAPI::configSingleton()->save();

        return $result;
    }

    /**
     * A comma-separated setting as a list, empty entries dropped.
     *
     * @param  mixed $value
     * @return string[]
     */
    public static function listOf( $value ) {

        if ( is_array( $value ) ) {

            $value = implode( ',', $value );
        }

        return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) );
    }
}

?>
