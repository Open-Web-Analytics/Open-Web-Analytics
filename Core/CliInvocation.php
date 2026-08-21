<?php

namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * Decides whether the process was started from a shell rather than by a web
 * request.
 *
 * cli.php answers this before it loads anything, because a CLI front script
 * reached over HTTP would run commands with the privileges of whoever asked.
 * The SAPI name settles it in the ordinary case, but not in every case, which
 * is why this is more than a one-line comparison:
 *
 * A host that ships no `php` binary runs scripts as `php-cgi script.php args`.
 * The SAPI is then a CGI one even though the invocation is a genuine shell
 * invocation, so a SAPI test alone would refuse to run at all there. The
 * historical answer was to accept argc as a second signal -- if arguments were
 * counted, something must have passed them on a command line.
 *
 * That inference does not hold. Whether argc is populated is governed by the
 * `register_argc_argv` ini setting, and under a CGI SAPI it can be filled in
 * from the request rather than from a command line. So argc says "arguments
 * exist", never "a shell provided them", and on an installation whose ini
 * differs from the one the author had in mind, the second signal answers yes
 * for a request that is not a shell invocation at all.
 *
 * The fix is to stop asking argc to carry meaning it does not have, and to
 * check for the request environment directly: REQUEST_METHOD is set for every
 * request a web SAPI serves and by nothing a shell does. Ordering matters --
 * the SAPI test comes first, so a real CLI run is never refused because the
 * surrounding shell happened to export REQUEST_METHOD into its environment,
 * which the CLI SAPI copies into $_SERVER.
 *
 * Deliberately dependency-free, so cli.php can require it before it has loaded
 * anything else and still decide with the whole rule rather than a copy of it.
 *
 * @since owa 1.12.1
 */
final class CliInvocation {

    /**
     * @param string $sapi   php_sapi_name()
     * @param array  $server $_SERVER
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
