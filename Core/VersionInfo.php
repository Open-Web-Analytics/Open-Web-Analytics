<?php
namespace OWA\Core;

/**
 * What this code is, as data: its version and branch, what it needs of a
 * server (Requirements), each module's schema, and every schema update with
 * whether its down() can restore what its up() changed. release.php, which
 * cmd=version-info writes at build time and a release check reads from a
 * newer release before installing it: a PHP file that exits (GUARD), so a
 * request for it shows nothing on any web server, then the JSON. It is read
 * as text (decode()), never run: the installer reads it from a release it
 * has not installed.
 *
 * BOOT-FREE. No settings, no database, no CoreAPI, and no module is
 * constructed: a module's name is its class's NAME, its
 * required_schema_version is read as the class's declared default, and
 * whether an update must be installed by hand as its declared
 * $manual_install, by reflection. The updates are the ones
 * Module::updateClasses() finds, the list Module::update() applies.
 *
 * A module class without its own NAME is not listed, as it is not loaded
 * (Module::declaresName()). One that sets its schema only in its constructor
 * is listed without a schema.
 *
 * @phpstan-import-type RequirementsArray from Requirements
 * @phpstan-type UpdateInfo array{module: string, sequence: int, manual_install: string}
 * @phpstan-type VersionInfoArray array{format: int, version: string, branch: string|null, requirements: RequirementsArray, modules: array<string, int|null>, updates: list<UpdateInfo>}
 */
class VersionInfo {

    /** release.php's format: raised when a field changes meaning or goes. */
    const FORMAT = 1;

    /** The file, at the top of a release. */
    const FILE = 'release.php';

    /** release.php's first line: run, it exits before anything is shown. */
    const GUARD = "<?php exit; ?>\n";

    /**
     * release.php's contents for $info.
     *
     * @param  array<string, mixed> $info
     * @return string
     */
    public static function encode( array $info ) {

        return self::GUARD . json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    }

    /**
     * release.php's data, read as text; null when it is not release.php.
     *
     * @param  string|null $contents
     * @return array<string, mixed>|null
     */
    public static function decode( $contents ) {

        $contents = (string) $contents;

        if ( ! str_starts_with( $contents, self::GUARD ) ) {

            return null;
        }

        $data = json_decode( substr( $contents, strlen( self::GUARD ) ), true );

        return is_array( $data ) ? $data : null;
    }

    /**
     * @return VersionInfoArray
     */
    public static function toArray() {

        $modules = self::modules();
        $updates = [];

        foreach ( $modules as $name => $required ) {

            foreach ( Module::updateClasses( $name ) as $sequence => $class ) {

                if ( $required !== null && $sequence > $required ) {

                    continue;
                }

                $manual = class_exists( $class )
                    ? (string) ( ( new \ReflectionClass( $class ) )->getDefaultProperties()['manual_install'] ?? '' )
                    : '';

                $updates[] = [
                    'module'         => $name,
                    'sequence'       => $sequence,
                    'manual_install' => $manual,
                ];
            }
        }

        return [
            'format'       => self::FORMAT,
            'version'      => Env::version(),
            'branch'       => self::branch( Env::version() ),
            'requirements' => Requirements::toArray(),
            'modules'      => $modules,
            'updates'      => $updates,
        ];
    }

    /**
     * The release line a version belongs to: '2.x' for 2.0.3. Null for a
     * checkout, whose version is 'master'.
     *
     * @param  string $version
     * @return string|null
     */
    public static function branch( $version ) {

        return preg_match( '/^(\d+)\.\d+/', (string) $version, $m ) ? $m[1] . '.x' : null;
    }

    /**
     * Every module directory's module, name => required schema, Base first
     * and the rest by name.
     *
     * @return array<string, int|null>
     */
    public static function modules() {

        $out = [];

        foreach ( (array) glob( Env::modulesDir() . '*/Module.php' ) as $file ) {

            $class = '\\OWA\\Module\\' . basename( dirname( (string) $file ) ) . '\\Module';

            if ( ! Module::declaresName( $class ) ) {

                continue;
            }

            $defaults = ( new \ReflectionClass( $class ) )->getDefaultProperties();
            $name     = (string) $class::NAME;

            $out[ $name ] = isset( $defaults['required_schema_version'] ) ? (int) $defaults['required_schema_version'] : null;
        }

        uksort( $out, static fn ( $a, $b ) => [ $a !== 'base', $a ] <=> [ $b !== 'base', $b ] );

        return $out;
    }
}
