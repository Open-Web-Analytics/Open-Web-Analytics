<?php
namespace OWA\Core;

/**
 * Which command, if any, cli.php runs before `new owa()`: one whose
 * controller answers false to Controller\Cli::needsBoot().
 *
 * Found from the files alone. Every module directory present is read, and
 * the one whose config/cli_commands.php names the command decides: its action
 * is looked up in that module's config/actions.php, then in every module's.
 * A name two modules declare boots, so boot's own rule (a later module's
 * declaration replaces an earlier one, Service::loadCliCommands()) settles
 * it. Both files are plain return arrays, read in a scope of their own. A
 * command a module registers in code is not seen here; it boots as usual.
 *
 * Nothing is booted to answer: no settings, no database, and only the
 * command's own class is autoloaded.
 */
class CliWithoutBoot {

    /**
     * The class that runs $command without boot, or null when it boots (or is
     * not a command at all, which boot then says).
     *
     * @param  string $command the cmd= value
     * @return class-string<Controller\Cli>|null
     */
    public static function commandClass( $command ) {

        $dirs     = self::moduleDirs();
        $declared = array_values( array_filter( $dirs,
            static fn ( $dir ) => isset( self::config( $dir, 'cli_commands' )[ $command ] ) ) );

        if ( count( $declared ) !== 1 ) {

            return null;
        }

        $action = (string) self::config( $declared[0], 'cli_commands' )[ $command ];
        $class  = self::config( $declared[0], 'actions' )[ $action ] ?? null;

        foreach ( $dirs as $other ) {

            $class = $class ?? ( self::config( $other, 'actions' )[ $action ] ?? null );
        }

        if ( ! is_string( $class ) || ! class_exists( $class ) || ! is_subclass_of( $class, Controller\Cli::class ) ) {

            return null;
        }

        /** @var class-string<Controller\Cli> $class */
        return $class::needsBoot() ? null : $class;
    }

    /**
     * The module directories present: Base first, then by name.
     *
     * @return list<string> directory names, such as Base and MaxmindGeoip
     */
    public static function moduleDirs() {

        $dirs = array_map( static fn ( $path ) => basename( (string) $path ),
            (array) glob( Env::modulesDir() . '*', GLOB_ONLYDIR ) );

        usort( $dirs, static fn ( $a, $b ) => [ $a !== 'Base', $a ] <=> [ $b !== 'Base', $b ] );

        return $dirs;
    }

    /**
     * One module's config/<file>.php, or [] when it has none.
     *
     * @param  string $dir
     * @param  string $file cli_commands or actions
     * @return array<string, mixed>
     */
    private static function config( $dir, $file ) {

        $path = Env::modulesDir() . $dir . '/config/' . $file . '.php';

        if ( ! is_file( $path ) ) {

            return [];
        }

        $read = static fn ( $path ) => include $path;

        return (array) $read( $path );
    }
}
