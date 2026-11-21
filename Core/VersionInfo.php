<?php
namespace OWA\Core;

/**
 * What this code is, as data: its version and branch, what it needs of a
 * server (Requirements), each module's schema, and every schema update with
 * whether its down() can restore what its up() changed. release.json, which
 * cmd=version-info writes at build time and a release check reads from a
 * newer release before installing it.
 *
 * BOOT-FREE. No settings, no database, no CoreAPI, and no module is
 * constructed: a module's name and required_schema_version are read as its
 * class's declared defaults, and an update's lossiness as its declared
 * $down_does_not_restore, by reflection. The updates are the ones
 * Module::updateClasses() finds, the list Module::update() applies.
 *
 * A module that sets its name or schema only in its constructor (an older
 * third-party one) is listed without a schema.
 *
 * @phpstan-import-type RequirementsArray from Requirements
 * @phpstan-type UpdateInfo array{module: string, sequence: int, lossy: bool, does_not_restore: string}
 * @phpstan-type VersionInfoArray array{format: int, version: string, branch: string|null, requirements: RequirementsArray, modules: array<string, int|null>, updates: list<UpdateInfo>}
 */
class VersionInfo {

    /** release.json's format: raised when a field changes meaning or goes. */
    const FORMAT = 1;

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

                $reason = class_exists( $class )
                    ? (string) ( ( new \ReflectionClass( $class ) )->getDefaultProperties()['down_does_not_restore'] ?? '' )
                    : '';

                $updates[] = [
                    'module'           => $name,
                    'sequence'         => $sequence,
                    'lossy'            => $reason !== '',
                    'does_not_restore' => $reason,
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

            if ( ! class_exists( $class ) ) {

                continue;
            }

            $defaults = ( new \ReflectionClass( $class ) )->getDefaultProperties();
            $name     = (string) ( $defaults['name'] ?? '' );

            if ( $name === '' ) {

                continue;
            }

            $out[ $name ] = isset( $defaults['required_schema_version'] ) ? (int) $defaults['required_schema_version'] : null;
        }

        uksort( $out, static fn ( $a, $b ) => [ $a !== 'base', $a ] <=> [ $b !== 'base', $b ] );

        return $out;
    }
}
