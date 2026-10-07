<?php
/**
 * The CLI reference. See lib/cli.php for where each column comes from.
 */

require_once __DIR__ . '/../lib/cli.php';

return function () {

    $commands = owa_wiki_cli_commands();

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

        // Base is always active; any other module's commands exist only while it is.
        if ( $c['module'] !== 'base' ) {
            $out .= "Available while the `{$c['module']}` module is active.\n\n";
        }

        if ( ! $c['arguments'] ) {

            $out .= "```bash\nphp cli.php cmd=$command\n```\n\nTakes no arguments.\n";

            continue;
        }

        $out .= "| Argument | Description |\n|---|---|\n";

        foreach ( $c['arguments'] as $arg => $desc ) {
            $out .= sprintf( "| `%s` | %s |\n", owa_wiki_cell( $arg ), owa_wiki_cell( $desc ) );
        }
    }

    return $out;
};
