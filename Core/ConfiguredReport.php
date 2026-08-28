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
 * subview's vocabulary, not this class's -- a subview reads what it needs and
 * keeps working when a new key appears. A whitelist here would mean adding a
 * key in two places and getting a silently empty widget when someone added it
 * in one.
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
    const KNOWN_KEYS = array( 'title', 'titleSuffix', 'params', 'metrics', 'widgets', 'settings', 'deprecated', 'metricSets' );

    /**
     * How many rows a table CARD shows when it names no page size.
     *
     * A card's height is its row count, so this is a layout number as much as a
     * query one: cards sit beside each other, and a row of them that each chose
     * their own page size is a row of different heights.
     *
     * Ten rather than the reporting API's own default of twenty-five, which is
     * a full-width table's number. Twenty-five rows in a quarter-row card is a
     * column of figures taller than everything beside it, and a card exists to
     * be read at a glance and then followed to the full report.
     */
    const DEFAULT_CARD_ROWS = 10;

    /**
     * Column formatters a grid widget may name.
     *
     * A NAME, never a function. Formatters are implemented in the grid widget
     * (jQuery.fn.fmatter in owa.resultSetExplorer.js) and a definition selects
     * one; the definition cannot carry code, which is the gate on report
     * configuration ever being user-authored. Same reason excludeColumns is a
     * list of names rather than a fragment of script.
     *
     * Add a name here and implement it there, in that order -- an unknown name
     * is refused, so a typo reads as an error rather than as an unformatted
     * column.
     */
    const KNOWN_FORMATTERS = array( 'attributionList' );

    /**
     * Metric lists a widget can ask for by NAME instead of spelling out.
     *
     * `"metrics": "@activeGoalCompletions"` resolves per site, because the
     * metrics only exist per site: one per goal the site has configured. A
     * static list cannot say that, and it is the only thing standing between
     * the goals report and being a definition like the rest.
     *
     * Whitelisted for the same reason formatters are -- the value reaches a
     * query -- and kept deliberately small. A report that wants an arbitrary
     * derived list wants a controller.
     */
    const KNOWN_METRIC_SOURCES = array( 'activeGoalCompletions' );

    /**
     * The renderer every configured report uses.
     *
     * Fixed here rather than named by each definition. It was a definition key
     * while the conversion was in progress and reports still rendered through
     * a dozen different subviews; all 53 named this one by the end, so it was
     * a required field with exactly one legal value.
     *
     * Removing it is not only tidiness: a report definition is meant to become
     * something a user can author, and a definition that names a renderer can
     * point at any view in the tree. Widgets already own their own rendering,
     * which is what makes this safe to fix.
     */
    const SUBVIEW = 'base.reportWidgets';

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

        foreach ( array( 'title' ) as $required ) {

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

            if ( isset( $widget['more'] ) && empty( $widget['more']['reportId'] ) ) {

                return sprintf( 'widget %s has a "more" link with no reportId', $i );
            }

            /*
             * A list of column names, never a fragment of JavaScript.
             *
             * This used to be echoed raw into a JS array literal, so the
             * definitions carried their own quoting -- "'pageUrl'". A string
             * here would be interpolated again the moment someone restored
             * that, so the format refuses one outright.
             */
            if ( isset( $widget['excludeColumns'] ) && ! is_array( $widget['excludeColumns'] ) ) {

                return sprintf( 'widget %s: "excludeColumns" must be a list of column names', $i );
            }

            /*
             * `formatters` maps a column to the NAME of a formatter the widget
             * implements. The value it replaces was a JavaScript function
             * carried in a controller and echoed raw into the page.
             */
            if ( isset( $widget['formatters'] ) ) {

                if ( ! is_array( $widget['formatters'] ) ) {

                    return sprintf(
                        'widget %s: "formatters" must map a column name to a formatter name', $i );
                }

                foreach ( $widget['formatters'] as $column => $formatter ) {

                    if ( ! is_string( $formatter )
                        || ! in_array( $formatter, self::KNOWN_FORMATTERS, true ) ) {

                        return sprintf(
                            'widget %s: "%s" is not a formatter this grid implements; it has %s',
                            $i, is_string( $formatter ) ? $formatter : gettype( $formatter ),
                            implode( ', ', self::KNOWN_FORMATTERS ) );
                    }
                }
            }

            /*
             * `valueLabels` renames a dimension's VALUES for display.
             *
             * A boolean dimension stores 1 and 0, and the generic formatter
             * turns those into Yes and No -- correct, but a pie of "Yes 62% /
             * No 38%" makes the reader supply the question from the title. The
             * labels belong to the report, not to the dimension: the same
             * column reads as New/Repeat here and could reasonably read as
             * something else elsewhere.
             *
             * Keys are the RAW values as strings, because that is what the
             * query returns and what a definition author can see in the data.
             */
            if ( isset( $widget['valueLabels'] ) ) {

                if ( ! is_array( $widget['valueLabels'] ) ) {

                    return sprintf(
                        'widget %s: "valueLabels" must map a dimension value to a label', $i );
                }

                foreach ( $widget['valueLabels'] as $value => $label ) {

                    if ( ! is_string( $label ) ) {

                        return sprintf(
                            'widget %s: the label for value "%s" must be a string, not %s',
                            $i, $value, gettype( $label ) );
                    }
                }
            }

            /*
             * A widget may name a derived metric list rather than spell one
             * out. Checked here so a typo is a definition error at load rather
             * than a query for a metric named "@activeGoalCompletion".
             */
            if ( isset( $widget['query']['metrics'] )
                && is_string( $widget['query']['metrics'] )
                && strpos( $widget['query']['metrics'], '@' ) === 0 ) {

                $source = substr( $widget['query']['metrics'], 1 );

                if ( ! in_array( $source, self::KNOWN_METRIC_SOURCES, true ) ) {

                    return sprintf(
                        'widget %s: "%s" is not a metric source this report can resolve; it has %s',
                        $i, $source,
                        implode( ', ', array_map(
                            static function ( $n ) { return '@' . $n; },
                            self::KNOWN_METRIC_SOURCES ) ) );
                }
            }

            /*
             * There is ONE way to constrain a widget, and it adds.
             *
             * `query` is merged over the report-wide defaults with a union, so
             * a `constraints` key written inside it would win outright and
             * silently drop the report's -- widening a detail report from one
             * host to every host, which reads as a data bug. Two spellings
             * with opposite meanings and no error between them is worse than
             * either, so the overriding one is refused.
             *
             * If a widget ever genuinely needs to escape the report's
             * constraint, that wants to be a key that says so.
             */
            if ( isset( $widget['query']['constraints'] ) ) {

                return sprintf(
                    'widget %s puts "constraints" inside "query", which would replace the '
                    . 'report\'s rather than add to it; declare them on the widget instead',
                    $i );
            }

            /*
             * A widget may narrow the rows further than the report does.
             * `traffic` is why: its three metric boxes each measure a
             * different medium, so what they ask for genuinely differs from
             * one another and from the report's trend.
             */
            $error = self::constraintsError(
                isset( $widget['constraints'] ) ? $widget['constraints'] : null,
                (array) ( $definition['params'] ?? array() ),
                sprintf( 'widget %s: ', $i ) );

            if ( $error !== '' ) {

                return $error;
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

                /*
                 * Parameters to carry to the target report.
                 *
                 * `document` links to domstreams and dom-clicks, and both are
                 * ABOUT a page -- so a link that carried only a reportId would
                 * land on a report constrained on a parameter it was not given,
                 * which is now refused outright. A plain reportId is still the
                 * common case and stays optional.
                 *
                 * A flat map of name => value, values interpolated from the
                 * report's own declared parameters. Never nested and never
                 * anything but a scalar: this is a URL query string, and the
                 * one thing a definition must not be able to do is carry
                 * structure a template will later evaluate.
                 */
                if ( isset( $link['params'] ) ) {

                    if ( ! is_array( $link['params'] ) ) {

                        return sprintf(
                            'report link %s: "params" must map a parameter name to a value', $j );
                    }

                    foreach ( $link['params'] as $name => $value ) {

                        if ( ! is_string( $name ) || $name === '' ) {

                            return sprintf( 'report link %s: a param name must be a string', $j );
                        }

                        if ( is_array( $value ) || is_object( $value ) ) {

                            return sprintf(
                                'report link %s: param "%s" must be a scalar', $j, $name );
                        }
                    }
                }
            }

            if ( empty( $widget['links'] ) ) {

                return 'a report-links widget with no links renders an empty list';
            }
        }

        /*
         * A deprecation notice says a report is still here but no longer
         * filling. Generic on purpose: any report can carry one, and the
         * renderer neither knows nor cares why.
         */
        if ( isset( $definition['deprecated'] ) ) {

            if ( ! is_array( $definition['deprecated'] ) ) {

                return '"deprecated" must be an object with a "message"';
            }

            if ( empty( $definition['deprecated']['message'] ) ) {

                return 'a deprecation notice needs a "message" saying what changed';
            }
        }

        /*
         * Which metric sets this report offers, and in what order.
         *
         * Absent means the site's own -- Site Usage, e-commerce when the site
         * setting is on, and one per active goal group. That is what every
         * definition does today and stays the default, so this key only ever
         * narrows or replaces.
         *
         * Two shapes, deliberately not mixable:
         *
         *   [ "site_usage", "ecommerce" ]        pick from the site's, in this order
         *   { "roi": { "label": .., "metrics": .. } }   declare this report's own
         *
         * A list is names, never declarations -- the same rule as
         * excludeColumns and formatters. Naming a set the site does not have
         * (e-commerce where it is switched off) is not an error: it is absent
         * for that site, which is the point of the setting.
         */
        if ( isset( $definition['metricSets'] ) ) {

            $sets = $definition['metricSets'];

            if ( ! is_array( $sets ) || ! $sets ) {

                return '"metricSets" must be a non-empty list of set names, or an object '
                     . 'declaring sets; omit it entirely to use the site\'s';
            }

            $named    = array_keys( $sets ) === range( 0, count( $sets ) - 1 );
            $declared = ! $named;

            if ( $named ) {

                foreach ( $sets as $name ) {

                    if ( ! is_string( $name ) || $name === '' ) {

                        return '"metricSets" as a list names the site\'s sets; '
                             . 'a set is declared by writing it as an object instead';
                    }
                }
            }

            if ( $declared ) {

                foreach ( $sets as $key => $set ) {

                    if ( ! is_array( $set ) ) {

                        return sprintf( 'metric set "%s" must be an object with a '
                                      . '"label" and "metrics"', $key );
                    }

                    if ( empty( $set['label'] ) || empty( $set['metrics'] ) ) {

                        return sprintf( 'metric set "%s" needs a "label" and "metrics"; '
                                      . 'a set with neither renders as an empty tab', $key );
                    }
                }
            }

            /*
             * Declaring `metrics` suppresses sets altogether -- that is the
             * switch the widget reads. Saying both means one of them does
             * nothing, and which one is not guessable from the file.
             */
            if ( isset( $definition['metrics'] ) ) {

                return '"metrics" and "metricSets" cannot both be declared: naming metrics '
                     . 'renders one grid of them and suppresses sets entirely';
            }
        }

        /*
         * An unconstrained detail report is the failure worth refusing: it
         * renders every row rather than the one asked for, which reads as a
         * data bug and not as a broken definition.
         */
        return self::constraintsError(
            isset( $definition['settings']['constraints'] ) ? $definition['settings']['constraints'] : null,
            (array) ( $definition['params'] ?? array() ),
            '' );
    }

    /**
     * Check one set of constraint parts, wherever it was authored.
     *
     * Shared by the report-wide constraint and a widget's own, so the two
     * cannot drift into accepting different things -- a widget constraint
     * reading an undeclared parameter fails as loudly as a report's.
     *
     * @param mixed $constraints the declared value; a string is used as-is
     * @param array $declared the report's declared params
     * @param string $where a prefix naming the widget, empty for the report
     * @return string the first problem found, or '' if there is none
     */
    private static function constraintsError( $constraints, array $declared, $where ) {

        if ( ! is_array( $constraints ) ) {

            return '';
        }

        foreach ( $constraints as $i => $part ) {

            if ( ! is_array( $part ) || empty( $part['dimension'] ) ) {

                return sprintf( '%sconstraint %s needs a "dimension"', $where, $i );
            }

            if ( ! array_key_exists( 'fromParam', $part ) && ! array_key_exists( 'value', $part ) ) {

                return sprintf( '%sconstraint on "%s" needs either a "value" or a "fromParam"',
                    $where, $part['dimension'] );
            }

            if ( array_key_exists( 'fromParam', $part )
                 && ! array_key_exists( $part['fromParam'], $declared ) ) {

                // Otherwise the constraint silently becomes `dimension==`,
                // matching nothing, with no clue as to why.
                return sprintf( '%sconstraint on "%s" reads the undeclared parameter "%s"',
                    $where, $part['dimension'], $part['fromParam'] );
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
         * Neither view is named by the definition.
         *
         * ReportController::pre() already sets the outer view to base.report,
         * and four converted reports used to restate it -- kept during the
         * conversion so a definition could produce a byte-identical result to
         * the controller it replaced. That is done, and restating a default is
         * just a way for the two to disagree later.
         */
        $this->setSubview( self::SUBVIEW );

        /*
         * Metric sets, if this report chooses its own.
         *
         * ReportController::pre() has already put the site's sets in place, so
         * a definition that says nothing keeps them -- which is every
         * definition converted so far, campaigns included.
         */
        if ( isset( $d['metricSets'] ) ) {

            $sets = self::resolveMetricSets(
                self::interpolateDeep( $d['metricSets'], $values ),
                (array) $this->get( 'metricSets' ) );

            $this->set( 'metricSets', $sets );

            $tabs = \OWA\Core\MetricSets::toLegacyTabs( $sets );

            $this->set( 'tabs', $tabs );
            $this->set( 'tabs_json', json_encode( $tabs ) );
        }

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

        /*
         * Resolved before the settings loop below rather than inside it,
         * because a widget's own constraint is ADDED to this one and so has to
         * exist before the widgets are.
         */
        $constraints = isset( $d['settings']['constraints'] ) ? $d['settings']['constraints'] : '';

        $constraints = is_array( $constraints )
            ? self::buildConstraints( $constraints, $values )
            : self::interpolate( (string) $constraints, $values );

        if ( isset( $d['deprecated'] ) ) {

            $this->set( 'deprecated', self::interpolateDeep( $d['deprecated'], $values ) );
        }

        if ( isset( $d['widgets'] ) ) {

            $this->set( 'widgets', self::resolveMetricSources( self::interpolateDeep(
                self::resolveWidgetConstraints( (array) $d['widgets'], $constraints, $values ),
                $values ), $this->getParam( 'siteId' ) ) );
        }

        $this->setTitle(
            self::interpolate( $d['title'], $values ),
            self::interpolate( isset( $d['titleSuffix'] ) ? $d['titleSuffix'] : '', $values ) );

        foreach ( (array) ( isset( $d['settings'] ) ? $d['settings'] : array() ) as $key => $value ) {

            if ( $key === 'constraints' ) {

                // Already resolved above, where the widgets could be given it.
                $value = $constraints;

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
     * The parameters this report's CONSTRAINTS read.
     *
     * Enumerating a constraint is the definition saying what the report is
     * about, so the value behind it is required: `document` constrained on
     * pagePath is not a page report without a page.
     *
     * It used to fail silently either way. buildConstraints() emitted
     * `pagePath==` for an absent value -- a constraint no fact row carries, so
     * the widget returned nothing and said nothing about why. Dropping the
     * empty clause instead is worse: the query then has no constraint at all
     * and the report renders SITE-WIDE data under a "Page Detail" heading,
     * which reads as real.
     *
     * So the caller checks this before rendering and refuses. Only constraint
     * parameters are collected -- one read solely by a title is cosmetic, and
     * a missing one should not take the report down.
     *
     * @param array $definition
     * @return array<int,string> declared parameter names, in definition order
     */
    public static function constraintParams( array $definition ) {

        $names = array();

        $collect = static function ( $constraints ) use ( &$names ) {

            if ( ! is_array( $constraints ) ) {

                // A plain string constrains on nothing that varies.
                return;
            }

            foreach ( $constraints as $part ) {

                $part = (array) $part;

                if ( isset( $part['fromParam'] ) && $part['fromParam'] !== '' ) {

                    $names[] = (string) $part['fromParam'];
                }
            }
        };

        $collect( $definition['settings']['constraints'] ?? null );

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

            $collect( ( (array) $widget )['constraints'] ?? null );
        }

        // Declared params only: a constraint naming something the definition
        // never declared is an authoring error, and getDefinitionError is
        // where that belongs -- not a 400 blamed on the request.
        $declared = array_keys( (array) ( $definition['params'] ?? array() ) );

        return array_values( array_unique( array_intersect( $names, $declared ) ) );
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
     * Fold each widget's own constraint into its query.
     *
     * ADDED to the report's rather than replacing it: a widget that narrows to
     * one medium still wants every other row the report was already limited
     * to, and a widget author should not have to restate the report's
     * constraint to add one of their own.
     *
     * Joining here rather than in the template keeps the two kinds of value
     * encoded in one place -- see buildConstraints() -- and means the template
     * receives a widget whose query is already complete.
     *
     * @param array $widgets
     * @param string $reportConstraints the already-built report-wide string
     * @param array $values
     * @return array the widgets, with `constraints` folded into `query`
     */
    /**
     * The sets this report offers, from what it declared and what the site has.
     *
     * A list NAMES the site's sets and keeps the order it was written in, so a
     * report can lead with the one that matters to it. A name the site does not
     * have is skipped rather than refused -- e-commerce is absent wherever the
     * setting is off, and that is the setting working.
     *
     * An object DECLARES this report's own, used as written.
     *
     * @param array $declared the definition's `metricSets`, interpolated
     * @param array $site     what ReportController::pre() already resolved
     * @return array<string, array>
     */
    private static function resolveMetricSets( array $declared, array $site ) {

        $named = array_keys( $declared ) === range( 0, count( $declared ) - 1 );

        if ( ! $named ) {

            /*
             * `chartMetric` stays optional and is NOT defaulted. An absent one
             * already means "draw no chart" -- report_widgets.php guards on
             * `!== ''` before issuing makeAreaChart, the same way `traffic`
             * suppresses its metric boxes. Filling one in would hand a chart to
             * a set that asked for none.
             *
             * The warning this looked like it should fix was in the renderer
             * assuming the key, and is fixed there.
             */
            return $declared;
        }

        $out = array();

        foreach ( $declared as $name ) {

            if ( isset( $site[ $name ] ) ) {

                $out[ $name ] = $site[ $name ];
            }
        }

        return $out;
    }

    /**
     * Replace a widget's named metric list with the site's actual metrics.
     *
     * A widget whose list resolves to nothing is DROPPED rather than rendered
     * empty: asking for no metrics returns no columns, and a headed panel with
     * no boxes in it reads as a broken report rather than as a site that has
     * not configured any goals. The controller this replaced made the same
     * choice with `if ($view->goal_metrics)`.
     *
     * @param array<int,array> $widgets
     * @param string $siteId
     * @return array<int,array> re-indexed, since a dropped widget leaves a hole
     */
    private static function resolveMetricSources( array $widgets, $siteId ) {

        $out = array();

        foreach ( $widgets as $widget ) {

            $declared = isset( $widget['query']['metrics'] ) ? $widget['query']['metrics'] : null;

            if ( ! is_string( $declared ) || strpos( $declared, '@' ) !== 0 ) {

                $out[] = $widget;
                continue;
            }

            // Dispatch on the NAME, not on "it must be the only one" -- that
            // holds today and stops holding the moment a second source exists,
            // silently resolving the wrong list.
            switch ( substr( $declared, 1 ) ) {

                case 'activeGoalCompletions':
                    $metrics = \OWA\Core\MetricSets::activeGoalCompletions( $siteId );
                    break;

                default:
                    // getDefinitionError() rejects an unknown source before a
                    // report can be loaded, so reaching this means the
                    // whitelist grew without a case being added for it.
                    $metrics = '';
                    break;
            }

            if ( $metrics === '' ) {

                continue;
            }

            $widget['query']['metrics'] = $metrics;

            $out[] = $widget;
        }

        return $out;
    }

    private static function resolveWidgetConstraints( array $widgets, $reportConstraints, array $values ) {

        $out = array();

        foreach ( $widgets as $widget ) {

            $widget = (array) $widget;

            if ( isset( $widget['constraints'] ) ) {

                $own = is_array( $widget['constraints'] )
                    ? self::buildConstraints( $widget['constraints'], $values )
                    : self::interpolate( (string) $widget['constraints'], $values );

                $query = (array) ( isset( $widget['query'] ) ? $widget['query'] : array() );

                /*
                 * Empty parts are dropped rather than joined, so a report with
                 * no constraint of its own does not hand the widget a string
                 * with a leading comma -- an empty first clause, which is what
                 * the template this replaces emitted for one of these three.
                 */
                $query['constraints'] = implode( ',',
                    array_filter( array( $reportConstraints, $own ), 'strlen' ) );

                $widget['query'] = $query;

                unset( $widget['constraints'] );
            }

            $out[] = $widget;
        }

        return $out;
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
