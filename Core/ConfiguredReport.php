<?php
namespace OWA\Core;

/**
 * The controller every configured report is rendered by.
 *
 * A configured report names a subview, a title, and a bag of values for that
 * subview to read -- so there is one controller here and the differences live in
 * JSON.
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
    const KNOWN_KEYS = [ 'title', 'description', 'titleSuffix', 'params', 'metrics', 'widgets', 'settings', 'deprecated', 'metricSets', 'capability' ];

    /**
     * Top-level keys with a type, checked in this order: key => [ type test,
     * the error when a value set fails it ]. `description` is one sentence
     * saying what the report shows; the wiki's report list reads it.
     */
    const TYPED_KEYS = [
        'description' => [ 'is_string', '"description" must be a string' ],
        'settings'    => [ 'is_array', '"settings" must be an object' ],
        'params'      => [ 'is_array', '"params" must be an object of parameter name => options' ],
        'capability'  => [ 'is_string', '"capability" must be a capability name' ],
    ];

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
    const KNOWN_FORMATTERS = [ 'attributionList' ];

    /**
     * The widget types report_widgets.php draws. An unknown type draws an
     * empty frame, so it is refused. CustomReports::WIDGET_TYPES is the
     * subset the builder offers.
     */
    const WIDGET_TYPES = [ 'trend', 'trend-card', 'metric-boxes', 'pie', 'report-links', 'heatmap-link', 'grid', 'grid-card' ];

    /**
     * The renderer every configured report uses.
     *
     * Fixed here rather than named by each definition: a report definition is meant
     * to become something a user can author, and a definition that names a
     * renderer could point at any view in the tree. Widgets own their own
     * rendering.
     */
    const SUBVIEW = 'base.reportWidgets';

    /** Draws cube data, so it is not drawn for a Property with no cube yet. */
    protected $reads_reporting_data = true;

    /** @var array<string, mixed> the decoded definition */
    private $definition = [];

    /**
     * The definition's `capability`, when it names one, replaces view_reports
     * as the capability a reader needs. A module's report may declare its own.
     *
     * @param array<string, mixed> $definition a decoded report definition
     * @return void
     */
    public function setDefinition( array $definition ) {

        $this->definition = $definition;

        if ( isset( $definition['capability'] ) && is_string( $definition['capability'] )
             && $definition['capability'] !== '' ) {

            $this->setRequiredCapability( $definition['capability'] );
        }
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

        foreach ( [ 'title' ] as $required ) {

            if ( ! isset( $definition[ $required ] ) || $definition[ $required ] === '' ) {

                return sprintf( 'a report definition needs a "%s"', $required );
            }
        }

        $unknown = array_diff( array_keys( $definition ), self::KNOWN_KEYS );

        if ( $unknown ) {

            return sprintf( 'unknown key%s %s; a report definition may use %s', \OWA\Core\Lib::plural( count( $unknown ), '', 's' ),
                implode( ', ', $unknown ), implode( ', ', self::KNOWN_KEYS ) );
        }

        foreach ( self::TYPED_KEYS as $key => [ $isType, $message ] ) {

            if ( isset( $definition[ $key ] ) && ! $isType( $definition[ $key ] ) ) {

                return $message;
            }
        }

        foreach ( (array) ( $definition['widgets'] ?? [] ) as $i => $widget ) {

            if ( ! is_array( $widget ) || empty( $widget['type'] ) ) {

                return sprintf( 'widget %s needs a "type"', $i );
            }

            if ( ! in_array( $widget['type'], self::WIDGET_TYPES, true ) ) {

                return sprintf( 'widget %s: "%s" is not a widget type; the types are %s', $i,
                    is_string( $widget['type'] ) ? $widget['type'] : gettype( $widget['type'] ), implode( ', ', self::WIDGET_TYPES ) );
            }

            if ( isset( $widget['more'] ) && empty( $widget['more']['reportId'] ) ) {

                return sprintf( 'widget %s has a "more" link with no reportId', $i );
            }

            /*
             * A list of column names, never a fragment of JavaScript, so the format
             * refuses a string outright.
             */
            // Names, never targets: see ROW_ACTIONS.
            if ( isset( $widget['rowActions'] ) && ( ! is_array( $widget['rowActions'] )
                     || array_filter( $widget['rowActions'], function ( $n ) {
                            return ! is_string( $n ) || ! preg_match( '/^[a-z][a-zA-Z]*$/', $n ); } ) ) ) {
                return sprintf( 'widget %s: "rowActions" must be a list of action names', $i );
            }
            // `compare: false` keeps the report's comparison out of the widget's query.
            if ( isset( $widget['compare'] ) && $widget['compare'] !== false ) {

                return sprintf( 'widget %s: "compare" can only be false', $i );
            }

            if ( isset( $widget['excludeColumns'] ) && ! is_array( $widget['excludeColumns'] ) ) {

                return sprintf( 'widget %s: "excludeColumns" must be a list of column names', $i );
            }

            /*
             * `formatters` maps a column to the NAME of a formatter the widget
             * implements, never to code.
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
                (array) ( $definition['params'] ?? [] ),
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
            foreach ( (array) ( $widget['links'] ?? [] ) as $j => $link ) {

                foreach ( [ 'reportId', 'label' ] as $required ) {

                    if ( empty( $link[ $required ] ) ) {

                        return sprintf( 'report link %s needs a "%s"', $j, $required );
                    }
                }

                /*
                 * Parameters to carry to the target report.
                 *
                 * `document` links to dom-clicks, which is
                 * ABOUT a page -- so a link that carried only a reportId would
                 * land on a report constrained on a parameter it was not given,
                 * which is refused. A plain reportId is the common case.
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
         * Absent means the site's own -- Site Usage, and e-commerce when the site
         * setting is on -- so this key only ever narrows or replaces.
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
            (array) ( $definition['params'] ?? [] ),
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
     * @param array<mixed> $declared the report's declared params
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

            if ( isset( self::EMPTY_TESTS[ $part['operator'] ?? '' ] ) ) {

                if ( array_key_exists( 'fromParam', $part ) || array_key_exists( 'value', $part ) ) {

                    return sprintf( '%sconstraint on "%s" is "%s", which takes no value',
                        $where, $part['dimension'], $part['operator'] );
                }

                continue;
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
     * A trend's query for the period being reported on.
     *
     * When the chart's axis is one day, a trend by `date` is a single point,
     * so it is charted by hour: `date` becomes `dateHour` as the x dimension
     * and in a sort that names it. A breakdown after the x dimension, and a
     * trend over anything but `date`, are left as they are.
     *
     * The AXIS, not the dates queried: on a Sunday "this week" is one day,
     * but its axis is the whole week (TimePeriod::getAxisEndDate()), and by
     * hour that put today's hours in the first 24 of 168.
     *
     * @param  array<string, mixed> $query  a trend widget's query
     * @param  object|null $period the report's TimePeriod
     * @return array<string, mixed>
     */
    public static function trendQuery( array $query, $period ) {

        if ( ! $period ) {

            return $query;
        }

        $start = $period->getStartDate()->getYyyymmdd();

        if ( $start !== $period->getEndDate()->getYyyymmdd() || $start !== $period->getAxisEndDate() ) {

            return $query;
        }

        $dims = array_map( 'trim', explode( ',', (string) ( $query['dimensions'] ?? '' ) ) );

        if ( $dims[0] !== 'date' ) {

            return $query;
        }

        $dims[0] = 'dateHour';

        $query['dimensions'] = implode( ',', $dims );

        if ( isset( $query['sort'] ) && preg_match( '/^date(-?)$/', trim( (string) $query['sort'] ), $m ) ) {

            $query['sort'] = 'dateHour' . $m[1];
        }

        return $query;
    }

    /**
     * What a grid row can lead to, by name: a definition lists the names
     * ("rowActions": ["heatmap", "clicks"]) and never a target, so it cannot
     * point a row anywhere the code does not.
     *
     * Each action names where it goes -- a report, or the overlay launcher --
     * and which of the row's dimensions fill its parameters. `first` is a
     * dimension the grid must be grouped by FIRST for the action to apply:
     * a heatmap is of one page, and a row of pagePathPlusQuery is one page;
     * a row of pagePath has pathHeatmap, which draws every query string's
     * clicks on the path with none. Modules add theirs through the report_row_actions filter.
     * `description` says what the action opens, for the wiki. A `label` is
     * shown translated (OWA.translate()), catalogued from here
     * (Strings::LABEL_TABLES).
     */
    const ROW_ACTIONS = [
        'heatmap' => [
            'label'     => 'Heatmap',
            'description' => 'The page itself, in a new window, with its clicks drawn over it.',
            'action'    => 'base.overlayLauncher',
            'params'    => [ 'pagePathPlusQuery' => 'pagePathPlusQuery' ],
            'first'     => 'pagePathPlusQuery',
            'newWindow' => true,
        ],
        'pathHeatmap' => [
            'label'     => 'Heatmap',
            'description' => 'The page at the path, in a new window, with the clicks of every query string of it drawn over it.',
            'action'    => 'base.overlayLauncher',
            'params'    => [ 'pagePath' => 'pagePath' ],
            'first'     => 'pagePath',
            'newWindow' => true,
        ],
        'clicks' => [
            'label'    => 'Element clicks',
            'description' => "Dom Clicks for the page's path: clicks by element id and tag.",
            'reportId' => 'dom-clicks',
            'params'   => [ 'pagePath' => 'pagePath' ],
        ],
        'paths' => [
            'label'    => 'Previous and next pages',
            'description' => "Page Detail for the page's path: its page views and sessions, and the pages viewed before and after it.",
            'reportId' => 'document',
            'params'   => [ 'pagePath' => 'pagePath' ],
        ],
    ];

    /**
     * The row actions a report offers, through the report_row_actions filter.
     *
     * @return array<array<string, mixed>> name => action
     */
    public static function rowActions() {

        return array_filter( (array) \OWA\Core\CoreAPI::filter( 'report_row_actions', self::ROW_ACTIONS ),
            'is_array' );
    }

    /**
     * A widget's rowActions names as the actions they name. A name nothing
     * registers -- a module's, with the module off -- is left out.
     *
     * @param  array<string, mixed> $widget
     * @return list<array<string, mixed>> of action + 'id'
     */
    public static function resolveRowActions( array $widget ) {

        $known = self::rowActions();
        $out   = [];

        foreach ( (array) ( $widget['rowActions'] ?? [] ) as $name ) {

            if ( is_string( $name ) && isset( $known[ $name ] ) ) {

                $out[] = [ 'id' => $name ] + $known[ $name ];
            }
        }

        return $out;
    }

    /**
     * Each report-links widget's links, through the report_links filter.
     *
     * The filter gets the links, the report id and the widget id, so a module
     * can add a link to a report it does not own -- a page's recordings on
     * Page Detail, say -- or remove one. Before interpolation, so
     * an added link may use the report's placeholders ({pagePath}). A widget
     * the filter leaves empty is dropped rather than drawn as an empty list.
     *
     * @param  array<mixed> $widgets
     * @param  string $reportId
     * @return list<mixed>
     */
    public static function filterReportLinks( array $widgets, $reportId ) {

        $out = [];

        foreach ( $widgets as $widget ) {

            if ( is_array( $widget ) && ( $widget['type'] ?? '' ) === 'report-links' ) {

                $widget['links'] = array_values( array_filter(
                    (array) \OWA\Core\CoreAPI::filter( 'report_links',
                        (array) ( $widget['links'] ?? [] ),
                        (string) $reportId, (string) ( $widget['id'] ?? '' ) ),
                    'is_array' ) );

                if ( ! $widget['links'] ) {

                    continue;
                }
            }

            $out[] = $widget;
        }

        return $out;
    }

    /**
     * Declare the subview, then the title, then the settings, so a setting named
     * "title" loses to the title.
     *
     * @return void
     */
    public function action() {

        $d = self::translated( $this->definition );

        $values = $this->resolveParams();

        /*
         * Neither view is named by the definition: ReportController::pre() sets the
         * outer view to base.report, and restating a default is a way for the two to
         * disagree.
         */
        $this->setSubview( self::SUBVIEW, [
            'template' => 'report_widgets.php',
            /*
             * Passed through, not interpreted: the template knows what a trend
             * and a grid are. The constraint and the metrics are report-wide,
             * so every widget looks at the same rows and measures them the
             * same way; metricSets are the sets the widgets are drawn once
             * each for (Core\MetricSets). custom_report_id is set only for a
             * user-authored report, deprecated on almost none.
             */
            'vars'     => [ 'widgets', 'deprecated', 'constraints', 'metrics', 'metricSets', 'custom_report_id' ],
        ] );

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
        }

        /*
         * Widgets are a setting as far as the view is concerned -- the subview
         * reads them like anything else. They are a TOP-LEVEL key in the
         * definition rather than one more entry in `settings` because they are
         * the report's structure, not one of its knobs, and because
         * getDefinitionError() has to be able to check them.
         */
        /*
         * Report-wide, like the constraint: every widget in a report asks for the
         * same metrics, and holding it once lets a metric set replace it in ONE
         * place rather than rewriting every widget's query.
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

            $widgets = self::filterReportLinks(
                (array) $d['widgets'], (string) $this->getParam( 'reportId' ) );

            $this->set( 'widgets', array_values( self::interpolateDeep(
                self::resolveWidgetConstraints( $widgets, $constraints, $values ),
                $values ) ) );
        }

        $this->setTitle(
            self::interpolate( $d['title'], $values ),
            self::interpolate( isset( $d['titleSuffix'] ) ? $d['titleSuffix'] : '', $values ) );

        foreach ( (array) ( isset( $d['settings'] ) ? $d['settings'] : [] ) as $key => $value ) {

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
     * Without it an absent value fails silently either way: `pagePath==` is a
     * constraint no fact row carries, so the widget returns nothing; dropping
     * the empty clause renders SITE-WIDE data under a "Page Detail" heading,
     * which reads as real. So the caller checks this before rendering and
     * refuses. Only constraint
     * parameters are collected -- one read solely by a title is cosmetic, and
     * a missing one should not take the report down.
     *
     * @param array<string, mixed> $definition
     * @return array<int,string> declared parameter names, in definition order
     */
    public static function constraintParams( array $definition ) {

        $names = [];

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

        foreach ( (array) ( $definition['widgets'] ?? [] ) as $widget ) {

            $collect( ( (array) $widget )['constraints'] ?? null );
        }

        // Declared params only: a constraint naming something the definition
        // never declared is an authoring error, and getDefinitionError is
        // where that belongs -- not a 400 blamed on the request.
        $declared = array_keys( (array) ( $definition['params'] ?? [] ) );

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

        $values = [];

        foreach ( (array) ( isset( $this->definition['params'] ) ? $this->definition['params'] : [] ) as $name => $spec ) {

            // A definition decoded with json_decode($s, true) hands this an
            // array, which is how the dispatcher reads one. Cast anyway: decoded
            // the other way an empty {} is a stdClass, and indexing that is a
            // fatal rather than a missing option.
            $spec  = (array) $spec;
            $value = (string) $this->getParam( $name );

            /*
             * For a dimension stored lowercased: a parameter that arrives as
             * "Google" would otherwise match no row stored as "google". A
             * definition asks for it with `lowercase`; no shipped report does.
             */
            if ( ! empty( $spec['lowercase'] ) ) {

                $value = strtolower( $value );
            }

            $values[ $name ] = $value;
        }

        return $values;
    }

    /**
     * A definition with what a reader sees in the locale in use: the
     * Strings::REPORT_KEYS of the report and Strings::REPORT_WIDGET_KEYS of
     * each widget. Translated before {name} is replaced, so a translation keeps
     * the placeholder.
     *
     * @param  array<string, mixed> $definition a decoded report definition
     * @return array<string, mixed>
     */
    public static function translated( array $definition ) {

        foreach ( \OWA\Core\Strings::REPORT_KEYS as $key ) {

            if ( isset( $definition[ $key ] ) && is_string( $definition[ $key ] ) && $definition[ $key ] !== '' ) {

                $definition[ $key ] = \OWA\Core\Strings::translate( $definition[ $key ] );
            }
        }

        if ( isset( $definition['widgets'] ) && is_array( $definition['widgets'] ) ) {

            foreach ( $definition['widgets'] as $i => $widget ) {

                foreach ( \OWA\Core\Strings::REPORT_WIDGET_KEYS as $key ) {

                    if ( is_array( $widget ) && isset( $widget[ $key ] ) && is_string( $widget[ $key ] ) && $widget[ $key ] !== '' ) {

                        $definition['widgets'][ $i ][ $key ] = \OWA\Core\Strings::translate( $widget[ $key ] );
                    }
                }
            }
        }

        return $definition;
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
     * @param array<string, string> $values
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
     * @param array<string, string> $values
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
     * The sets this report offers, from what it declared and what the site has.
     *
     * A list NAMES the site's sets and keeps the order it was written in, so a
     * report can lead with the one that matters to it. A name the site does not
     * have is skipped rather than refused -- e-commerce is absent wherever the
     * setting is off, and that is the setting working.
     *
     * An object DECLARES this report's own, used as written.
     *
     * @param array<mixed> $declared the definition's `metricSets`, interpolated
     * @param array<mixed> $site     what ReportController::pre() already resolved
     * @return array<mixed>
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
             */
            return $declared;
        }

        $out = [];

        foreach ( $declared as $name ) {

            if ( isset( $site[ $name ] ) ) {

                $out[ $name ] = $site[ $name ];
            }
        }

        return $out;
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
     * @param  array<mixed>          $widgets
     * @param  string                $reportConstraints the report's, interpolated
     * @param  array<string, string> $values            parameter name => value
     * @return list<array<mixed>>
     */
    private static function resolveWidgetConstraints( array $widgets, $reportConstraints, array $values ) {

        $out = [];

        foreach ( $widgets as $widget ) {

            $widget = (array) $widget;

            if ( isset( $widget['constraints'] ) ) {

                $own = is_array( $widget['constraints'] )
                    ? self::buildConstraints( $widget['constraints'], $values )
                    : self::interpolate( (string) $widget['constraints'], $values );

                $query = (array) ( isset( $widget['query'] ) ? $widget['query'] : [] );

                /*
                 * Empty parts are dropped rather than joined, so a report with
                 * no constraint of its own does not hand the widget a string
                 * with a leading comma -- an empty first clause.
                 */
                $query['constraints'] = implode( ',',
                    array_filter( [ $reportConstraints, $own ], static fn ( $part ) => (string) $part !== '' ) );

                $widget['query'] = $query;

                // Kept apart so an empty widget does not blame the reader's
                // filters for its own (OWA.dataGrid.emptyText).
                $widget['ownConstraints'] = $own;

                unset( $widget['constraints'] );
            }

            $out[] = $widget;
        }

        return $out;
    }

    /**
     * The unary operators a definition's array form accepts, and the string
     * form each builds. ResultSetManager::emptyTestFor() reads that form back
     * as the empty test; "(not set)" is the label for NULL and '', never a
     * stored value.
     */
    const EMPTY_TESTS = [
        'empty'    => '==(not set)',
        'notEmpty' => '!=(not set)',
    ];

    /**
     * Build a constraint string from its parts.
     *
     * Structured rather than a string with placeholders, because the two kinds
     * of value are encoded differently: a value taken from the request is
     * escaped as a constraint value (ResultSetManager::constraintValue()) and
     * urlencoded, a literal is not. Expressed as one template string, that
     * difference would have to be spelled with a filter -- and every author
     * would have to remember which side of the '==' they were on.
     *
     * `constraints` may still be a plain string, which is used as-is. Most
     * reports constrain on nothing that varies.
     *
     * @param array<array<string, mixed>> $parts
     * @param array<string, string> $values
     * @return string
     */
    private static function buildConstraints( array $parts, array $values ) {

        $out = [];

        foreach ( $parts as $part ) {

            $operator = isset( $part['operator'] ) ? $part['operator'] : '==';

            if ( isset( self::EMPTY_TESTS[ $operator ] ) ) {

                $out[] = $part['dimension'] . self::EMPTY_TESTS[ $operator ];
                continue;
            }

            if ( array_key_exists( 'fromParam', $part ) ) {

                $name  = $part['fromParam'];
                $value = urlencode( \OWA\Module\Base\Classes\ResultSetManager::constraintValue(
                    isset( $values[ $name ] ) ? $values[ $name ] : '' ) );

            } else {

                $value = isset( $part['value'] ) ? $part['value'] : '';
            }

            $out[] = $part['dimension'] . $operator . $value;
        }

        return implode( ',', $out );
    }
}
