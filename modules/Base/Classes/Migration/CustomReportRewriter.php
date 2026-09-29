<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A saved 1.14 custom report, rewritten into v2's names (PLAN.html 2.21).
 *
 * Per name, through V1Names: renamed where v2 has an equivalent, dropped where
 * it has none. A widget goes only when what is left cannot render -- no
 * metric, or a grid whose dimensions were all removed, which would collapse
 * into one total row. A dropped constraint widens the numbers and says so.
 * Every change is returned as a line for the update's output.
 *
 * Pure: a definition in, a definition and its changes out. What is stored,
 * and what is kept of the original, is the update's.
 */
class CustomReportRewriter {

    /** @var string[] */
    private $changes = array();

    /** @var array|null report ids by dimension, read once */
    private $more_targets = null;

    /** @var array|null */
    private $link_targets = null;

    /**
     * @param  array $definition
     * @return array [ 'definition' => array, 'changes' => string[] ]
     */
    public function rewrite( array $definition ) {

        $this->changes = array();

        if ( isset( $definition['metrics'] ) ) {

            $definition['metrics'] = $this->names( $definition['metrics'], 'metric', 'report metric set' );

            if ( ! $definition['metrics'] ) {

                unset( $definition['metrics'] );
            }
        }

        $widgets = array();

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $i => $widget ) {

            $where  = sprintf( 'widget %d', $i + 1 );
            $widget = is_array( $widget ) ? $this->widget( $widget, $where ) : $widget;

            if ( $widget !== null ) {

                $widgets[] = $widget;
            }
        }

        $definition['widgets'] = $widgets;

        return array( 'definition' => $definition, 'changes' => $this->changes );
    }

    /** @return array|null null when the widget cannot render any more */
    private function widget( array $widget, $where ) {

        $query = (array) ( $widget['query'] ?? array() );

        if ( isset( $query['metrics'] ) ) {

            $query['metrics'] = $this->names( $query['metrics'], 'metric', $where );

            if ( ! $query['metrics'] ) {

                $this->changes[] = sprintf( '%s dropped: none of its metrics exist in v2', $where );

                return null;
            }
        }

        $renamed = false;

        if ( isset( $query['dimensions'] ) ) {

            $before = self::asNames( $query['dimensions'] );
            $had    = (bool) $before;
            $query['dimensions'] = $this->names( $query['dimensions'], 'dimension', $where );

            if ( $had && ! $query['dimensions'] ) {

                $this->changes[] = sprintf( '%s dropped: none of its dimensions exist in v2, and it would'
                    . ' collapse into a single total', $where );

                return null;
            }

            $renamed = self::asNames( $query['dimensions'] ) !== $before;
        }

        if ( isset( $query['sort'] ) && (string) $query['sort'] !== '' ) {

            $descending = substr( (string) $query['sort'], -1 ) === '-';
            $name       = rtrim( (string) $query['sort'], '-' );
            $to         = $this->one( $name, 'either', $where . ' sort' );

            if ( $to === null ) {

                unset( $query['sort'] );

            } else {

                $query['sort'] = $to . ( $descending ? '-' : '' );
            }
        }

        $widget['query'] = $query;

        if ( isset( $widget['chartMetric'] ) ) {

            $widget['chartMetric'] = $this->names( $widget['chartMetric'], 'metric', $where . ' chart' );

            if ( ! $widget['chartMetric'] ) {

                unset( $widget['chartMetric'] );
            }
        }

        if ( isset( $widget['constraints'] ) && is_string( $widget['constraints'] ) ) {

            $widget['constraints'] = $this->constraints( $widget['constraints'], $where );

            if ( $widget['constraints'] === '' ) {

                unset( $widget['constraints'] );
            }
        }

        if ( isset( $widget['link'] ) && is_array( $widget['link'] ) ) {

            $widget['link'] = $this->link( $widget['link'], $where );

            if ( $widget['link'] === null ) {

                unset( $widget['link'] );
            }
        }

        if ( isset( $widget['more'] ) && is_array( $widget['more'] ) ) {

            $reportId = (string) ( $widget['more']['reportId'] ?? '' );

            if ( $reportId !== '' && ! \OWA\Core\CoreAPI::getReportDefinition( $reportId ) ) {

                unset( $widget['more'] );
                $this->changes[] = sprintf( '%s: its "more" link to report "%s" is removed; that report'
                    . ' is gone', $where, $reportId );
            }
        }

        if ( $renamed ) {

            $widget = $this->refit( $widget, $where );
        }

        return $widget;
    }

    /**
     * A link that fitted the old dimensions and not the new ones is removed.
     *
     * A full-report link must go to a report that shows one of the widget's
     * dimensions, and a row link to one that is read by its link column. A
     * renamed dimension -- pagePath to pagePathPlusQuery -- can leave a link
     * pointing at a report that groups by the old name.
     */
    private function refit( array $widget, $where ) {

        $dimensions = self::asNames( $widget['query']['dimensions'] ?? '' );

        if ( isset( $widget['more']['reportId'] ) ) {

            if ( $this->more_targets === null ) {

                $this->more_targets = \OWA\Module\Base\Classes\CustomReports::moreTargetsByDimension();
            }

            $fits = false;

            foreach ( $dimensions as $dimension ) {

                $fits = $fits || in_array( $widget['more']['reportId'],
                    array_column( $this->more_targets[ $dimension ] ?? array(), 'id' ), true );
            }

            if ( ! $fits ) {

                $this->changes[] = sprintf( '%s: its "more" link to report "%s" is removed; that report'
                    . ' does not show %s', $where, $widget['more']['reportId'], implode( ', ', $dimensions ) );

                unset( $widget['more'] );
            }
        }

        if ( isset( $widget['link']['template']['reportId'], $widget['link']['linkColumn'] ) ) {

            if ( $this->link_targets === null ) {

                $this->link_targets = \OWA\Module\Base\Classes\CustomReports::linkTargetsByDimension();
            }

            $column = (string) $widget['link']['linkColumn'];

            if ( ! in_array( $widget['link']['template']['reportId'],
                    array_column( $this->link_targets[ $column ] ?? array(), 'id' ), true ) ) {

                $this->changes[] = sprintf( '%s: its row link to report "%s" is removed; that report is not'
                    . ' read by %s', $where, $widget['link']['template']['reportId'], $column );

                unset( $widget['link'] );
            }
        }

        return $widget;
    }

    /**
     * Names in a comma list or an array, each mapped; dropped ones removed.
     *
     * @return string the list, comma-separated
     */
    private function names( $value, $kind, $where ) {

        $out = array();

        foreach ( self::asNames( $value ) as $name ) {

            $to = $this->one( $name, $kind, $where );

            if ( $to !== null && ! in_array( $to, $out, true ) ) {

                $out[] = $to;
            }
        }

        return implode( ',', $out );
    }

    /** One name, mapped and logged; null when v2 has no equivalent. */
    private function one( $name, $kind, $where ) {

        if ( $kind === 'either' ) {

            $mapped = V1Names::metric( $name );

            if ( ! $mapped['known'] ) {

                $mapped = V1Names::dimension( $name );
            }

        } else {

            $mapped = $kind === 'metric' ? V1Names::metric( $name ) : V1Names::dimension( $name );
        }

        if ( ! $mapped['known'] ) {

            return $name;
        }

        if ( $mapped['to'] === null ) {

            $this->changes[] = sprintf( '%s: dropped %s, which v2 does not have', $where, $name );

            return null;
        }

        if ( $mapped['to'] !== $name ) {

            $this->changes[] = sprintf( '%s: %s -> %s', $where, $name, $mapped['to'] );
        }

        if ( isset( V1Names::REDEFINED[ $name ] ) ) {

            $this->changes[] = sprintf( '%s: %s is %s', $where, $name, V1Names::REDEFINED[ $name ] );
        }

        return $mapped['to'];
    }

    /** A constraint string, clause by clause. */
    private function constraints( $constraints, $where ) {

        $out = array();

        foreach ( explode( ',', $constraints ) as $clause ) {

            $clause = trim( $clause );

            if ( $clause === '' ) {

                continue;
            }

            if ( ! preg_match( '/^([A-Za-z0-9_]+)\s*([=!<>~@]+)(.*)$/s', $clause, $m ) ) {

                $out[] = $clause;

                continue;
            }

            list( , $name, $operator, $value ) = $m;

            if ( isset( V1Names::VALUES[ $name ] ) ) {

                $translated = V1Names::VALUES[ $name ][ strtolower( trim( $value ) ) ] ?? null;

                if ( $translated !== null && in_array( $operator, array( '==', '!=' ), true ) ) {

                    $out[] = 'newVsReturning' . $operator . $translated;
                    $this->changes[] = sprintf( '%s constraint: %s -> newVsReturning%s%s',
                        $where, $clause, $operator, $translated );

                    continue;
                }
            }

            $mapped = V1Names::dimension( $name );

            if ( ! $mapped['known'] ) {

                $out[] = $clause;

                continue;
            }

            if ( $mapped['to'] === null || isset( V1Names::VALUES[ $name ] ) ) {

                $this->changes[] = sprintf( '%s constraint dropped: %s (v2 has no equivalent), so the'
                    . ' widget now counts more than it did', $where, $clause );

                continue;
            }

            if ( $mapped['to'] !== $name ) {

                $this->changes[] = sprintf( '%s constraint: %s -> %s', $where, $name, $mapped['to'] );
            }

            $out[] = $mapped['to'] . $operator . $value;
        }

        return implode( ',', $out );
    }

    /** A link's columns mapped; null when it cannot be kept. */
    private function link( array $link, $where ) {

        foreach ( array( 'linkColumn', 'valueColumns' ) as $key ) {

            if ( ! isset( $link[ $key ] ) ) {

                continue;
            }

            $mapped = V1Names::dimension( (string) $link[ $key ] );

            if ( $mapped['known'] && $mapped['to'] === null ) {

                $this->changes[] = sprintf( '%s: its link is removed; %s does not exist in v2',
                    $where, $link[ $key ] );

                return null;
            }

            $link[ $key ] = $mapped['to'];
        }

        $reportId = (string) ( $link['template']['reportId'] ?? '' );

        if ( $reportId !== '' && ! \OWA\Core\CoreAPI::getReportDefinition( $reportId ) ) {

            $this->changes[] = sprintf( '%s: its link to report "%s" is removed; that report is gone',
                $where, $reportId );

            return null;
        }

        return $link;
    }

    private static function asNames( $value ) {

        $names = is_array( $value ) ? $value : explode( ',', (string) $value );

        return array_values( array_filter( array_map( 'trim', $names ), 'strlen' ) );
    }
}

?>
