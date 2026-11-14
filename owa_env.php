<?php

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
// $Id$
//

/**
 * Loads the class autoloader. Every entry point includes this, by way of
 * owa.php, before anything else.
 *
 * Where the installation is and which release it is: \OWA\Core\Env.
 */

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {

	require_once __DIR__ . '/vendor/autoload.php';

} else {

	/*
	 * No vendor/ -- a source checkout, or the GitHub "Source code" archive
	 * rather than the packaged release tarball.
	 *
	 * OWA's own classes are loaded by Composer's PSR-4 map, so without an
	 * autoloader the very next file fatals on `class owa extends
	 * \OWA\Core\Caller` and every request answers with a blank 500. That
	 * included install.php, whose environment check exists precisely to say
	 * "Dependencies: missing -- run composer install": it could never run.
	 *
	 * This registers the same two PSR-4 prefixes composer.json declares, for
	 * OWA's own code only. It deliberately does NOT stand in for the packages
	 * in vendor/; it boots far enough to reach the screen that explains they
	 * are missing. Nothing on that path needs one; the last that did was
	 * Monolog, which the error log no longer uses.
	 */
	spl_autoload_register( function ( $class ) {

		$prefixes = array(
			'OWA\\Core\\'   => __DIR__ . '/Core/',
			'OWA\\Module\\' => __DIR__ . '/modules/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {

			if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {

				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {

				require_once $file;
			}

			return;
		}
	} );
}
