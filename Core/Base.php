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

namespace OWA\Core;

/**
 * OWA Base Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

// The Base hierarchy sets properties it does not declare. PHP 8.2 deprecates
// that except on a class carrying this attribute, which is PHP's supported way
// to keep it.
#[\AllowDynamicProperties]
class Base {

    /**
     * Configuration: the base module's settings, key => value
     *
     * @var array<string, mixed> a setting's value is any type
     */
    public $config;

    /**
     * Error Logger
     *
     * @var object
     */
    public $e;

    /**
     * Configuration Entity
     *
     * @var \OWA\Module\Base\Classes\Settings  Object global configuration object
     */
    public $c;

    /**
     * Module that this class belongs to
     *
     * @var string
     */
    public $module;

    /**
     * Request Params
     *
     * @var array<mixed> name => value as the request carried it
     */
    public $params;

    /**
     * Base Constructor
     */
    public function __construct() {
        // Nothing is fetched here on purpose. Unsetting these declared
        // properties is what routes the first read of each through __get below,
        // which is where they are actually built. See that method for why.
        unset( $this->e, $this->c, $this->config );
    }

    /**
     * Builds $e, $c and $config on first read rather than at construction.
     *
     * WHY THIS IS NOT AN OPTIMISATION
     * Constructing any object used to require a working settings system, and
     * settings cannot be built without constructing objects. Settings::__construct
     * loads the config file, then creates the base.configuration ENTITY, and only
     * then applies the config constants as settings -- so the entity is built
     * while the settings object that will hold its values is still half-built.
     *
     * That worked only because three classes -- Settings, Entity and Module --
     * were kept outside this hierarchy, so nothing on that path ever ran this
     * constructor. It was a real constraint enforced by nothing: giving entities
     * a $this->c "like every other class" would have had configSingleton()
     * re-entered before its own static was assigned, building a second Settings,
     * a second entity, and so on until the stack ran out. On every request.
     *
     * Reading late instead of eagerly removes the cycle rather than dodging it.
     * Construction touches nothing, so the first read happens when someone
     * actually wants a value, by which time settings exist.
     *
     * $config is a snapshot of the base settings, and it is now taken at first
     * read rather than at construction. Where that differs, later is the more
     * correct of the two: an object built before a module applied its overrides
     * used to keep the values from before them.
     *
     * @param  string $name
     * @return \OWA\Module\Base\Classes\Error|\OWA\Module\Base\Classes\Settings|array<string, mixed>|null
     *         $e, $c, $config; null for any other name
     */
    public function __get( $name ) {

        switch ( $name ) {

            case 'e':
                return $this->e = \OWA\Core\CoreAPI::errorSingleton();

            case 'c':
                return $this->c = \OWA\Core\CoreAPI::configSingleton();

            case 'config':
                // $this->c, not the singleton directly: if a subclass has
                // already been handed a different settings object, the snapshot
                // must come from that one.
                return $this->config = $this->c->fetch( 'base' );
        }

        // Anything else is an ordinary undefined property, and must still read
        // like one rather than silently becoming null.
        trigger_error(
            sprintf( 'Undefined property: %s::$%s', get_class( $this ), $name ),
            E_USER_WARNING
        );

        return null;
    }

    /**
     * isset() and empty() have to agree with __get, or a lazy property reads as
     * missing -- including isset($this->config['some_key']), which asks about
     * the property first and only then about the offset.
     *
     * @param  string $name
     * @return bool
     */
    public function __isset( $name ) {

        return in_array( $name, [ 'e', 'c', 'config' ], true );
    }

    /**
     * Retrieves string message from mesage file
     *
     * @param  int|string|null $code a messages.php key; a string when a request
     *                              carried it
     * @param  array<string, mixed> $substitutions message part => its vsprintf()
     *                                            argument list, or a single argument
     * @return array<string, string> part (headline, message) => text; empty for an
     *                               unknown code
     */
    public function getMsg($code, $substitutions = []) {

        static $_owa_messages;

        $msg = array();

        if (empty($_owa_messages)) {
            require(\OWA\Core\Env::confDir() . 'messages.php');
        }

        if ( $code && array_key_exists( $code, $_owa_messages ) ) {

            $msg = $_owa_messages[$code];
			
			// Substitutions are keyed by the message part they fill in, and each
			// value is that part's vsprintf() argument list. A caller that fills
			// in only one part must leave the other one untouched, and a caller
			// passing a bare scalar means a single argument -- handing either of
			// those straight to vsprintf() is a TypeError on PHP 8.
			foreach ( ['headline', 'message'] as $part ) {

				if ( isset( $msg[ $part ] ) && isset( $substitutions[ $part ] ) ) {

					$msg[ $part ] = vsprintf( $msg[ $part ], (array) $substitutions[ $part ] );
				}
			}
        }

        return $msg;
    }

    /**
     * @param  int|string|null $code as getMsg() takes it
     * @param  array<string, mixed> $substitutions as getMsg() takes them
     * @return string
     */
    public function getMsgAsString($code, $substitutions = [])
    {
        $msg = $this->getMsg($code, $substitutions);

        return implode(' ', array_values($msg));
    }

}

?>