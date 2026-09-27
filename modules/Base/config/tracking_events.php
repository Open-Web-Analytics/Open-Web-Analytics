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
 * TWO OF THESE NEVER ARRIVE ON A BEACON. session_start and first_visit are
 * materialised by EventRawHandlers::expand() from the is_new_session_start and
 * is_new_visitor_created flags on a page view, so no tracker sends them and they
 * have no beacon contract. They are here because they ARE event_type values in
 * stored rows, and because the tracker must refuse them as custom names.
 */
return array(

    // The page, and the two rows a landing page view raises beside itself.
    'page_view',
    'session_start',
    'first_visit',

    // Interaction.
    'click',
    'scroll',
    'user_engagement',

    // Enhanced measurement: what a click MEANT, and the rest of the set.
    'file_download',
    'form_start',
    'form_submit',
    'view_search_results',

    // Commerce.
    'purchase',
);

?>
