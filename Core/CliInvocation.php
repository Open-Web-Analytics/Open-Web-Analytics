<?php

namespace OWA\Core;

/**
 * Decides whether the process was started from a shell rather than by a web
 * request.
 *
 * cli.php answers this before it loads anything, because a CLI front script
 * reached over HTTP would run commands with the privileges of whoever asked.
 * The SAPI name settles it in the ordinary case, but not in every case:
 *
 * A host that ships no `php` binary runs scripts as `php-cgi script.php args`.
 * The SAPI is then a CGI one even though the invocation is a genuine shell
 * invocation, so a SAPI test alone would refuse to run at all there.
 *
 * argc is no second signal: whether it is populated is governed by the
 * `register_argc_argv` ini setting, and under a CGI SAPI it can be filled in
 * from the request. So this checks for the request environment directly:
 * REQUEST_METHOD is set for every request a web SAPI serves and by nothing a
 * shell does. Ordering matters -- the SAPI test comes first, so a real CLI run
 * is never refused because the surrounding shell happened to export
 * REQUEST_METHOD into its environment, which the CLI SAPI copies into $_SERVER.
 *
 * Deliberately dependency-free, so cli.php can require it before it has loaded
 * anything else and still decide with the whole rule rather than a copy of it.
 */
final class CliInvocation {

    /**
     * @param  string $sapi   php_sapi_name()
     * @param  array<mixed> $server $_SERVER
     * @return bool
     */
    public static function detect( $sapi, array $server ) {

        // The ordinary case, and the only signal that is unambiguous.
        if ( $sapi === 'cli' ) {

            return true;
        }

        // Something is serving a request, so whatever else is true, this
        // process was not started by a person at a shell.
        if ( isset( $server['REQUEST_METHOD'] ) ) {

            return false;
        }

        // php-cgi used as an interpreter: no request in the environment, and
        // arguments counted on the command line.
        return isset( $server['argc'] )
            && is_numeric( $server['argc'] )
            && $server['argc'] > 0;
    }
}
