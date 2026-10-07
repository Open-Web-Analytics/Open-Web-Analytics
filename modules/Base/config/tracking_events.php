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
    'page_view'     => array(
        'description' => 'A page was viewed. Sent by the trackPageView command, and again on each in-page route change when trackRouteChanges is on.',
    ),
    'session_start' => array(
        'materialized' => true,
        'description' => 'A session began. Not sent by the tracker: OWA creates it at ingest beside the event that started the session, usually a page_view.',
    ),
    'first_visit'   => array(
        'materialized' => true,
        'description' => 'A visitor was seen for the first time. Not sent by the tracker: OWA creates it at ingest beside the event that created the visitor.',
    ),

    // Interaction.
    'click'           => array(
        'description' => 'An element on the page was clicked, including a middle-click on a link. Sent when trackClicks is on.',
    ),
    'scroll'          => array(
        'description' => 'How far down the page the visitor scrolled: one event per page view, at the deepest threshold reached, and only after a scroll. Sent at once on reaching the last threshold, otherwise when the page is hidden or left. Sent when trackScroll is on; the thresholds are the scrollThresholds option (25, 50, 75 and 90 percent by default).',
    ),
    'user_engagement' => array(
        'description' => 'Time spent on the page that no other event has reported yet, sent when the page is hidden or left. Sent automatically; less than a second is not sent on its own.',
    ),

    // What a click MEANT, and the rest of the automatically raised set.
    'file_download'       => array(
        'description' => 'A clicked link fetched a file whose extension is in the downloadExtensions option. Sent beside the click when trackClicks is on.',
    ),
    'form_start'          => array(
        'description' => 'The visitor began filling in a form: the first change to one of its fields, or a submit with no change before it. Sent once per form per page when trackForms is on.',
    ),
    'form_submit'         => array(
        'description' => 'A form was submitted. Sent when trackForms is on.',
    ),
    'view_search_results' => array(
        'description' => 'A site-search results page was viewed, recognised by a search term in one of the siteSearchParams query parameters (q, s, search, query and keyword by default). Sent by the trackSiteSearch command, and on each in-page route change when trackRouteChanges is on.',
    ),

    // Commerce.
    'purchase' => array(
        'description' => 'An order was completed. Sent by the trackPurchase command, which requires a transaction id, or by the older addTransaction and trackTransaction pair.',
    ),
    'refund'   => array(
        'description' => 'An order, or some of its items, was refunded. Sent by the trackRefund command, which requires the transaction id of the purchase.',
    ),
);

?>
