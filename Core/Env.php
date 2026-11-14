<?php
namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Where this installation is on disk, and which release it is.
 *
 * The code directories follow from this file's location: it lives in Core/,
 * one level below the root.
 *
 * The data and cache directories are settings (base.data_dir, base.cache_dir),
 * set at boot by Settings::applyConfigConstants() from the optional
 * OWA_DATA_DIR and OWA_CACHE_DIR in owa-config.php. Without them the data
 * directory is owa-data/ under the root and the cache directory is caches/
 * under the data directory.
 *
 * root() has no trailing slash; every directory ends in one.
 */
final class Env {

    /**
     * The release. The Release Packager (.github/workflows/main.yml) writes
     * the version into this line; a source checkout says 'master'.
     */
    const VERSION = 'master';

    public static function version(): string {

        return self::VERSION;
    }

    /** The checkout: where index.php, owa-config.php and Core/ are. */
    public static function root(): string {

        return dirname( __DIR__ );
    }

    public static function modulesDir(): string {

        return self::root() . '/modules/';
    }

    public static function confDir(): string {

        return self::root() . '/conf/';
    }

    public static function vendorDir(): string {

        return self::root() . '/vendor/';
    }

    /** owa-data/ under the root: the data directory when OWA_DATA_DIR is not set. */
    public static function defaultDataDir(): string {

        return self::root() . '/owa-data/';
    }

    /**
     * Where OWA writes: logs, the file event queue, caches, downloaded
     * user-agent patterns and GeoIP data. The base.data_dir setting.
     */
    public static function dataDir(): string {

        $dir = (string) CoreAPI::getSetting( 'base', 'data_dir' );

        return $dir === '' ? self::defaultDataDir() : self::withSlash( $dir );
    }

    /** The file cache. The base.cache_dir setting. */
    public static function cacheDir(): string {

        $dir = (string) CoreAPI::getSetting( 'base', 'cache_dir' );

        return $dir === '' ? self::dataDir() . 'caches/' : self::withSlash( $dir );
    }

    /** A directory path ending in exactly one slash. */
    public static function withSlash( $dir ): string {

        return rtrim( (string) $dir, '/' ) . '/';
    }
}
