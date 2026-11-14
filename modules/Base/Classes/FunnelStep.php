<?php

namespace OWA\Module\Base\Classes;

/**
 * One funnel step: an event, and optionally conditions on what it carries.
 *
 * Stored in a funnel's definition as
 *
 *   { name, kind: 'event', event, match: 'all'|'any',
 *     conditions: [ { property, operator, value }, ... ], step_number }
 *
 * and counted by Classes\EventPredicate over the Property's cube. A page is a
 * page_view event with a page_path condition, which is what a new step starts
 * as. No conditions matches every event of that name.
 *
 * There used to be two other kinds: a path ({path}) and a goal event step
 * ({goal_event_definition_id}), which borrowed a definition's trigger and
 * conditions as a saved filter. Update079 turned the saved ones into event
 * steps, and neither is saved or counted any more.
 *
 * The rules a step is saved under live here, so the save (VisualizationSave),
 * the preview (VisualizationPreview) and their tests apply one set of them.
 */
class FunnelStep {

    const KIND_EVENT = 'event';

    /** What a new step matches: a page, by its path. */
    const DEFAULT_EVENT    = 'page_view';
    const DEFAULT_PROPERTY = 'page_path';

    /**
     * The stored step a submitted row makes, or null when the row is not one.
     *
     * A row is not a step when it is BLANK -- one somebody added and left
     * alone: no condition has a value and its event is still the default one.
     * "Every page view" is therefore not a step the builder can save as such;
     * page_path begins with / says it. Any other event with no conditions is a
     * step, matching every one.
     *
     * A row of an old kind (a path or a goal event definition) is not made into
     * a step here; problems() refuses it.
     *
     * A condition with no value is a row left alone and is dropped, as a goal
     * event definition's is.
     *
     * @param  array $row     { name, kind, event, custom, match, conditions }
     * @param  int   $number  the step's position, from 1
     * @return array|null
     */
    public static function fromRow( array $row, $number ) {

        if ( self::isOldKind( $row ) || self::isBlank( $row ) ) {

            return null;
        }

        $event      = self::eventOf( $row );
        $conditions = self::conditionsOf( $row );
        $name       = trim( (string) ( $row['name'] ?? '' ) );

        $step = array(
            'name'       => '',
            'kind'       => self::KIND_EVENT,
            'event'      => $event,
            'match'      => self::matchOf( $row ),
            'conditions' => $conditions,
            'step_number' => (int) $number,
        );

        $step['name'] = $name !== '' ? $name : self::defaultName( $step );

        return $step;
    }

    /** The kinds a step had before Update079, which are no longer saved. */
    const OLD_KINDS = array( 'path', 'goal_event' );

    /**
     * A row of a kind no longer saved, with something in it: a path, a goal
     * event definition, or a kind nothing knows.
     */
    public static function isOldKind( array $row ) {

        if ( trim( (string) ( $row['path'] ?? '' ) ) !== ''
             || trim( (string) ( $row['goal_event_definition_id'] ?? '' ) ) !== '' ) {

            return true;
        }

        $kind = (string) ( $row['kind'] ?? '' );

        return ! in_array( $kind, array_merge( array( '', self::KIND_EVENT ), self::OLD_KINDS ), true );
    }

    /** A row nobody filled in: see fromRow(). An empty row of an old kind is blank too. */
    public static function isBlank( array $row ) {

        if ( self::isOldKind( $row ) ) {

            return false;
        }

        if ( in_array( (string) ( $row['kind'] ?? '' ), self::OLD_KINDS, true ) ) {

            return true;
        }

        return ! self::conditionsOf( $row )
            && empty( $row['custom'] )
            && self::eventOf( $row ) === self::DEFAULT_EVENT;
    }

    /** The row's event; a row that names none is on the default one. */
    public static function eventOf( array $row ) {

        return trim( (string) ( is_scalar( $row['event'] ?? null ) ? $row['event'] : self::DEFAULT_EVENT ) );
    }

    /** 'any' or 'all'; anything else reads as all. */
    public static function matchOf( array $step ) {

        return ( $step['match'] ?? '' ) === \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ANY
            ? \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ANY
            : \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ALL;
    }

    /**
     * A step's conditions, each { property, operator, value }.
     *
     * Those with a value, for a submitted row, where an empty one is a row left
     * alone. A stored step is counted with every condition it has
     * ($dropEmpty false): Update079 copies a goal event definition's
     * conditions as they are, and one comparing with '' counted as such.
     *
     * @param  array $step
     * @param  bool  $dropEmpty
     * @return array
     */
    public static function conditionsOf( array $step, $dropEmpty = true ) {

        $out = array();

        foreach ( (array) ( $step['conditions'] ?? array() ) as $condition ) {

            if ( ! is_array( $condition ) ) {

                continue;
            }

            // A stored value is compared as stored; a submitted one as typed, without the spaces round it.
            $value = (string) ( is_scalar( $condition['value'] ?? '' ) ? ( $condition['value'] ?? '' ) : '' );
            $value = $dropEmpty ? trim( $value ) : $value;

            if ( $value === '' && $dropEmpty ) {

                continue;
            }

            $out[] = array(
                'property' => trim( (string) ( is_scalar( $condition['property'] ?? '' ) ? ( $condition['property'] ?? '' ) : '' ) ),
                'operator' => trim( (string) ( is_scalar( $condition['operator'] ?? '' ) ? ( $condition['operator'] ?? '' ) : '' ) ),
                'value'    => $value,
            );
        }

        return $out;
    }

    /**
     * Why a submitted row cannot be saved: field => message, empty when it can.
     *
     * The field names say which row, and which condition of it, a message is
     * about (stepEvent3, stepCondition3_2), so the builder shows it there.
     *
     * @param  array $row            as fromRow() takes it
     * @param  int   $number         the step's position, from 1
     * @param  array $customColumns  the Property's applied custom dimensions,
     *                               column => label (EventPredicate::customColumnsFor())
     * @return array
     */
    public static function problems( array $row, $number, array $customColumns = array() ) {

        if ( self::isOldKind( $row ) ) {

            return array( 'stepEvent' . $number => sprintf(
                \OWA\Core\CoreAPI::t( 'Step %s: a funnel step is an event. Page and goal event definition steps are no longer saved.' ),
                $number ) );
        }

        if ( self::isBlank( $row ) ) {

            return array();
        }

        $out   = array();
        $event = self::eventOf( $row );

        if ( $event === '' ) {

            $out[ 'stepEvent' . $number ] = sprintf( ! empty( $row['custom'] )
                ? \OWA\Core\CoreAPI::t( 'Step %s: enter the name of the custom event.' )
                : \OWA\Core\CoreAPI::t( 'Step %s: choose an event.' ), $number );

            return $out;
        }

        $problem = self::eventProblem( $event, $number );

        if ( $problem !== '' ) {

            $out[ 'stepEvent' . $number ] = $problem;

            return $out;
        }

        $carried   = \OWA\Module\Base\Classes\GoalVocabulary::columnsForEvent( $event );
        $predicate = new EventPredicate( null, $customColumns );
        $E         = \OWA\Module\Base\Entity\GoalEventDefinition::class;

        foreach ( self::conditionsOf( $row ) as $c => $condition ) {

            $field    = 'stepCondition' . $number . '_' . ( $c + 1 );
            $property = $condition['property'];
            $operator = $condition['operator'];
            $value    = $condition['value'];

            if ( ! $predicate->allows( $property ) ) {

                $out[ $field ] = sprintf( \OWA\Core\CoreAPI::t( 'Step %s: %s is not a property a funnel step can test.' ),
                    $number, $property );

                continue;
            }

            // A custom dimension is on every event's row; a column only on the events whose property it holds.
            if ( ! isset( $customColumns[ $property ] ) && ! isset( $carried[ $property ] ) ) {

                $out[ $field ] = sprintf( \OWA\Core\CoreAPI::t( 'Step %s: a %s event does not carry %s.' ), $number, $event, $property );

                continue;
            }

            if ( ! array_key_exists( $operator, \OWA\Module\Base\Entity\GoalEventDefinition::operators() ) ) {

                $out[ $field ] = sprintf( \OWA\Core\CoreAPI::t( 'Step %s: %s is not a comparison a condition can make.' ),
                    $number, $operator );

                continue;
            }

            /*
             * A regex that does not compile matches nothing, for ever, silently
             * -- the comparison suppresses the warning, which is right there and
             * is why it is caught here.
             */
            if ( $operator === $E::MATCH_REGEX
                 && @preg_match( \OWA\Module\Base\Entity\GoalEventDefinition::regexPattern( $value ), '' ) === false ) {

                $out[ $field ] = sprintf( \OWA\Core\CoreAPI::t( 'Step %s: the regular expression is not valid.' ), $number );

                continue;
            }

            // It runs in the database, so it passes what a report filter's pattern must.
            $refused = $operator === $E::MATCH_REGEX && $value !== '' ? ResultSetManager::regexProblem( $value ) : null;

            if ( $refused !== null ) {

                $out[ $field ] = sprintf( \OWA\Core\CoreAPI::t( 'Step %s: the regular expression was refused: %s' ),
                    $number, $refused );

                continue;
            }

            if ( $property === 'page_path'
                 && in_array( $operator, array( $E::MATCH_EXACT, $E::MATCH_NOT, $E::MATCH_BEGINS ), true ) ) {

                /*
                 * A path, not a URL. page_path never holds a scheme or a host,
                 * so a full web address matches nothing and the stage reports
                 * zero. Refused rather than silently trimmed.
                 */
                if ( preg_match( '~^[a-z][a-z0-9+.\-]*://~i', $value ) ) {

                    $out[ $field ] = sprintf(
                        \OWA\Core\CoreAPI::t( 'Step %s: enter a path such as /basket, not a full URL.' ), $number );

                    continue;
                }

                /*
                 * Nor a query string: page_path is cut at the ?, and the rest is
                 * page_query. The path steps this replaces split a typed ? into
                 * the two; a condition says which it means instead.
                 */
                if ( $operator !== $E::MATCH_BEGINS && strpos( $value, '?' ) !== false ) {

                    $out[ $field ] = sprintf( \OWA\Core\CoreAPI::t( 'Step %s: a page path has no query string. '
                        . 'Test the part after the ? as the page query.' ), $number );
                }
            }
        }

        return $out;
    }

    /**
     * Why an event cannot be a step's, or ''.
     *
     * The events a goal event definition may be triggered by, and a site's own
     * events: the same list the event picker offers, so the save refuses only
     * what the builder would not have offered.
     */
    private static function eventProblem( $event, $number ) {

        $problem = \OWA\Module\Base\Entity\GoalEventDefinition::triggerProblem( $event );

        if ( $problem === '' ) {

            return '';
        }

        if ( in_array( $event, \OWA\Core\CoreAPI::trackingEventTypes(), true )
             || in_array( $event, \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), true ) ) {

            return sprintf( \OWA\Core\CoreAPI::t( 'Step %s: a funnel step cannot match %s events.' ), $number, $event );
        }

        return sprintf( \OWA\Core\CoreAPI::t( 'Step %s: %s' ), $number, $problem );
    }

    /**
     * The page a step matches, when that is all it matches: a page_view whose
     * only conditions are page_path exact, and page_query exact beside it.
     *
     * @return string|null  the path, with ?query when there is one
     */
    public static function pageOf( array $step ) {

        if ( (string) ( $step['event'] ?? '' ) !== self::DEFAULT_EVENT ) {

            return null;
        }

        $path  = null;
        $query = null;

        foreach ( self::conditionsOf( $step ) as $condition ) {

            if ( $condition['operator'] !== \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_EXACT ) {

                return null;
            }

            if ( $condition['property'] === 'page_path' && $path === null ) {

                $path = $condition['value'];

            } elseif ( $condition['property'] === 'page_query' && $query === null ) {

                $query = $condition['value'];

            } else {

                return null;
            }
        }

        if ( $path === null || ( $query !== null && self::matchOf( $step ) !== \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ALL ) ) {

            return null;
        }

        return $query === null ? $path : $path . '?' . $query;
    }

    /** A step with no name is named for its page, or for its event. */
    public static function defaultName( array $step ) {

        $page = self::pageOf( $step );

        return $page !== null ? $page : (string) ( $step['event'] ?? '' );
    }

    /**
     * What a step matches, in words, for the funnel's chart and table.
     *
     * A page step reads as its page, as a path step did. Anything else reads
     * as the event, then each condition as label, comparison and value.
     *
     * @param  array $step           as stored
     * @param  array $customColumns  column => label, for a custom dimension's label
     * @return string
     */
    public static function summary( array $step, array $customColumns = array() ) {

        $page = self::pageOf( $step );

        if ( $page !== null ) {

            return $page;
        }

        $event      = (string) ( $step['event'] ?? '' );
        $conditions = self::conditionsOf( $step );

        if ( ! $conditions ) {

            return $event;
        }

        $operators = \OWA\Module\Base\Entity\GoalEventDefinition::operators();
        $parts     = array();

        foreach ( $conditions as $condition ) {

            $label = $customColumns[ $condition['property'] ]
                ?? \OWA\Module\Base\Classes\GoalVocabulary::label( $condition['property'] );

            $parts[] = sprintf( '%s %s %s', $label,
                strtolower( (string) ( $operators[ $condition['operator'] ] ?? $condition['operator'] ) ),
                $condition['value'] );
        }

        $join = self::matchOf( $step ) === \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ANY
            ? \OWA\Core\CoreAPI::t( 'or' ) : \OWA\Core\CoreAPI::t( 'and' );

        return sprintf( \OWA\Core\CoreAPI::t( '%s where %s' ), $event, implode( ' ' . $join . ' ', $parts ) );
    }
}
