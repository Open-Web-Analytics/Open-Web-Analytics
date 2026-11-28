<?php
namespace OWA\Core;

/**
 * Third-party modules: extensions/<Dir>/, namespace OWA\Extension\<Dir>\,
 * a Module class extending Core\Module with its own NAME, as any module.
 * An install never touches extensions/ (updater.php `keeps`).
 *
 * OWA's own modules, in modules/, register first. An extension that declares
 * what a module loaded before it registers -- its NAME, an entity's table, a
 * CLI command, a scheduled job or a tracking event type -- is refused
 * (clashes(), and CoreAPI::getPresentModules() for a NAME or directory of
 * OWA's): it is not loaded, nothing of it is registered, and it is not
 * activated. A REST route is /<module>/<version>/<route>, so only a NAME of
 * OWA's could take one of OWA's routes. Each refusal is a notice, once per
 * process, and a System Health finding (refused()).
 *
 * Until it is added (Service::addModule()), an extension's REST routes,
 * implementations and tracking properties wait in the module
 * (Module::$pending_registrations): a refused one's never reach the service,
 * and an added one's never replace what is registered already.
 */
final class Extensions {

    const PREFIX = 'OWA\\Extension\\';

    /** @var array<string, string> extension directory => why it was refused */
    private static $refused = [];

    /**
     * Whether $module, an object or a class name, is an extension's.
     *
     * @param  object|string $module
     */
    public static function isExtension( $module ): bool {

        $class = ltrim( is_object( $module ) ? get_class( $module ) : (string) $module, '\\' );

        return strncmp( $class, self::PREFIX, strlen( self::PREFIX ) ) === 0;
    }

    /**
     * The extension's directory, from its class: OWA\Extension\<Dir>\Module.
     *
     * @param  object|string $module
     */
    public static function dirOf( $module ): string {

        $class = ltrim( is_object( $module ) ? get_class( $module ) : (string) $module, '\\' );

        return (string) explode( '\\', $class )[2];
    }

    /**
     * Record an extension as refused, with why: a notice the first time.
     *
     * @param  string $dir
     * @param  string $why one sentence, or several
     */
    public static function refuse( $dir, $why ): void {

        if ( isset( self::$refused[ (string) $dir ] ) ) {

            return;
        }

        self::$refused[ (string) $dir ] = (string) $why;
        CoreAPI::notice( (string) $why );
    }

    /**
     * The extensions refused in this process, and why.
     *
     * @return array<string, string> directory => why
     */
    public static function refused(): array {

        return self::$refused;
    }

    /** Forget the refusals: CoreAPI::forgetSingletons(), and a test's. */
    public static function forget(): void {

        self::$refused = [];
    }

    /**
     * What the extension $ext, built, declares that a module of $loaded
     * registers already, one sentence each: [] when nothing clashes.
     *
     * @param  Module                $ext
     * @param  array<string, Module> $loaded name => module, in the order they were loaded
     * @return list<string>
     */
    public static function clashes( Module $ext, array $loaded ): array {

        $dir  = self::dirOf( $ext );
        $out  = [];
        $say  = static function ( $kind, $name, $owner ) use ( $dir, &$out ) {

            $out[] = sprintf( CoreAPI::t( 'extensions/%1$s is not loaded: its %2$s %3$s is registered already, by the module %4$s. Rename it in the extension.' ),
                $dir, $kind, $name, $owner );
        };

        if ( isset( $loaded[ $ext->name ] ) ) {

            $say( CoreAPI::t( 'name' ), $ext->name, $ext->name );

            return $out;
        }

        $owners = [ 'table' => [], 'command' => [], 'job' => [], 'event' => [] ];

        foreach ( $loaded as $name => $m ) {

            foreach ( self::tables( $m ) as $t ) {

                $owners['table'][ $t ] = $owners['table'][ $t ] ?? (string) $name;
            }

            foreach ( array_keys( (array) $m->cli_commands ) as $c ) {

                $owners['command'][ (string) $c ] = $owners['command'][ (string) $c ] ?? (string) $name;
            }

            foreach ( array_keys( (array) $m->scheduled_jobs ) as $j ) {

                $owners['job'][ (string) $j ] = $owners['job'][ (string) $j ] ?? (string) $name;
            }

            foreach ( array_keys( (array) $m->event_processors ) as $e ) {

                $owners['event'][ (string) $e ] = $owners['event'][ (string) $e ] ?? (string) $name;
            }
        }

        $declared = [
            'table'   => [ CoreAPI::t( 'table' ), self::tables( $ext ) ],
            'command' => [ CoreAPI::t( 'CLI command' ), array_map( 'strval', array_keys( (array) $ext->cli_commands ) ) ],
            'job'     => [ CoreAPI::t( 'scheduled job' ), array_map( 'strval', array_keys( (array) $ext->scheduled_jobs ) ) ],
            'event'   => [ CoreAPI::t( 'event type' ), array_map( 'strval', array_keys( (array) $ext->event_processors ) ) ],
        ];

        foreach ( $declared as $key => [ $kind, $names ] ) {

            foreach ( $names as $n ) {

                if ( isset( $owners[ $key ][ $n ] ) ) {

                    $say( $kind, $n, $owners[ $key ][ $n ] );
                }
            }
        }

        return $out;
    }

    /**
     * The tables a module's entities have, prefixed. An entity that cannot be
     * built is left out: building it fails as it would without the check.
     *
     * @return list<string>
     */
    private static function tables( Module $m ): array {

        $out = [];

        foreach ( (array) $m->entities as $entity ) {

            try {

                $out[] = (string) CoreAPI::entityFactory( $m->name . '.' . $entity )->getTableName();

            } catch ( \Throwable $e ) {

                continue;
            }
        }

        return $out;
    }
}
