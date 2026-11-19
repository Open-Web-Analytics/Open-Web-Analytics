<?php
namespace OWA\Module\Base\Classes\Migration;

/**
 * v1's actions (owa_action_fact) into custom events named by the action.
 *
 * v1 had one event type for every action and told them apart by action_name;
 * v2 names the event. The name is put in the tracker's shape -- a letter, then
 * letters, digits and underscores, at most 40 -- prefixed with action_ where it
 * would otherwise be a built-in event's name, and the original kept as the
 * action_name parameter where that changed it. Group, label and value are
 * the group, label and value parameters.
 *
 * customEvent() is the rule, and the beacon compat layer applies the same one
 * to a live track.action beacon (conf/beacon_compat.php, `event_reshapes`), so
 * a migrated action and one sent today are one event.
 */
class ActionMigrator extends FactMigrator {

    const SOURCE = 'action_fact';

    const NO_NAME = 'no_name';

    /** A 1.x action's properties, as owa_action_fact and a 1.x beacon name them. */
    const V1_PROPERTIES = [ 'action_name', 'action_group', 'action_label', 'numeric_value' ];

    protected function refusal( array $r ) {

        return parent::refusal( $r ) ?: ( self::eventName( $r['action_name'] ?? '' ) === '' ? self::NO_NAME : null );
    }

    protected function events( array $r, array $refs ) {

        $event = self::customEvent( $r );

        if ( $event === null ) {

            return [];
        }

        return [ $this->baseEvent( $r, $refs, $event['event_type'], $event['properties'] ) ];
    }

    /**
     * A 1.x action as the custom event v2 records it: named by eventName(), its
     * group, label and value as eps_group, eps_label and epn_value, and the
     * name as given in eps_action_name when eventName() changed it. A value
     * that is not numeric is left out.
     *
     * @param  array<string, mixed> $action V1_PROPERTIES => value; other keys are ignored
     * @return array{event_type: string, properties: array<string, mixed>}|null null when the name leaves nothing
     */
    public static function customEvent( array $action ) {

        $original = trim( (string) ( $action['action_name'] ?? '' ) );
        $name     = self::eventName( $original );

        if ( $name === '' ) {

            return null;
        }

        $properties = [];

        foreach ( [ 'eps_group' => 'action_group', 'eps_label' => 'action_label' ] as $to => $from ) {

            $value = $action[ $from ] ?? null;

            if ( $value !== null && $value !== false && $value !== '' ) {

                $properties[ $to ] = $value;
            }
        }

        if ( isset( $action['numeric_value'] ) && is_numeric( $action['numeric_value'] ) ) {

            $properties['epn_value'] = $action['numeric_value'] + 0;
        }

        if ( $name !== $original ) {

            $properties['eps_action_name'] = $original;
        }

        return [ 'event_type' => $name, 'properties' => $properties ];
    }

    /**
     * "Signup Form Submit" -> signup_form_submit; '' where nothing is left.
     *
     * @param  string $name
     * @return string
     */
    public static function eventName( $name ) {

        $name = strtolower( trim( (string) $name ) );
        $name = trim( preg_replace( '/[^a-z0-9]+/', '_', $name ), '_' );

        if ( $name === '' ) {

            return '';
        }

        if ( ! preg_match( '/^[a-z]/', $name ) || self::isTaken( $name ) ) {

            $name = 'action_' . $name;
        }

        return substr( $name, 0, 40 );
    }

    /**
     * Whether an event name already means something other than a site's own
     * action: a first-class event, one a module routes to its own processor,
     * or one in the owa_ prefix the tracker reserves. A v1 action called
     * "Purchase" became a purchase row, with none of a purchase's columns, and
     * one sharing a module's event name was handed to that module's store; all
     * are prefixed with action_ instead.
     *
     * @param  string $name
     * @return bool
     */
    public static function isTaken( $name ) {

        if ( strncmp( (string) $name, 'owa_', 4 ) === 0 ) {

            return true;
        }

        if ( in_array( $name, \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), true ) ) {

            return true;
        }

        return (bool) \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'event_processors',
            \OWA\Core\CoreAPI::trackingDispatchName( $name ) );
    }
}
