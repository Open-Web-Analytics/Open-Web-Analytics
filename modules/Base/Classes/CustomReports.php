<?php
namespace OWA\Module\Base\Classes;


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
 * Custom reports: storing them, listing them, and deciding what may be stored.
 *
 * WHY THIS CAN ACCEPT USER INPUT AT ALL
 *
 * A custom report is a report DEFINITION -- the same JSON a shipped report in
 * modules/Base/reports/ holds -- and it renders through the same
 * Core\ConfiguredReport. The format was narrowed over the conversion so that it
 * could one day come from a user, and each narrowing is load-bearing here:
 *
 *   - The renderer is FIXED (ConfiguredReport::SUBVIEW), not named by the
 *     definition. A definition that named its own view could point at any view
 *     in the tree.
 *   - Formatters are selected by NAME from a known list, never carried as code.
 *   - excludeColumns is a list of names, not a fragment of script.
 *
 * None of those may be relaxed while definitions are user-authored.
 *
 * WHAT THIS ADDS ON TOP
 *
 * getDefinitionError() already refuses an unknown top-level key and a missing
 * title. That is necessary and not sufficient for user input, because it says
 * nothing about whether the NAMES inside resolve. So validate() below also
 * requires that:
 *
 *   - there are at most MAX_WIDGETS widgets;
 *   - every widget names one of the types a person can actually build;
 *   - every metric, dimension and sort name resolves through the registry.
 *
 * That last one is the REST-to-SQL invariant: a name that reaches the query
 * builder without resolving through the registry is a name the reader chose
 * appearing in SQL. Refusing at SAVE time is the belt to the query layer's
 * braces -- ResultSetManager refuses an unknown constraint at query time too --
 * and it means the author is told at the moment they can still fix it, rather
 * than being handed a report that is broken every time it is opened.
 *
 * @since owa 1.8.0
 */
class CustomReports {

    /**
     * How many widgets one custom report may carry.
     *
     * A limit rather than an opinion about layout: every widget is at least one
     * query, so a report is a multiplier on database work and this bounds it.
     */
    const MAX_WIDGETS = 10;

    /**
     * How many metrics one query may ask for.
     *
     * A limit on READABILITY as much as on cost. A metric-boxes widget draws a
     * box per metric and a trend draws a box per metric under its chart, so
     * beyond about four they stop fitting a row and the widget reads as a wall
     * of numbers. Every metric is also another aggregate in the query.
     */
    const MAX_METRICS = 4;

    /**
     * How many dimensions one query may group by.
     *
     * Same number, different reason. Each dimension is another GROUP BY column
     * and another join, so rows multiply with every one added -- and a grid
     * grouped six ways is a list of near-unique rows, which answers nothing a
     * reader came for.
     */
    const MAX_DIMENSIONS = 4;

    /**
     * The widget types the builder offers.
     *
     * A SUBSET of what the renderer can draw, and deliberately so. The others
     * exist to serve one shipped report each -- report-links needs a list of
     * report ids, heatmap-link needs a document, the badge widgets need a
     * particular dimension in a particular column -- so offering them would be
     * offering configurations that mostly cannot be made to work.
     *
     * Keys are what a definition says; values are what a person is shown.
     */
    const WIDGET_TYPES = array(
        'grid'          => 'Table',
        'grid-card'     => 'Table card',
        'pie'           => 'Pie chart',
        'trend'         => 'Trend chart',
        'trend-card'    => 'Trend card',
        'metric-boxes'  => 'Info boxes',
    );

    /**
     * One line about each type, shown where the type is chosen.
     *
     * The type is picked before anything else about a widget is, so this is
     * where an author decides what they are building -- and "Table" beside
     * "Table card" says nothing about which to reach for. Kept beside
     * WIDGET_TYPES rather than in the template so the two cannot drift.
     */
    const WIDGET_TYPE_HINTS = array(
        'grid'          => 'A full-width table. Several metrics and dimensions, and the reader can regroup and filter it.',
        'grid-card'     => 'A narrow table of one metric by one dimension. No controls; its rows can link to the report that details them.',
        'pie'           => 'Share of one metric across the values of one dimension.',
        'trend'         => 'One metric over time, as a chart. Always by date.',
        'trend-card'    => 'A half-width version: its totals sit above the chart, it cannot be broken out by a dimension, and it names its own metrics.',
        'metric-boxes'  => 'Totals for the period, one box per metric.',
    );

    /**
     * A picture of each type, for the screen where one is chosen.
     *
     * Font Awesome 5 names -- the icon set the reporting chrome already loads
     * (see Base\View\Report). A drawing of the thing says which is which
     * faster than either the name or the sentence does, which matters most on
     * the two that are nearly the same word: a full table and a card.
     */
    const WIDGET_TYPE_ICONS = array(
        'grid'          => 'fas fa-table',
        'grid-card'     => 'fas fa-list-alt',
        'pie'           => 'fas fa-chart-pie',
        'trend'         => 'fas fa-chart-area',
        'trend-card'    => 'fas fa-chart-line',
        'metric-boxes'  => 'fas fa-th-large',
    );

    /**
     * Types whose first dimension is not the author's to choose, and what it is.
     *
     * A trend is a metric OVER TIME -- that is what makes it a trend rather
     * than a chart of something else -- so it is always grouped by a date to
     * begin with. What a reader sees is day or month, chosen at the chart; the
     * definition stores the default.
     *
     * It may carry ONE more dimension, and that one IS the author's: its values
     * become the lines, so `visits` by `medium` is a line per medium over the
     * filled total. See TIME_DIMENSIONS for what may not be that second one.
     *
     * metric-boxes is deliberately NOT here: its boxes are totals, so it needs
     * no grouping, but a hand-written one that has some is not wrong and is not
     * refused.
     */
    const FIXED_DIMENSIONS = array(
        'trend'      => 'date',
        'trend-card' => 'date',
    );

    /**
     * The dimensions that measure TIME.
     *
     * A trend's x axis is one of these, so none of them can also be the thing
     * it is broken down by -- a trend of visits by month, over months, is not a
     * chart anybody meant to ask for.
     *
     * Named rather than derived from the registry's `time` family, because the
     * family is a grouping in the interface and this is a rule about what a
     * chart can plot. They are the same list today and may diverge.
     */
    const TIME_DIMENSIONS = array(
        'date', 'day', 'month', 'year', 'dayofweek', 'dayofyear', 'weekofyear',
    );

    /** How many dimensions a widget of this type may group by, in total. */
    const FIXED_DIMENSION_EXTRA = array(
        // One breakdown beyond the date: its values are the lines.
        'trend' => 1,

        /*
         * NONE. A trend card is a headline figure with the shape behind it, at
         * half a row -- there is no room for a legend of six lines and no grid
         * under it to say what they are. A card that could be broken out would
         * be a trend that had been made too small to read.
         */
        'trend-card' => 0,
    );

    /**
     * The two table widgets, and why there are two of them.
     *
     * A table and its controls are one decision, not two. A full-width `grid`
     * is an EXPLORER: it can carry several metrics and dimensions, and it draws
     * the bar that lets a reader regroup and filter it -- which needs room,
     * because every control adds a column. A `grid-card` is a READING: one
     * metric against one dimension, a quarter of the row wide, no controls.
     *
     * They were one type with a colspan, and that is what went wrong. An
     * explorer narrowed to a quarter of the page still drew its full bar, so
     * the controls overflowed the widget and it grew a horizontal scrollbar.
     * The size and the controls have to be decided together, so the type
     * decides both: a grid cannot be narrowed and a card cannot be widened.
     */
    const FULL_WIDTH_TYPES = array( 'grid' );

    /**
     * Widget types that take exactly one metric and exactly one dimension.
     *
     * EXACTLY, not at most, and the "at least" half is the part that matters.
     * A report METRIC SET is several metrics, and a widget that draws one of
     * them has no way to pick which -- a card ranks its rows by one metric, a
     * pie is a share of one metric. Left to inherit the set, a pie got an empty
     * chartMetric and drew nothing at all. So these have to say which one
     * themselves, and the report metric set is not an answer for them.
     *
     * The "at most" half is the same sentence read the other way: a second
     * metric on a pie is drawn by nothing, and a second dimension makes its
     * slices a pair of values.
     *
     * Every other type either draws all the metrics it is given (info boxes, a
     * trend's boxes) or ranks by whatever it has, so a set works for them.
     */
    const SINGLE_FIELD_TYPES = array( 'grid-card', 'pie' );

    /**
     * Widget types that draw exactly one metric AND may not inherit a set.
     *
     * A trend is deliberately NOT here, and the distinction is the point: its
     * CHART draws one metric, but the widget also draws a box per metric under
     * that chart, and those boxes are exactly what a report metric set is for.
     * So a trend carries the set and names which one of it to plot --
     * chartMetric -- while a card or a pie has nowhere to put a second metric
     * and no way to choose between them.
     */
    const SINGLE_METRIC_TYPES = array( 'grid-card', 'pie' );

    /**
     * Types that must name their own metrics, because a set is not an answer.
     *
     * A report METRIC SET is a property of the SITE -- site usage, e-commerce,
     * a group of goals -- and a widget that inherits one is saying "however
     * this report is being measured". That is right for a trend, whose boxes
     * are the set made visible.
     *
     * It is wrong for a trend CARD. A card is a chosen figure at half a row:
     * its author picked what it shows, and a set would silently replace that
     * with three to six metrics whose boxes do not fit the width the type
     * exists to be. So it declares, and a card with nothing declared is
     * refused rather than quietly filled in.
     *
     * The other two are here because they already say the same thing a
     * stronger way: a card and a pie draw EXACTLY one metric, which no set can
     * supply. Named together so the reason is in one place.
     */
    const OWN_METRIC_TYPES = array( 'grid-card', 'pie', 'trend-card' );

    /**
     * Types that draw one metric as a chart and must be told which.
     *
     * Read by the builder, so the form asks for a chart metric on exactly the
     * types that plot one. It was a list written into the builder's own
     * JavaScript, which is the copy that does not get updated when a type is
     * added -- and the symptom is a chart with no line rather than an error.
     */
    const CHART_TYPES = array( 'trend', 'trend-card', 'pie' );

    /**
     * The link template a widget's rows may carry, in full.
     *
     * A `link` becomes makeLink() output in the rendered page, so an author who
     * could write arbitrary parameters into it would be choosing URLs the
     * report shows. The builder only ever produces this shape -- another
     * report, addressed the way every report is addressed -- so this is what a
     * stored definition may say.
     */
    const LINK_ACTION = 'base.report';

    /**
     * What a "View Full Report" link says when nobody names it.
     *
     * The words the shipped summary widgets use, so a report built here reads
     * like the ones that came with the install.
     */
    const MORE_LABEL = 'View Full Report »';

    /** How many metrics a widget of this type may ask for. */
    public static function maxMetricsFor( $type ) {

        return in_array( (string) $type, self::SINGLE_METRIC_TYPES, true )
            ? 1
            : self::MAX_METRICS;
    }

    /**
     * How many dimensions of its OWN a widget of this type may choose.
     *
     * A fixed-dimension type does not choose its first one, so this answers
     * what it may add beyond it -- a trend chooses the one whose values become
     * its lines, and nothing else.
     */
    public static function maxDimensionsFor( $type ) {

        $type = (string) $type;

        if ( array_key_exists( $type, self::FIXED_DIMENSIONS ) ) {

            return self::FIXED_DIMENSION_EXTRA[ $type ] ?? 0;
        }

        return in_array( $type, self::SINGLE_FIELD_TYPES, true )
            ? 1
            : self::MAX_DIMENSIONS;
    }

    /** Roster ordering: most recently changed first. */
    const ROSTER_LIMIT = 500;

    /**
     * What the roster may be ordered by, and the column each one means.
     *
     * An ALLOWLIST, because the sort arrives from a URL and reaches an ORDER BY
     * -- which cannot be a bound parameter. Mapping a known key to a known
     * column is what keeps a reader's choice from being SQL, the same rule the
     * reporting stack applies to every dimension and metric name.
     */
    const ROSTER_SORTS = array(
        'name'    => 'name',
        'author'  => 'user_id',
        'updated' => 'last_updated_timestamp',
    );

    /** Ordered by when it changed, newest first: the useful default for a list. */
    const ROSTER_DEFAULT_SORT = 'updated';

    /**
     * Why this definition may not be stored, or '' if it may.
     *
     * Static, and returning a string rather than throwing, so the builder can
     * put the reason next to the field that caused it and a test can ask the
     * same question without a request.
     *
     * @param mixed $definition a decoded definition
     * @return string
     */
    public static function validate( $definition ) {

        // Everything the renderer already refuses: a missing title, an unknown
        // top-level key, a malformed widget list, an unknown formatter.
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( $definition );

        if ( $error !== '' ) {

            return $error;
        }

        $widgets = isset( $definition['widgets'] ) ? (array) $definition['widgets'] : array();

        if ( ! $widgets ) {

            return 'a report needs at least one widget';
        }

        if ( count( $widgets ) > self::MAX_WIDGETS ) {

            return sprintf( 'a report may have at most %d widgets; this one has %d',
                self::MAX_WIDGETS, count( $widgets ) );
        }

        foreach ( $widgets as $i => $widget ) {

            $where = sprintf( 'widget %d', $i + 1 );

            if ( ! is_array( $widget ) ) {

                return $where . ' is not an object';
            }

            $type = isset( $widget['type'] ) ? (string) $widget['type'] : '';

            if ( ! isset( self::WIDGET_TYPES[ $type ] ) ) {

                return sprintf( '%s names widget type "%s", which is not one that can be built. Choose one of: %s',
                    $where, $type, implode( ', ', array_keys( self::WIDGET_TYPES ) ) );
            }

            $error = self::validateQuery( $widget, $where );

            if ( $error !== '' ) {

                return $error;
            }
        }

        /*
         * The report-level metric set: the metrics the report offers as its own
         * tab group, independent of any one widget. Same registry check -- an
         * unresolvable name here takes down every widget rather than one.
         */
        $error = self::validateNames( $definition, 'metrics', 'metric', 'the report metric set' );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateFieldCount(
            isset( $definition['metrics'] ) ? $definition['metrics'] : '',
            self::MAX_METRICS, 'metrics', 'the report metric set',
            'the boxes stop fitting a row and the numbers stop being readable' );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateCombination(
            isset( $definition['metrics'] ) ? $definition['metrics'] : '',
            '', '', 'the report metric set' );

        if ( $error !== '' ) {

            return $error;
        }

        return '';
    }

    /**
     * The names inside one widget's query.
     *
     * @param array  $widget
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateQuery( array $widget, $where ) {

        $query = isset( $widget['query'] ) ? (array) $widget['query'] : array();

        $type = isset( $widget['type'] ) ? (string) $widget['type'] : '';

        foreach ( array(
            array( 'metrics',    'metric' ),
            array( 'dimensions', 'dimension' ),
        ) as $pair ) {

            list( $key, $kind ) = $pair;

            $error = self::validateNames( $query, $key, $kind, $where );

            if ( $error !== '' ) {

                return $error;
            }
        }

        /*
         * A type whose dimension is fixed has to be grouped by it. Checked
         * first, because every other message about dimensions would be
         * describing a choice this type does not have.
         */
        if ( array_key_exists( $type, self::FIXED_DIMENSIONS ) ) {

            $fixed  = self::FIXED_DIMENSIONS[ $type ];
            $listed = self::asNames( $query['dimensions'] ?? '' );
            $label  = self::WIDGET_TYPES[ $type ] ?? $type;

            /*
             * The FIRST one, because it is the x axis. Order is not
             * decoration here: the chart reads the first as what it plots
             * against and the second as what it breaks out into lines.
             */
            if ( ( $listed[0] ?? '' ) !== $fixed ) {

                return sprintf(
                    '%s is a %s, which is always over %s; this one starts with "%s".',
                    $where, $label, $fixed, $listed ? $listed[0] : 'nothing' );
            }

            $extra = array_slice( $listed, 1 );

            if ( count( $extra ) > ( self::FIXED_DIMENSION_EXTRA[ $type ] ?? 0 ) ) {

                return sprintf(
                    '%s is a %s: one metric over %s, optionally broken out by one '
                  . 'dimension. This one names %d beyond the %s.',
                    $where, $label, $fixed, count( $extra ), $fixed );
            }

            /*
             * ...and the breakdown cannot itself be time. A trend of visits by
             * month, over months, is not a chart anybody meant to ask for.
             */
            foreach ( $extra as $name ) {

                if ( in_array( $name, self::TIME_DIMENSIONS, true ) ) {

                    return sprintf(
                        '%s is broken out by "%s", which measures time -- and time is '
                      . 'already the axis it is drawn against. Break it out by '
                      . 'something else, or by nothing.',
                        $where, $name );
                }
            }
        }

        /*
         * A single-field type is checked for EXACTLY one of each before the
         * ordinary caps, so a card with none reports the thing that is actually
         * wrong. The cap message ("asks for 2; 1 is the most") would be right
         * about too many and silent about too few.
         */
        if ( in_array( $type, self::SINGLE_METRIC_TYPES, true ) ) {

            $error = self::validateSingleField( $query, $type, 'metrics', $where );

            if ( $error !== '' ) {

                return $error;
            }
        }

        if ( in_array( $type, self::SINGLE_FIELD_TYPES, true ) ) {

            $error = self::validateSingleField( $query, $type, 'dimensions', $where );

            if ( $error !== '' ) {

                return $error;
            }
        }

        /*
         * A type that may not inherit a set has to have named some.
         *
         * AFTER the single-field check, which says the same thing more
         * precisely for a card or a pie: "draws one metric, this one names 0"
         * names the count as well as the rule. What is left for this to catch
         * is the type that takes SEVERAL of its own -- a trend card -- where
         * nothing else would notice an empty list, because an empty list is
         * legal on every type that CAN inherit.
         */
        if ( in_array( $type, self::OWN_METRIC_TYPES, true )
             && ! self::asNames( $query['metrics'] ?? '' ) ) {

            return sprintf(
                '%s is a %s, which names its own metrics -- it does not take the '
              . 'report metric set. This one names none.',
                $where, self::WIDGET_TYPES[ $type ] ?? $type );
        }

        $error = self::validateFieldCount(
            isset( $query['metrics'] ) ? $query['metrics'] : '',
            self::maxMetricsFor( $type ), 'metrics', $where,
            'the boxes stop fitting a row and the numbers stop being readable' );

        if ( $error !== '' ) {

            return $error;
        }

        /*
         * Counted only where the whole list is the author's. A fixed-dimension
         * type does not choose its first one, and its own rule above has
         * already counted what it added beyond it -- running a cap check over
         * the full list here would refuse a trend for being grouped by the one
         * thing it must be grouped by.
         */
        if ( ! array_key_exists( $type, self::FIXED_DIMENSIONS ) ) {

            $error = self::validateFieldCount(
                isset( $query['dimensions'] ) ? $query['dimensions'] : '',
                self::maxDimensionsFor( $type ), 'dimensions', $where,
                'every dimension multiplies the rows, and a grid grouped that many ways '
              . 'is a list of near-unique rows' );

            if ( $error !== '' ) {

                return $error;
            }
        }

        /*
         * ...and everything asked for has to be ASKABLE TOGETHER.
         *
         * Metrics, dimensions AND the dimensions named by the widget's
         * constraints: a query is answered from one fact table, and that table
         * has to serve all three. Checked after the names resolve, because a
         * combination check on a name that does not exist would report the
         * wrong problem.
         */
        $error = self::validateCombination(
            isset( $query['metrics'] ) ? $query['metrics'] : '',
            isset( $query['dimensions'] ) ? $query['dimensions'] : '',
            isset( $widget['constraints'] ) ? $widget['constraints'] : '',
            $where );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateChartMetrics( $widget, $where );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateLink( $widget, $where );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateMore( $widget, $where );

        if ( $error !== '' ) {

            return $error;
        }

        /*
         * Sorts carry a direction as a trailing '-', which is not part of the
         * name. Stripping it here rather than validating the whole token is
         * what lets `visits-` resolve as `visits`; the direction itself never
         * reaches SQL as text -- sortStringToArray turns it into the literal
         * ASC or DESC.
         */
        if ( ! empty( $query['sort'] ) ) {

            foreach ( explode( ',', (string) $query['sort'] ) as $sort ) {

                $name = rtrim( trim( $sort ), '-' );

                if ( $name === '' ) {

                    continue;
                }

                if ( ! self::isKnownName( $name ) ) {

                    return sprintf( '%s sorts on "%s", which is not a metric or a dimension', $where, $name );
                }
            }
        }

        return '';
    }

    /**
     * Every comma-separated name under $key must resolve as $kind.
     *
     * @param array  $bag
     * @param string $key
     * @param string $kind metric|dimension
     * @param string $where
     * @return string
     */
    private static function validateNames( array $bag, $key, $kind, $where ) {

        if ( empty( $bag[ $key ] ) ) {

            return '';
        }

        $names = is_array( $bag[ $key ] ) ? $bag[ $key ] : explode( ',', (string) $bag[ $key ] );

        foreach ( $names as $name ) {

            $name = trim( (string) $name );

            if ( $name === '' ) {

                continue;
            }

            $known = $kind === 'metric' ? self::isMetric( $name ) : self::isDimension( $name );

            if ( ! $known ) {

                return sprintf( '%s names "%s", which is not a known %s', $where, $name, $kind );
            }
        }

        return '';
    }

    /**
     * Whether these metrics can be asked for in one query.
     *
     * Every metric is computed from one or more FACT TABLES, and a query is
     * answered from one of them -- so a set is only askable if its metrics
     * share a table. `domClicks` is measured in the click table alone and
     * `visits` in the session or the request; no table holds both, so asking
     * for them together is not a thin result, it is not a question.
     *
     * The answer comes from ResultSetManager, which performs exactly this
     * reduction when it chooses a base entity. Asking IT rather than keeping a
     * list here is what stops the two drifting: a metric registered tomorrow is
     * covered without anything being added.
     *
     * @param string|array $metrics
     * @param string|array $dimensions
     * @param string $constraints the widget's constraint string, if any
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateCombination( $metrics, $dimensions = '', $constraints = '', $where = '' ) {

        $names = self::asNames( $metrics );
        $dims  = self::asNames( $dimensions );

        /*
         * Constraints contribute their DIMENSIONS to the same reduction -- the
         * engine folds them in with getDimensionsFromConstraints(), so a
         * constraint on a field the fact table does not carry breaks a query
         * exactly as a dimension does.
         */
        foreach ( self::constraintDimensions( $constraints ) as $name ) {

            $dims[] = $name;
        }

        // Nothing to reconcile: one field is always askable on its own.
        if ( count( $names ) + count( $dims ) < 2 ) {

            return '';
        }

        if ( ! $names ) {

            /*
             * Dimensions alone cannot be reduced here: the entity list starts
             * from the METRICS, and a widget with none inherits the report's.
             * Left to the query, which checks the pair it actually runs with.
             */
            return '';
        }

        $rsm = new ResultSetManager;

        $clash = $rsm->firstIncompatible( $names, $dims );

        if ( ! $clash ) {

            return '';
        }

        /*
         * The message NAMES BOTH SIDES. Listing everything asked for tells the
         * author nothing they can act on; naming the field that broke the set
         * and what it clashes with tells them which one to remove.
         */
        return $where . ': ' . ResultSetManager::incompatibleMessage(
            $clash['name'], $clash['with'], $clash['kind'] );
    }

    /**
     * At most $max of one kind of field in one query.
     *
     * @param string|array $fields
     * @param int    $max
     * @param string $kind   metrics|dimensions, for the message
     * @param string $where
     * @param string $why    what goes wrong past the limit
     * @return string
     */
    private static function validateFieldCount( $fields, $max, $kind, $where, $why ) {

        $names = self::asNames( $fields );

        if ( count( $names ) <= $max ) {

            return '';
        }

        return sprintf(
            '%s asks for %d %s; %d is the most one widget can carry. Beyond that %s -- '
          . 'split them across widgets instead.',
            $where, count( $names ), $kind, $max, $why );
    }

    /**
     * A single-field widget has one metric and one dimension. Both, and one of
     * each.
     *
     * @param array  $query
     * @param string $type
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateSingleField( array $query, $type, $key, $where ) {

        $label = isset( self::WIDGET_TYPES[ $type ] ) ? self::WIDGET_TYPES[ $type ] : $type;

        $count = count( self::asNames( isset( $query[ $key ] ) ? $query[ $key ] : '' ) );

        if ( $count === 1 ) {

            return '';
        }

        return sprintf(
            '%s is a %s, which draws one %s. This one names %d %s. %s',
            $where, $label, rtrim( $key, 's' ), $count, $key,
            $key === 'metrics'
                ? 'A report metric set is several metrics, and there is nothing to say '
                  . 'which of them this would draw.'
                : sprintf( 'Use a %s if you need more than one.', self::WIDGET_TYPES['grid'] ) );
    }

    // ------------------------------------------------------------------
    // Linking a card's rows to the report that details them
    // ------------------------------------------------------------------

    /**
     * The reports a row of this dimension can lead to, keyed by dimension.
     *
     * DERIVED, not listed. A detail report declares the constraint it is read
     * under -- browser-detail says `{dimension: browserType, fromParam:
     * browserType}` -- so the report itself already knows which dimension a
     * link into it has to supply. Reading that means the builder offers a card
     * grouped by browserType exactly one destination, and cannot offer it
     * "Product Detail", which would render a link to a report that refuses the
     * request for a parameter it never gets.
     *
     * ONE parameter, distinct. A report wanting two cannot be reached from a
     * single column, and one wanting none is not a detail of anything.
     * document.json names three constraints and one parameter, which is why
     * the count is of distinct parameters rather than of constraints.
     *
     * @return array dimension => list of {id, label, param}
     */
    public static function linkTargetsByDimension() {

        $targets = array();

        foreach ( \OWA\Core\CoreAPI::getReportRegistry() as $id => $registration ) {

            $path = isset( $registration['json'] ) ? (string) $registration['json'] : '';

            /*
             * JSON-defined reports only. A report that resolves to a controller
             * declares its constraints in code, where this cannot read them --
             * so it is not offered rather than offered on a guess.
             */
            if ( $path === '' || ! is_readable( $path ) ) {

                continue;
            }

            $definition = json_decode( (string) file_get_contents( $path ), true );

            if ( ! is_array( $definition ) ) {

                continue;
            }

            $params = array_values( array_unique(
                \OWA\Core\ConfiguredReport::constraintParams( $definition ) ) );

            if ( count( $params ) !== 1 ) {

                continue;
            }

            $dimension = self::constrainedDimension( $definition, $params[0] );

            if ( $dimension === '' ) {

                continue;
            }

            $targets[ $dimension ][] = array(
                'id'    => (string) $id,
                'label' => self::reportLabel( $definition, $id ),
                'param' => $params[0],
            );
        }

        foreach ( $targets as $dimension => $list ) {

            usort( $list, static function ( $a, $b ) {

                return strnatcasecmp( $a['label'], $b['label'] );
            } );

            $targets[ $dimension ] = $list;
        }

        ksort( $targets );

        return $targets;
    }

    /**
     * The reports a widget can offer a "View Full Report" link to, per dimension.
     *
     * SCOPED TO THE DIMENSION, like the row link, and for the same reason: a
     * card of top pages leads to the full Top Pages report, not to a list of
     * every report on the install. What makes a report "the full one" is that
     * it shows the same thing -- so a report qualifies for a dimension when one
     * of its own widgets queries that dimension.
     *
     * Two conditions, and the second is what separates this list from
     * linkTargetsByDimension(): the destination must read NO parameter. This
     * link carries no value from the widget, so a detail report followed from
     * here would answer 400. The row link is the one that can reach those.
     *
     * Derived rather than listed, and it reproduces all twelve of the shipped
     * summary widgets' links exactly -- top products to Products, top page
     * types to Page Types, latest visits to Latest Visits.
     *
     * @return array dimension => list of {id, label}
     */
    public static function moreTargetsByDimension() {

        $targets = array();

        foreach ( \OWA\Core\CoreAPI::getReportRegistry() as $id => $registration ) {

            $path = isset( $registration['json'] ) ? (string) $registration['json'] : '';

            /*
             * JSON-defined reports only. A controller-backed report declares
             * neither its parameters nor its dimensions anywhere this can read,
             * so there is no way to tell what it is the full report OF.
             */
            if ( $path === '' || ! is_readable( $path ) ) {

                continue;
            }

            $definition = json_decode( (string) file_get_contents( $path ), true );

            if ( ! is_array( $definition ) ) {

                continue;
            }

            if ( \OWA\Core\ConfiguredReport::constraintParams( $definition ) ) {

                continue;
            }

            $label = self::reportLabel( $definition, $id );

            foreach ( self::definitionDimensions( $definition ) as $dimension ) {

                $targets[ $dimension ][] = array( 'id' => (string) $id, 'label' => $label );
            }
        }

        foreach ( $targets as $dimension => $list ) {

            usort( $list, static function ( $a, $b ) {

                return strnatcasecmp( $a['label'], $b['label'] );
            } );

            $targets[ $dimension ] = $list;
        }

        ksort( $targets );

        return $targets;
    }

    /**
     * Every dimension a definition's widgets group by.
     *
     * What the report is ABOUT, as far as anything readable can tell.
     *
     * @param array $definition
     * @return array
     */
    private static function definitionDimensions( array $definition ) {

        $dimensions = array();

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

            $query = (array) ( ( (array) $widget )['query'] ?? array() );

            $dimensions = array_merge( $dimensions,
                self::asNames( $query['dimensions'] ?? '' ) );
        }

        return array_values( array_unique( $dimensions ) );
    }

    /**
     * What a chart plots has to be something the widget asked the database for.
     *
     * chartMetric names the metric -- or, for a trend, the metrics -- drawn as
     * lines. A name that is not in the query resolves to nothing in the result
     * set, and flot plots an empty series: a chart with a legend entry and no
     * line, which reads as "no data for this metric" rather than as a mistake.
     *
     * Checked against the widget's OWN metrics only when it has some. A widget
     * that declares none inherits the report metric set, and this is the wrong
     * place to re-derive that -- the names still have to resolve through the
     * registry, which validateNames() has already established.
     *
     * @param array  $widget
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateChartMetrics( array $widget, $where ) {

        if ( empty( $widget['chartMetric'] ) ) {

            return '';
        }

        $charted = self::asNames( $widget['chartMetric'] );

        /*
         * ONE. A chart plots a single metric over time; what varies is the
         * dimension it is broken out by, not the measure. A list here would
         * reach the chart as one unresolvable name.
         */
        if ( count( $charted ) !== 1 ) {

            return sprintf(
                '%s charts %d metrics; a chart draws one. The rest of a widget\'s '
              . 'metrics are drawn as boxes beneath it.',
                $where, count( $charted ) );
        }

        $error = self::validateNames(
            array( 'metrics' => $charted ), 'metrics', 'metric', $where );

        if ( $error !== '' ) {

            return $error;
        }

        $query = (array) ( $widget['query'] ?? array() );
        $own   = self::asNames( $query['metrics'] ?? '' );

        if ( ! $own ) {

            return '';
        }

        $missing = array_values( array_diff( $charted, $own ) );

        if ( $missing ) {

            return sprintf(
                '%s charts %s, which it does not measure. A chart draws lines from '
              . 'the metrics the widget asks for: %s.',
                $where, implode( ', ', $missing ), implode( ', ', $own ) );
        }

        return '';
    }

    /**
     * A widget's "View Full Report" link.
     *
     * The same shape the shipped summary widgets use -- a report id and the
     * words to show. The same rule as the in-row link, minus the constraint:
     * the destination has to be about a dimension this widget shows, and has to
     * need no value -- moreTargetsByDimension() offers only those, and this
     * refuses the rest rather than letting the builder be the only check.
     *
     * @param array  $widget
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateMore( array $widget, $where ) {

        if ( empty( $widget['more'] ) ) {

            return '';
        }

        if ( ! is_array( $widget['more'] ) ) {

            return $where . ' has a "more" link that is not an object';
        }

        $reportId = (string) ( $widget['more']['reportId'] ?? '' );

        $definition = $reportId === ''
            ? false
            : \OWA\Core\CoreAPI::getReportDefinition( $reportId );

        if ( ! $definition ) {

            return sprintf( '%s has a full-report link to "%s", which is not a registered report',
                $where, $reportId );
        }

        $unknown = array_diff( array_keys( $widget['more'] ), array( 'reportId', 'label' ) );

        if ( $unknown ) {

            return sprintf( '%s has a full-report link with unknown key(s): %s',
                $where, implode( ', ', $unknown ) );
        }

        if ( isset( $widget['more']['label'] ) && ! is_string( $widget['more']['label'] ) ) {

            return $where . ' has a full-report link whose label is not text';
        }

        /*
         * ...and the destination has to be one this widget can actually lead
         * to. Two ways it might not be:
         *
         * It reads a parameter. This link carries no value, so a detail report
         * followed from here answers 400 -- the row link is what reaches those.
         *
         * It is about something else. "View Full Report" under a table of top
         * pages means the full Top Pages report; a link to an unrelated one is
         * not a fuller version of anything, and there is no reading of the
         * words under which it is right.
         */
        $path = isset( $definition['json'] ) ? (string) $definition['json'] : '';

        $decoded = ( $path !== '' && is_readable( $path ) )
            ? json_decode( (string) file_get_contents( $path ), true )
            : null;

        if ( ! is_array( $decoded ) ) {

            return '';   // nothing readable to check it against
        }

        $needs = \OWA\Core\ConfiguredReport::constraintParams( $decoded );

        if ( $needs ) {

            return sprintf(
                '%s has a full-report link to "%s", which is read under %s -- a link '
              . 'below a widget carries no value, so that report cannot be reached '
              . 'from one. Link the rows instead.',
                $where, $reportId, implode( ', ', array_unique( $needs ) ) );
        }

        $mine  = self::asNames( ( (array) ( $widget['query'] ?? array() ) )['dimensions'] ?? '' );
        $shown = self::definitionDimensions( $decoded );

        if ( ! array_intersect( $mine, $shown ) ) {

            return sprintf(
                '%s has a full-report link to "%s", which does not show %s. A full '
              . 'report link goes to the report that shows more of the same thing.',
                $where, $reportId,
                $mine ? implode( ' or ', $mine ) : 'anything this widget shows' );
        }

        return '';
    }

    /**
     * The dimension a report is constrained on, for the parameter it takes.
     *
     * Usually the same name as the parameter, and that is preferred when the
     * report constrains several dimensions from one value: document.json
     * constrains pagePath AND priorPagePath from `pagePath`, and a link into it
     * is a link from a pagePath column.
     *
     * @param array  $definition
     * @param string $param
     * @return string '' when it cannot be told
     */
    private static function constrainedDimension( array $definition, $param ) {

        $dimensions = array();

        $collect = static function ( $constraints ) use ( &$dimensions, $param ) {

            if ( ! is_array( $constraints ) ) {

                return;
            }

            foreach ( $constraints as $part ) {

                $part = (array) $part;

                if ( ( $part['fromParam'] ?? '' ) === $param && ! empty( $part['dimension'] ) ) {

                    $dimensions[] = (string) $part['dimension'];
                }
            }
        };

        $collect( $definition['settings']['constraints'] ?? null );

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

            $collect( ( (array) $widget )['constraints'] ?? null );
        }

        $dimensions = array_values( array_unique( $dimensions ) );

        if ( in_array( $param, $dimensions, true ) ) {

            return $param;
        }

        return count( $dimensions ) === 1 ? $dimensions[0] : '';
    }

    /**
     * A report's name, without the value it is about.
     *
     * A detail report's title is "Browser Detail: {browserType}" -- the
     * placeholder is filled per request, so in a list of destinations it is the
     * part that is not the name. Falls back to the id, which is at least
     * addressable.
     *
     * @param array  $definition
     * @param string $id
     * @return string
     */
    private static function reportLabel( array $definition, $id ) {

        $title = trim( (string) ( $definition['title'] ?? '' ) );

        $title = trim( (string) preg_replace( '/\{[^}]*\}/', '', $title ) );

        // "Browser Detail:" once the placeholder is gone.
        $title = trim( $title, " \t:-\xE2\x80\x93\xE2\x80\x94" );

        return $title !== '' ? $title : (string) $id;
    }

    /**
     * A widget's row link: where it goes, and what it carries.
     *
     * A link becomes makeLink() output on the rendered page, so the whole shape
     * is pinned rather than the report id alone -- otherwise a stored
     * definition could address any action with any parameters, and the builder
     * would be the only thing stopping it.
     *
     * @param array  $widget
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateLink( array $widget, $where ) {

        if ( empty( $widget['link'] ) ) {

            return '';
        }

        if ( ! is_array( $widget['link'] ) ) {

            return $where . ' has a link that is not an object';
        }

        $link       = $widget['link'];
        $dimensions = self::asNames( ( (array) ( $widget['query'] ?? array() ) )['dimensions'] ?? '' );

        foreach ( array( 'linkColumn', 'valueColumns' ) as $key ) {

            $column = (string) ( $link[ $key ] ?? '' );

            if ( $column === '' ) {

                return sprintf( '%s has a link with no %s', $where, $key );
            }

            if ( ! in_array( $column, $dimensions, true ) ) {

                return sprintf(
                    '%s links from "%s", which is not a column it shows. A link has to '
                  . 'come from one of this widget\'s dimensions: %s',
                    $where, $column, implode( ', ', $dimensions ) ?: '(none)' );
            }
        }

        $template = (array) ( $link['template'] ?? array() );

        if ( ( $template['do'] ?? '' ) !== self::LINK_ACTION ) {

            return sprintf( '%s has a link to something other than a report', $where );
        }

        $reportId = (string) ( $template['reportId'] ?? '' );

        if ( $reportId === '' || ! \OWA\Core\CoreAPI::getReportDefinition( $reportId ) ) {

            return sprintf( '%s links to report "%s", which is not registered',
                $where, $reportId );
        }

        /*
         * `do`, `reportId` and the one parameter the destination reads. Any
         * other key would be a parameter this report chose to put in a URL, and
         * nothing the builder can produce needs one.
         */
        $extra = array_diff( array_keys( $template ), array( 'do', 'reportId' ) );

        if ( count( $extra ) !== 1 ) {

            return sprintf(
                '%s has a link carrying %d parameters; it carries the one the '
              . 'destination is read under, and nothing else.',
                $where, count( $extra ) );
        }

        $value = (string) $template[ reset( $extra ) ];

        if ( $value !== '%s' ) {

            return sprintf(
                '%s has a link whose parameter is "%s"; it is filled from the row, so '
              . 'it has to be %%s', $where, $value );
        }

        return '';
    }

    /** A comma string or list, as trimmed non-empty names. */
    private static function asNames( $value ) {

        $names = is_array( $value ) ? $value : explode( ',', (string) $value );

        return array_values( array_filter( array_map( 'trim', $names ) ) );
    }

    /**
     * The dimension names a constraint string mentions.
     *
     * A constraint is `name==value`, comma separated. Only the NAME matters
     * here, and only when it is a dimension -- a constraint on a metric is a
     * having clause and does not decide the fact table.
     *
     * @param string $constraints
     * @return array
     */
    private static function constraintDimensions( $constraints ) {

        $out = array();

        foreach ( self::asNames( $constraints ) as $clause ) {

            // Split on the first operator character; the operators are
            // ==, !=, >, <, >=, <=, =~ and so on, all starting with one of these.
            $name = preg_split( '/[=!<>~]/', $clause, 2 )[0];
            $name = trim( (string) $name );

            if ( $name !== '' && self::isDimension( $name ) ) {

                $out[] = $name;
            }
        }

        return $out;
    }

    /** Whether the name resolves as either kind -- what a sort may be. */
    private static function isKnownName( $name ) {

        return self::isMetric( $name ) || self::isDimension( $name );
    }

    public static function isMetric( $name ) {

        $metrics = (array) \OWA\Core\CoreAPI::getAllMetrics();

        return array_key_exists( $name, $metrics );
    }

    public static function isDimension( $name ) {

        $dimensions = (array) \OWA\Core\CoreAPI::getAllDimensions();

        return array_key_exists( $name, $dimensions );
    }

    /**
     * One stored report, or null.
     *
     * Returns the ROW, decoded, rather than an entity: every caller wants the
     * definition as an array and the name as a string, and handing back an
     * entity would make each of them remember to decode.
     *
     * @param string $id
     * @return array|null {id, name, user_id, definition, ...}
     */
    public static function load( $id ) {

        if ( (string) $id === '' ) {

            return null;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $entity->load( $id );

        if ( ! $entity->wasPersisted() ) {

            return null;
        }

        return self::hydrate( $entity->_getProperties() );
    }

    /**
     * The reports a user may see listed.
     *
     * Ownership governs the ROSTER, not viewing: an admin sees every report, a
     * non-admin sees the ones they created. A report reached by its URL renders
     * for anyone with view_reports, which is what makes the URL shareable --
     * and is safe because a custom report can show nothing its reader could not
     * already query for themselves.
     *
     * @param string $user_id
     * @param bool   $all     true for a user who may see everyone's
     * @return array
     */
    public static function roster( $user_id, $all = false, $sort = '', $descending = null, $limit = null ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $sql    = 'SELECT * FROM owa_custom_report';
        $params = array();

        if ( ! $all ) {

            $sql     .= ' WHERE user_id = ?';
            $params[] = (string) $user_id;
        }

        /*
         * The column comes from the allowlist, never from the request. An
         * unknown key falls back to the default rather than erroring: a sort is
         * a way of looking at a list, and a bad one should not take the list
         * away.
         */
        $key = isset( self::ROSTER_SORTS[ $sort ] ) ? $sort : self::ROSTER_DEFAULT_SORT;

        /*
         * Direction defaults to what each column is usually wanted in: a date
         * newest-first, a name A-to-Z. Asking for "updated" and getting the
         * oldest first is right once and wrong every other time.
         */
        if ( $descending === null ) {

            $descending = ( $key === 'updated' );
        }

        // A literal, not a parameter: ASC and DESC are the only two values this
        // can ever be, chosen here rather than passed through.
        $rows_wanted = ( $limit === null ) ? self::ROSTER_LIMIT : max( 0, (int) $limit );

        $sql .= ' ORDER BY ' . self::ROSTER_SORTS[ $key ]
              . ( $descending ? ' DESC' : ' ASC' )
              . ' LIMIT ' . (int) $rows_wanted;

        // get_results(), not query()->fetchAll(): query() returns the DRIVER's
        // own result and only PDO's has fetchAll(). Null for both no rows and a
        // failed query.
        $rows = $db->get_results( $sql, $params );

        if ( $rows === null ) {

            return array();
        }

        return array_map( array( __CLASS__, 'hydrate' ), $rows );
    }

    /** A stored row, with its definition decoded. */
    private static function hydrate( $row ) {

        $row = (array) $row;

        $raw = isset( $row['definition'] ) ? (string) $row['definition'] : '';

        /*
         * Stored strings are entity-encoded on write, so a definition comes
         * back with its quotes as &quot; and does not parse until they are
         * turned back. Decoding here means no caller has to know that.
         */
        $decoded = json_decode( html_entity_decode( $raw, ENT_QUOTES ), true );

        $row['definition'] = is_array( $decoded ) ? $decoded : array();

        return $row;
    }

    /**
     * Store a report, creating or replacing.
     *
     * Returns the id on success, or a string error. The caller decides how to
     * show the error; this decides whether there is one.
     *
     * @param array  $fields {id, name, definition}
     * @param string $user_id the author, for a new report
     * @return array {ok:bool, id:string, error:string}
     */
    public static function save( array $fields, $user_id ) {

        $name = trim( (string) ( $fields['name'] ?? '' ) );

        if ( $name === '' ) {

            return self::failure( 'a report needs a name' );
        }

        $definition = $fields['definition'] ?? array();

        if ( is_string( $definition ) ) {

            $decoded = json_decode( $definition, true );

            if ( ! is_array( $decoded ) ) {

                return self::failure( 'the report definition is not valid JSON: ' . json_last_error_msg() );
            }

            $definition = $decoded;
        }

        /*
         * The name the author typed is the title the report renders. Set here
         * rather than asked for twice: two fields that must agree is a way for
         * the roster and the report heading to disagree.
         */
        $definition['title'] = $name;

        $definition = self::normalize( $definition );

        $error = self::validate( $definition );

        if ( $error !== '' ) {

            return self::failure( $error );
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        $id  = (string) ( $fields['id'] ?? '' );
        $now = time();

        if ( $id !== '' ) {

            $entity->load( $id );

            if ( ! $entity->wasPersisted() ) {

                return self::failure( 'that report no longer exists' );
            }
        }

        $entity->set( 'name', $name );
        $entity->set( 'definition', json_encode( $definition ) );
        $entity->set( 'last_updated_timestamp', $now );

        if ( $id === '' ) {

            $id = (string) \OWA\Core\Lib::generateRandomUid();

            $entity->set( 'id', $id );
            $entity->set( 'user_id', (string) $user_id );
            $entity->set( 'creation_timestamp', $now );
            $entity->create();

        } else {

            // user_id is NOT reassigned on edit. The creator is a fact about
            // the report, and an admin editing someone's report must not
            // silently become its author on the roster.
            $entity->update();
        }

        return array( 'ok' => true, 'id' => $id, 'error' => '' );
    }

    /**
     * Settle the things the TYPE decides, before the definition is stored.
     *
     * Only one so far: a full-width type carries no colspan. Dropping the key
     * rather than writing 12 is what makes the rule survive a change to what
     * full width means -- ReportGrid answers that question, and a definition
     * that had baked in the old number would keep the old layout.
     *
     * Here rather than in validate(), because this is not a reason to refuse a
     * report. A definition arriving with a colspan on a grid is one written
     * against an older builder, or by hand; the width simply is not the
     * author's to choose, so it is taken off rather than argued about.
     *
     * @param array $definition
     * @return array
     */
    public static function normalize( array $definition ) {

        if ( empty( $definition['widgets'] ) || ! is_array( $definition['widgets'] ) ) {

            return $definition;
        }

        foreach ( $definition['widgets'] as $i => $widget ) {

            if ( ! is_array( $widget ) ) {

                continue;
            }

            $type = isset( $widget['type'] ) ? (string) $widget['type'] : '';

            if ( in_array( $type, self::FULL_WIDTH_TYPES, true ) ) {

                unset( $definition['widgets'][ $i ]['colspan'] );
            }
        }

        return $definition;
    }

    /**
     * How many custom reports the left-hand nav lists.
     *
     * The nav is a way IN, not an index -- the roster is the index, and the
     * group's own heading links to it. Ten is enough that somebody's current
     * work is nearly always there and few enough that the group can be read
     * without scrolling.
     */
    const NAV_LIMIT = 10;

    /**
     * The most recently changed reports a user may see, for the nav.
     *
     * Same visibility rule as the roster, because it is the same list: an admin
     * sees everyone's, everyone else sees their own. Ordered by when they
     * changed, which is the order that puts what somebody is working on at the
     * top.
     *
     * @param string $user_id
     * @param bool   $all
     * @return array
     */
    public static function recent( $user_id, $all = false ) {

        return self::roster( $user_id, $all, 'updated', true, self::NAV_LIMIT );
    }

    /**
     * @param string $id
     * @return bool
     */
    public static function delete( $id ) {

        if ( (string) $id === '' ) {

            return false;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $entity->load( $id );

        if ( ! $entity->wasPersisted() ) {

            return false;
        }

        $entity->delete( $id );

        return true;
    }

    /**
     * Whether this user may edit this report.
     *
     * Editing is narrower than viewing: the creator, or anyone who may see
     * every report. Checked against the ROW rather than re-read, so a caller
     * cannot check one report and act on another.
     *
     * @param array|null $report
     * @param string     $user_id
     * @param bool       $all
     * @return bool
     */
    public static function mayEdit( $report, $user_id, $all = false ) {

        if ( ! $report ) {

            return false;
        }

        if ( $all ) {

            return true;
        }

        return (string) ( $report['user_id'] ?? '' ) === (string) $user_id
            && (string) $user_id !== '';
    }

    /** @return array */
    private static function failure( $message ) {

        return array( 'ok' => false, 'id' => '', 'error' => $message );
    }
}
