<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's actions (owa_action_fact) into custom events named by the action.
 *
 * v1 had one event type for every action and told them apart by action_name;
 * v2 names the event. The name is put in the tracker's shape -- a letter, then
 * letters, digits and underscores, at most 40 -- prefixed with action_ where it
 * would otherwise be a built-in event's name, and the original kept as the
 * action_name parameter where that changed it. Group, label and value are
 * parameters, as a site sets them now.
 */
class ActionMigrator extends FactMigrator {

    const SOURCE = 'action_fact';

    const NO_NAME = 'no_name';

    protected function refusal( array $r ) {

        return parent::refusal( $r ) ?: ( self::eventName( $r['action_name'] ?? '' ) === '' ? self::NO_NAME : null );
    }

    protected function events( array $r, array $refs ) {

        $original = trim( (string) $r['action_name'] );
        $name     = self::eventName( $original );

        $extra = array(
            'ep_group' => $r['action_group'] ?? null,
            'ep_label' => $r['action_label'] ?? null,
        );

        if ( isset( $r['numeric_value'] ) && is_numeric( $r['numeric_value'] ) ) {

            $extra['epn_value'] = $r['numeric_value'] + 0;
        }

        if ( $name !== $original ) {

            $extra['ep_action_name'] = $original;
        }

        return array( $this->baseEvent( $r, $refs, $name, $extra ) );
    }

    /** "Signup Form Submit" -> signup_form_submit; '' where nothing is left. */
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
     * action: a first-class event, or one a module routes to its own
     * processor. A v1 action called "Purchase" became a purchase row, with
     * none of a purchase's columns, and one called "domstream" was handed to
     * the recording store; both are prefixed with action_ instead.
     */
    public static function isTaken( $name ) {

        if ( in_array( $name, \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), true ) ) {

            return true;
        }

        return (bool) \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'event_processors',
            \OWA\Core\CoreAPI::trackingDispatchName( $name ) );
    }
}

?>
