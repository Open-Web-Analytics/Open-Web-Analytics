<?php
/**
 * The CLI reference. See lib/cli.php for where each column comes from.
 *
 *   cli             every command: an index, then each command's section
 *   cli-<command>   one command's arguments table, for a page about what the
 *                   command does, e.g. cli-cube-rebuild
 */

require_once __DIR__ . '/../lib/cli.php';

return function ( $arg = null ) {

    $commands = owa_wiki_cli_commands();

    $arguments = static function ( array $c ) {

        $out = "| Argument | Description |\n|---|---|\n";

        foreach ( $c['arguments'] as $name => $desc ) {
            $out .= sprintf( "| `%s` | %s |\n", owa_wiki_cell( $name ), owa_wiki_cell( $desc ) );
        }

        return $out;
    };

    if ( $arg !== null ) {

        if ( ! isset( $commands[ $arg ] ) ) {
            throw new RuntimeException( "cli-$arg: no command by that name." );
        }

        return $commands[ $arg ]['arguments']
            ? $arguments( $commands[ $arg ] )
            : "`cmd=$arg` takes no arguments.\n";
    }

    $out = "Run every command from the installation directory:\n\n"
         . "```bash\nphp cli.php cmd=<command> [argument=value ...] [--switch ...]\n```\n\n"
         . "A switch can also be written `switch=1`.\n\n";

    /*
     * A LIST, NOT A TABLE. In a table the names sit in the narrowest column and
     * GitHub wraps them at every hyphen -- custom-dimension-deregister over three
     * lines -- and it strips the markup that would stop it. The capability and
     * module are in each command's own section.
     */
    foreach ( $commands as $command => $c ) {

        $first = preg_split( '/(?<=\.)\s/', $c['description'], 2 )[0];

        $out .= sprintf( "- [`%s`](#%s) — %s\n", $command, $command, $first );
    }

    foreach ( $commands as $command => $c ) {

        $out .= "\n### $command\n\n" . $c['description'] . "\n\n";

        if ( $c['capability'] ) {
            $out .= "Requires the `{$c['capability']}` capability.\n\n";
        }

        // full runs every command; the section names the other roles it runs on.
        $roles = array_values( array_diff( $c['roles'], array( \OWA\Core\ServerRole::FULL ) ) );
        $out  .= sprintf( "Runs on server roles: %s.\n\n",
            implode( ', ', array_map( fn ( $r ) => "`$r`", array_merge( array( \OWA\Core\ServerRole::FULL ), $roles ) ) ) );

        // Base is always active; any other module's commands exist only while it is.
        if ( $c['module'] !== 'base' ) {
            $out .= "Available while the `{$c['module']}` module is active.\n\n";
        }

        if ( ! $c['arguments'] ) {

            $out .= "```bash\nphp cli.php cmd=$command\n```\n\nTakes no arguments.\n";

            continue;
        }

        $out .= $arguments( $c );
    }

    return $out;
};
