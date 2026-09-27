<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * THE FIRST-CLASS EVENTS. The vocabulary, enumerated.
 *
 * An event on this list has a property registry behind it, a reserved name the
 * tracker refuses for a custom event, and an `event_type` value that means the
 * same thing on every install. Anything not on it is a site's own custom event:
 * admitted if it matches CUSTOM_NAME_PATTERN, stored under the name the site
 * chose, and grouped by the eventName dimension like any other.
 *
 * DECLARED, BECAUSE IT CANNOT BE DERIVED. It used to be: eventNames() took the
 * union of every non-`*` entry in the property registry's `events` lists, on the
 * reasoning that a first-class event is one the registry describes. That holds
 * only while every event happens to carry a property no other event does, and it
 * stopped holding the moment engagement_msec was widened to `*` -- which was
 * correct, the tracker sends it on every event -- and `user_engagement`, whose
 * only distinguishing property it was, silently left the vocabulary. The symptom
 * appeared two steps away, as the tracker reserving a name the server no longer
 * called first-class.
 *
 * `scroll` and `view_search_results` were one property away from the same thing.
 *
 * So the derivation is still computed, but as a CHECK rather than the source:
 * TrackingEventHelpers::eventNamesFromProperties() answers what the registry
 * implies, and a test asserts nothing is implied that is not declared here. An
 * event's existence no longer depends on the scope of one property.
 *
 * ORDER IS IRRELEVANT -- eventNames() sorts.
 *
 * TWO OF THESE ARE MATERIALIZED, and never arrive on a beacon. session_start
 * and first_visit are built at ingest, by callbacks on the
 * tracking_events_pre_save filter (Classes\MaterializedEvents), from the
 * is_new_session_start and is_new_visitor_created flags on the event that
 * carried them. They are here because they ARE event_type values in stored rows,
 * and because the tracker must refuse them as custom names.
 *
 * `materialized` IS READ BY THREE THINGS:
 *
 *   - TrackingEventHelpers::propertiesForEvent() leaves out a property declared
 *     `"materialize": false` for a materialized name. A materialized event's
 *     values are copied from the event that carried the flag, and a property
 *     describing THAT event -- engagement time accrued, whether it met a goal --
 *     is not a fact about the materialized one.
 *   - Ingest refuses a beacon that names one: the server is their only source.
 *   - The tracker's reserved-name list, which a test compares against it.
 *
 * WHEN AN ENGAGED-SESSION METRIC IS BUILT, a materialized event must not count
 * as the session's goal event: a session that only started, or only belonged to
 * a new visitor, has not engaged on that basis.
 */
return array(

    // The page, and the two events a landing page view materializes beside it.
    'page_view'     => array(),
    'session_start' => array( 'materialized' => true ),
    'first_visit'   => array( 'materialized' => true ),

    // Interaction.
    'click'           => array(),
    'scroll'          => array(),
    'user_engagement' => array(),

    // What a click MEANT, and the rest of the automatically raised set.
    'file_download'       => array(),
    'form_start'          => array(),
    'form_submit'         => array(),
    'view_search_results' => array(),

    // Commerce.
    'purchase' => array(),
    'refund'   => array(),
);

?>
