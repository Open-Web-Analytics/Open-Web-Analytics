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
    const KNOWN_KEYS = array( 'title', 'titleSuffix', 'view', 'subview', 'params', 'metrics', 'widgets', 'settings' );

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

        if ( isset( $definition['params'] ) && ! is_array( $definition['params'] ) ) {

            return '"params" must be an object of parameter name => options';
        }

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $i => $widget ) {

            if ( ! is_array( $widget ) || empty( $widget['type'] ) ) {

                return sprintf( 'widget %s needs a "type"', $i );
            }

            if ( $widget['type'] !== 'report-links' ) {

                continue;
            }

            /*
             * A link with no target renders as an anchor to the report
             * dispatcher with no id, which answers "no reportId was given" --
             * a 400 where the author expected a report.
             */
            foreach ( (array) ( $widget['links'] ?? array() ) as $j => $link ) {

                foreach ( array( 'reportId', 'label' ) as $required ) {

                    if ( empty( $link[ $required ] ) ) {

                        return sprintf( 'report link %s needs a "%s"', $j, $required );
                    }
                }
            }

            if ( empty( $widget['links'] ) ) {

                return 'a report-links widget with no links renders an empty list';
            }
        }

        /*
         * An unconstrained detail report is the failure worth refusing: it
         * renders every row rather than the one asked for, which reads as a
         * data bug and not as a broken definition.
         */
        $constraints = isset( $definition['settings']['constraints'] )
            ? $definition['settings']['constraints']
            : null;

        if ( is_array( $constraints ) ) {

            foreach ( $constraints as $i => $part ) {

                if ( ! is_array( $part ) || empty( $part['dimension'] ) ) {

                    return sprintf( 'constraint %s needs a "dimension"', $i );
                }

                if ( ! array_key_exists( 'fromParam', $part ) && ! array_key_exists( 'value', $part ) ) {

                    return sprintf( 'constraint on "%s" needs either a "value" or a "fromParam"',
                        $part['dimension'] );
                }

                $declared = (array) ( $definition['params'] ?? array() );

                if ( array_key_exists( 'fromParam', $part )
                     && ! array_key_exists( $part['fromParam'], $declared ) ) {

                    // Otherwise the constraint silently becomes `dimension==`,
                    // matching nothing, with no clue as to why.
                    return sprintf( 'constraint on "%s" reads the undeclared parameter "%s"',
                        $part['dimension'], $part['fromParam'] );
                }
            }
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

        $values = $this->resolveParams();

        /*
         * pre() already sets this, and four of the converted reports set it
         * again in their action(). Kept so a definition can say so explicitly
         * and produce a byte-identical result to the controller it replaced.
         */
        if ( ! empty( $d['view'] ) ) {

            $this->setView( $d['view'] );
        }

        $this->setSubview( $d['subview'] );

        /*
         * Widgets are a setting as far as the view is concerned -- the subview
         * reads them like anything else. They are a TOP-LEVEL key in the
         * definition rather than one more entry in `settings` because they are
         * the report's structure, not one of its knobs, and because
         * getDefinitionError() has to be able to check them.
         */
        /*
         * Report-wide, like the constraint and for the same reason: every
         * widget in a report asks for the same metrics -- measured across all
         * 13 multi-widget reports, without exception. Holding it once is also
         * what lets a metric set replace it in ONE place rather than rewriting
         * every widget's query.
         */
        if ( isset( $d['metrics'] ) ) {

            $this->set( 'metrics', self::interpolate( $d['metrics'], $values ) );
        }

        if ( isset( $d['widgets'] ) ) {

            $this->set( 'widgets', self::interpolateDeep( $d['widgets'], $values ) );
        }

        $this->setTitle(
            self::interpolate( $d['title'], $values ),
            self::interpolate( isset( $d['titleSuffix'] ) ? $d['titleSuffix'] : '', $values ) );

        foreach ( (array) ( isset( $d['settings'] ) ? $d['settings'] : array() ) as $key => $value ) {

            if ( $key === 'constraints' && is_array( $value ) ) {

                $value = self::buildConstraints( $value, $values );

            } else {

                // Anywhere else a value is authored, a placeholder means the
                // same thing. ReportBrowserDetail puts its parameter inside
                // dimension_properties rather than in the title or the
                // constraint, and a substitution that only knew about those two
                // places would leave it holding the literal text "{browserType}".
                $value = self::interpolateDeep( $value, $values );
            }

            $this->set( $key, $value );
        }
    }

    /**
     * Read the request parameters this report declares, applying the one
     * normalisation any of them asks for.
     *
     * Declared rather than inferred from where they are used, so a definition
     * says what it reads. It is also what lets a test supply every parameter a
     * report takes without parsing the report.
     *
     * @return array<string,string> parameter name => value
     */
    private function resolveParams() {

        $values = array();

        foreach ( (array) ( isset( $this->definition['params'] ) ? $this->definition['params'] : array() ) as $name => $spec ) {

            // A definition decoded with json_decode($s, true) hands this an
            // array, which is how the dispatcher reads one. Cast anyway: decoded
            // the other way an empty {} is a stdClass, and indexing that is a
            // fatal rather than a missing option.
            $spec  = (array) $spec;
            $value = (string) $this->getParam( $name );

            /*
             * Three of the detail reports lowercase before constraining --
             * ad, adType and campaign -- because the dimension is stored
             * lowercased. Dropping that would make "Google" and "google"
             * different ads.
             */
            if ( ! empty( $spec['lowercase'] ) ) {

                $value = strtolower( $value );
            }

            $values[ $name ] = $value;
        }

        return $values;
    }

    /**
     * Replace {name} with a parameter's value.
     *
     * Deliberately only substitution -- no filters, no expressions. The one
     * transformation a parameter can need is declared on the parameter itself,
     * so this stays a thing config can safely be rather than a small language
     * that has to be evaluated.
     *
     * @param mixed $template
     * @param array $values
     * @return string
     */
    private static function interpolate( $template, array $values ) {

        $template = (string) $template;

        if ( strpos( $template, '{' ) === false ) {

            return $template;
        }

        foreach ( $values as $name => $value ) {

            $template = str_replace( '{' . $name . '}', $value, $template );
        }

        return $template;
    }

    /**
     * Interpolate through a value of any shape.
     *
     * Arrays are walked because settings hold them -- dimension_properties and
     * dimensionLink both nest. Non-strings are returned untouched, so an
     * integer like resultsPerPage stays an integer.
     *
     * @param mixed $value
     * @param array $values
     * @return mixed
     */
    private static function interpolateDeep( $value, array $values ) {

        if ( is_array( $value ) ) {

            foreach ( $value as $k => $v ) {

                $value[ $k ] = self::interpolateDeep( $v, $values );
            }

            return $value;
        }

        return is_string( $value ) ? self::interpolate( $value, $values ) : $value;
    }

    /**
     * Build a constraint string from its parts.
     *
     * Structured rather than a string with placeholders, because the two kinds
     * of value are encoded differently: a value taken from the request is
     * urlencoded, a literal is not. Expressed as one template string, that
     * difference would have to be spelled with a filter -- and every author
     * would have to remember which side of the '==' they were on.
     *
     * `constraints` may still be a plain string, which is used as-is. Most
     * reports constrain on nothing that varies.
     *
     * @param array $parts
     * @param array $values
     * @return string
     */
    private static function buildConstraints( array $parts, array $values ) {

        $out = array();

        foreach ( $parts as $part ) {

            $operator = isset( $part['operator'] ) ? $part['operator'] : '==';

            if ( array_key_exists( 'fromParam', $part ) ) {

                $name  = $part['fromParam'];
                $value = urlencode( isset( $values[ $name ] ) ? $values[ $name ] : '' );

            } else {

                $value = isset( $part['value'] ) ? $part['value'] : '';
            }

            $out[] = $part['dimension'] . $operator . $value;
        }

        return implode( ',', $out );
    }
}
