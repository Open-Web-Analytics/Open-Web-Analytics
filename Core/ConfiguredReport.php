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
 * The controller every configured report is rendered by.
 *
 * A config-driven report controller never did anything but name a subview, a
 * title, and a bag of values for that subview to read -- so there is one
 * controller here and the differences live in JSON. This class is what makes
 * 35 near-identical files unnecessary rather than what replaces them.
 *
 * It deliberately does NOT interpret the settings bag. Those keys are the
 * subview's vocabulary, not this class's: ReportSimpleDimensional copies eleven
 * of them into its template and would keep working if a twelfth appeared. A
 * whitelist here would mean adding a key in two places and getting a silently
 * empty widget when someone added it in one.
 *
 * Lives in Core rather than in the Base module because it is the machinery all
 * modules' reports run on -- and because a file named Report*.php under
 * modules/Base/Controller is, correctly, treated as a report by the
 * characterization harness.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class ConfiguredReport extends \OWA\Core\ReportController {

    /**
     * Top-level keys a report definition may use.
     *
     * Checked, and an unknown one refused, because the failure it prevents is
     * silent: a definition with "titel" would render a report with no title and
     * nothing anywhere saying why. The settings bag inside is deliberately not
     * checked -- see the class comment.
     */
    const KNOWN_KEYS = array( 'title', 'titleSuffix', 'view', 'subview', 'settings' );

    /** @var array the decoded definition */
    private $definition = array();

    /**
     * @param array $definition a decoded report definition
     */
    public function setDefinition( array $definition ) {

        $this->definition = $definition;
    }

    /**
     * Why a definition cannot be rendered, or '' if it can.
     *
     * Static so the same answer is available to a test, or to a future
     * validator over the whole reports directory, without building a
     * controller -- which needs a request and a database.
     *
     * @param mixed $definition
     * @return string
     */
    public static function getDefinitionError( $definition ) {

        if ( ! is_array( $definition ) ) {

            return 'a report definition must be an object';
        }

        foreach ( array( 'title', 'subview' ) as $required ) {

            if ( ! isset( $definition[ $required ] ) || $definition[ $required ] === '' ) {

                return sprintf( 'a report definition needs a "%s"', $required );
            }
        }

        $unknown = array_diff( array_keys( $definition ), self::KNOWN_KEYS );

        if ( $unknown ) {

            return sprintf( 'unknown key(s) %s; a report definition may use %s',
                implode( ', ', $unknown ), implode( ', ', self::KNOWN_KEYS ) );
        }

        if ( isset( $definition['settings'] ) && ! is_array( $definition['settings'] ) ) {

            return '"settings" must be an object';
        }

        return '';
    }

    /**
     * Declare exactly what the controller this replaces declared.
     *
     * The order is the order those controllers used -- subview, then title,
     * then the settings -- so that a setting named "title" would lose to the
     * title, as it did before. Nothing relies on that today; it is simply not
     * this change's business to alter it.
     */
    function action() {

        $d = $this->definition;

        /*
         * pre() already sets this, and four of the converted reports set it
         * again in their action(). Kept so a definition can say so explicitly
         * and produce a byte-identical result to the controller it replaced.
         */
        if ( ! empty( $d['view'] ) ) {

            $this->setView( $d['view'] );
        }

        $this->setSubview( $d['subview'] );

        $this->setTitle( $d['title'], isset( $d['titleSuffix'] ) ? $d['titleSuffix'] : '' );

        foreach ( (array) ( isset( $d['settings'] ) ? $d['settings'] : array() ) as $key => $value ) {

            $this->set( $key, $value );
        }
    }
}
