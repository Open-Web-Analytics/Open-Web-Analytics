<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The goal metrics are named for what they count: goal events.
 *
 *   goalConversions               -> goalEvents
 *   goalConversionRatePerSession  -> goalEventsPerSession
 *   goalConversionRatePerUser     -> goalEventsPerUser
 *   the `conversions` metric set  -> `goals`
 *
 * Names resolve exactly and there is no alias layer, so a saved report naming
 * an old one would fail whole. This rewrites them wherever saved data names a
 * metric, which is owa_custom_report.definition and nowhere else: reports,
 * visualizations and shared charts are all rows of it, and no setting holds a
 * metric name. In a definition, a metric is named by
 *
 *   metrics                  the report's own set, a list or a comma string
 *   metricSets               a list of set names, or sets declared with their
 *                            own metrics and chartMetric
 *   widgets[].query.metrics  a list or a comma string
 *   widgets[].query.sort     a comma string, each name with an optional '-'
 *   widgets[].chartMetric
 *   widgets[].excludeColumns a list of column names
 *   widgets[].formatters     keyed by column name
 *
 * and only those places are read. A name anywhere else -- a title, a
 * constraint's value -- is the author's text, not a metric, and stays.
 *
 * RAW SQL AND PHP'S JSON, not the entity, which is cachable and would answer
 * from a cache the UPDATE leaves behind. A definition is read the way
 * CustomReports::hydrate() reads it, and the names are replaced in the stored
 * text where that can be shown exact (rewriteStored()), so nothing else in it
 * changes. A definition that does not decode is named and left as it is.
 *
 * down() is the inverse map. Each direction rewrites only the names it maps,
 * so each runs twice to the same result. A report saved since naming
 * goalValue, which has no name before this, keeps it.
 *
 * NOT CLI-ONLY. Saved reports are a handful of rows.
 */
class Update078 extends \OWA\Core\Update {

    var $schema_version = 78;

    var $is_cli_mode_required = false;

    /** Old metric name => new. */
    const METRICS = array(
        'goalConversions'              => 'goalEvents',
        'goalConversionRatePerSession' => 'goalEventsPerSession',
        'goalConversionRatePerUser'    => 'goalEventsPerUser',
    );

    /** Old metric set name => new (Core\MetricSets). */
    const SETS = array(
        'conversions' => 'goals',
    );

    function up( $force = false ) {

        return $this->rewriteAll( self::METRICS, self::SETS );
    }

    /** The exact inverse. */
    function down() {

        return $this->rewriteAll( array_flip( self::METRICS ), array_flip( self::SETS ) );
    }

    /**
     * Rewrite every saved definition that names one of these.
     *
     * @param  array $metrics  from => to
     * @param  array $sets     from => to
     * @return bool
     */
    private function rewriteAll( array $metrics, array $sets ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' )->getTableName();

        // Narrowed by text, decided by structure: a row only mentioning a name is decoded and left.
        $like   = array();
        $params = array();

        foreach ( array_merge( array_keys( $metrics ), array_keys( $sets ) ) as $name ) {

            $like[]   = 'definition LIKE ?';
            $params[] = '%' . $name . '%';
        }

        $rows = (array) $db->get_results( sprintf( 'SELECT id, name, definition FROM %s WHERE %s',
            $table, implode( ' OR ', $like ) ), $params );

        $rewritten = 0;

        foreach ( $rows as $row ) {

            $row    = (array) $row;
            $stored = (string) $row['definition'];
            $text   = self::rewriteStored( $stored, $metrics, $sets );

            if ( $text === null ) {

                $this->e->notice( sprintf( 'Custom report "%s" (%s): its definition cannot be read; left as it is.',
                    $row['name'], $row['id'] ) );

                continue;
            }

            if ( $text === $stored ) {

                continue;
            }

            if ( $db->query( sprintf( 'UPDATE %s SET definition = ? WHERE id = ?', $table ),
                    array( $text, $row['id'] ) ) === false ) {

                $this->e->notice( sprintf( 'Renaming the goal metrics in custom report %s failed.', $row['id'] ) );

                return false;
            }

            $rewritten++;
        }

        if ( $rewritten ) {

            $this->e->notice( sprintf( 'Goal metrics renamed in %d saved %s.', $rewritten, \OWA\Core\Lib::plural( $rewritten, 'report', 'reports' ) ) );
        }

        return true;
    }

    /**
     * A stored definition with the names mapped, or null when it does not
     * decode. The stored text itself when nothing in it maps.
     *
     * IN THE STORED TEXT where that is exact, so the rest of the definition
     * keeps its bytes -- its encoding, entity or plain, included -- and down()
     * gives back what up() was given. Each name is replaced as a whole word
     * (a set name only as a whole quoted string), and the result is kept only
     * if it decodes to what rename() makes of the definition: a name the
     * author also wrote into a title would be replaced there too, decode
     * differently, and send this to the fallback, json_encode( rename() ).
     *
     * @param  string $stored   as the column holds it
     * @param  array  $metrics  from => to
     * @param  array  $sets     from => to
     * @return string|null
     */
    public static function rewriteStored( $stored, array $metrics, array $sets = array() ) {

        $stored     = (string) $stored;
        $definition = self::decode( $stored );

        if ( ! is_array( $definition ) ) {

            return null;
        }

        $renamed = self::rename( $definition, $metrics, $sets );

        if ( $renamed === $definition ) {

            return $stored;
        }

        $text = $stored;

        foreach ( $metrics as $from => $to ) {

            $text = (string) preg_replace(
                '/(?<![A-Za-z0-9_])' . preg_quote( $from, '/' ) . '(?![A-Za-z0-9_])/', $to, $text );
        }

        foreach ( $sets as $from => $to ) {

            $text = (string) preg_replace(
                '/("|&quot;)' . preg_quote( $from, '/' ) . '\1/', '${1}' . $to . '${1}', $text );
        }

        return self::decode( $text ) === $renamed ? $text : json_encode( $renamed );
    }

    /** A stored definition decoded, as CustomReports::hydrate() reads one. */
    private static function decode( $stored ) {

        return json_decode( html_entity_decode( (string) $stored, ENT_QUOTES ), true );
    }

    /**
     * A definition with its metric names, and its metric set names, mapped.
     *
     * Pure, so a test can hand it any shape.
     *
     * @param  array $definition  decoded
     * @param  array $metrics     from => to
     * @param  array $sets        from => to
     * @return array
     */
    public static function rename( array $definition, array $metrics, array $sets = array() ) {

        if ( isset( $definition['metrics'] ) ) {

            $definition['metrics'] = self::names( $definition['metrics'], $metrics );
        }

        if ( isset( $definition['metricSets'] ) && is_array( $definition['metricSets'] ) ) {

            $declared = array();

            foreach ( $definition['metricSets'] as $key => $set ) {

                // A list names the site's sets; an object declares the report's own.
                if ( is_string( $set ) ) {

                    $declared[ $key ] = $sets[ $set ] ?? $set;

                    continue;
                }

                if ( is_array( $set ) ) {

                    if ( isset( $set['metrics'] ) ) {

                        $set['metrics'] = self::names( $set['metrics'], $metrics );
                    }

                    if ( isset( $set['chartMetric'] ) ) {

                        $set['chartMetric'] = self::names( $set['chartMetric'], $metrics );
                    }
                }

                $declared[ $key ] = $set;
            }

            $definition['metricSets'] = $declared;
        }

        if ( isset( $definition['widgets'] ) && is_array( $definition['widgets'] ) ) {

            foreach ( $definition['widgets'] as $i => $widget ) {

                if ( is_array( $widget ) ) {

                    $definition['widgets'][ $i ] = self::widget( $widget, $metrics );
                }
            }
        }

        return $definition;
    }

    private static function widget( array $widget, array $metrics ) {

        if ( isset( $widget['query'] ) && is_array( $widget['query'] ) ) {

            if ( isset( $widget['query']['metrics'] ) ) {

                $widget['query']['metrics'] = self::names( $widget['query']['metrics'], $metrics );
            }

            if ( isset( $widget['query']['sort'] ) && is_string( $widget['query']['sort'] ) ) {

                $widget['query']['sort'] = self::sort( $widget['query']['sort'], $metrics );
            }
        }

        if ( isset( $widget['chartMetric'] ) ) {

            $widget['chartMetric'] = self::names( $widget['chartMetric'], $metrics );
        }

        if ( isset( $widget['excludeColumns'] ) && is_array( $widget['excludeColumns'] ) ) {

            $widget['excludeColumns'] = self::names( $widget['excludeColumns'], $metrics );
        }

        if ( isset( $widget['formatters'] ) && is_array( $widget['formatters'] ) ) {

            $formatters = array();

            foreach ( $widget['formatters'] as $column => $formatter ) {

                $formatters[ $metrics[ $column ] ?? $column ] = $formatter;
            }

            $widget['formatters'] = $formatters;
        }

        return $widget;
    }

    /**
     * A list of names, or a comma string of them, mapped and in the shape given.
     * Each keeps the spacing around it, so a string that maps nothing comes
     * back byte for byte and one that does differs only in the names.
     *
     * @param  mixed $names
     * @param  bool  $sort   each name may end in '-', a descending sort
     * @return mixed
     */
    private static function names( $names, array $map, $sort = false ) {

        if ( is_array( $names ) ) {

            return array_map( function ( $name ) use ( $map, $sort ) {

                return self::one( $name, $map, $sort );

            }, $names );
        }

        if ( ! is_string( $names ) ) {

            return $names;
        }

        return implode( ',', array_map( function ( $name ) use ( $map, $sort ) {

            return self::one( $name, $map, $sort );

        }, explode( ',', $names ) ) );
    }

    /** A sort string: names() with each name keeping its '-'. */
    private static function sort( $sort, array $map ) {

        return self::names( $sort, $map, true );
    }

    /** One name, mapped where the map has it. */
    private static function one( $part, array $map, $sort ) {

        if ( ! is_string( $part ) ) {

            return $part;
        }

        $name = trim( $part );
        $name = $sort ? rtrim( $name, '-' ) : $name;

        return isset( $map[ $name ] ) ? str_replace( $name, $map[ $name ], $part ) : $part;
    }
}

?>
