<?php
namespace OWA\Core;

/**
 * Where this installation is on disk, and which release it is.
 *
 * The code directories follow from this file's location: it lives in Core/,
 * one level below the root.
 *
 * The data directory is a setting (base.data_dir), set at boot by
 * Settings::applyConfigConstants() from the optional OWA_DATA_DIR in
 * owa-config.php. Without it the data directory is owa-data/ under the root.
 *
 * root() has no trailing slash; every directory ends in one.
 */
final class Env {

    /**
     * The release. The Release Packager (.github/workflows/main.yml) writes
     * the version into this line; a source checkout says 'master'.
     */
    const VERSION = 'master';

    /**
     * The oldest release this release installs over, by hand or
     * automatically. cmd=version-info writes it into release.php as
     * upgrades_from, where a release check reads it (ReleaseCheck::judge()).
     */
    const UPGRADES_FROM = '1.14.0';

    public static function version(): string {

        return self::VERSION;
    }

    /** The checkout: where index.php, owa-config.php and Core/ are. */
    public static function root(): string {

        return dirname( __DIR__ );
    }

    /** OWA's own modules, which a release ships, namespace OWA\Module\<Dir>\. */
    public static function modulesDir(): string {

        return self::root() . '/modules/';
    }

    /**
     * Third-party modules, namespace OWA\Extension\<Dir>\: never shipped,
     * never touched by an install. Need not exist.
     */
    public static function extensionsDir(): string {

        return self::root() . '/extensions/';
    }

    /**
     * Where modules are found, OWA's first: namespace prefix => directory.
     *
     * @return array<string, string>
     */
    public static function moduleRoots(): array {

        return [ 'OWA\\Module\\' => self::modulesDir(), 'OWA\\Extension\\' => self::extensionsDir() ];
    }

    /**
     * The directory, ending in a slash, of the module $name names (its
     * runtime name or its directory): modules/<Dir>/ when OWA has it, else
     * extensions/<Dir>/ when there is one, else modules/<Dir>/.
     *
     * @param  string $name
     */
    public static function moduleDir( $name ): string {

        $dir = Lib::moduleDirName( (string) $name );

        return ( self::isExtension( $dir ) ? self::extensionsDir() : self::modulesDir() ) . $dir . '/';
    }

    /**
     * The namespace of the module $name names, without a trailing backslash:
     * OWA\Module\<Dir>, or OWA\Extension\<Dir> for an extension (moduleDir()).
     *
     * @param  string $name
     */
    public static function moduleNamespace( $name ): string {

        $dir = Lib::moduleDirName( (string) $name );

        return ( self::isExtension( $dir ) ? 'OWA\\Extension\\' : 'OWA\\Module\\' ) . $dir;
    }

    /**
     * Every module class present, boot-free: OWA's (modules/<Dir>/Module.php),
     * then the extensions', each declaring its own NAME (Module::declaresName()).
     * An extension with the directory or the NAME of one of OWA's is left
     * out, as CoreAPI::getPresentModules() refuses it.
     *
     * @return list<class-string<Module>>
     */
    public static function moduleClasses(): array {

        $out   = [];
        $dirs  = [];
        $names = [];

        foreach ( self::moduleRoots() as $prefix => $root ) {

            foreach ( (array) glob( $root . '*/Module.php' ) as $file ) {

                $dir   = basename( dirname( (string) $file ) );
                $class = $prefix . $dir . '\\Module';

                if ( isset( $dirs[ $dir ] ) || ! Module::declaresName( $class ) || isset( $names[ (string) $class::NAME ] ) ) {

                    continue;
                }

                $dirs[ $dir ]                   = true;
                $names[ (string) $class::NAME ] = true;
                $out[]                          = $class;
            }
        }

        return $out;
    }

    /** Whether the module directory $dir is an extension's: there is none of OWA's by that name. */
    private static function isExtension( string $dir ): bool {

        return $dir !== '' && ! is_dir( self::modulesDir() . $dir ) && is_dir( self::extensionsDir() . $dir );
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

    /**
     * A directory path ending in exactly one slash.
     *
     * @param  string $dir
     */
    public static function withSlash( $dir ): string {

        return rtrim( (string) $dir, '/' ) . '/';
    }
}
