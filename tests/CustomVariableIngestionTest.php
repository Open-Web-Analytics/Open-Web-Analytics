<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Ingestion test for CUSTOM VARIABLES.
 *
 * The tracker sends each custom variable as a single 'cv{slot}' beacon param
 * whose value is a joined "name=value" string (see
 * tests/js/BeaconContractCustomVars.test.js and the
 * base.page_request.customvars contract fixture). On the server,
 * owa_trackingEventHelpers::translateCustomVariables() splits each 'cv{slot}'
 * into cv{slot}_name / cv{slot}_value (and deletes the raw cv{slot}), and
 * addCustomVariableProperties() registers those as required, lowercased,
 * '(not set)'-defaulted properties. Unlike source/campaign/ua there is NO
 * dedicated dimension entity: the name/value pairs are stored as columns
 * (cv{n}_name / cv{n}_value, VARCHAR255) directly on the fact tables — every
 * fact entity extends owa_factTable, which defines those columns, so a custom
 * var set on a pageview lands on BOTH the base.request row (requestHandlers) and
 * the base.session row (sessionHandlers::logSession does setProperties() from
 * the same event on a new session). This test fires a new-session pageview
 * carrying custom vars and asserts they land, split and lowercased, on both fact
 * rows — and that unset slots default to '(not set)'.
 *
 * Anti-drift: the cv{n} wire params fed to the handler are checked against the
 * base.page_request.customvars contract entry, so a tracker-side rename of the
 * 'cv{n}' param (or a change to the "name=value" encoding the split relies on)
 * can't pass silently.
 */
final class CustomVariableIngestionTest extends IngestionTestCase
{
    public function testCustomVariablesArePersistedToTheFactRow(): void
    {
        // The fields this beacon feeds the pipeline must be ones the tracker
        // emits for the custom-var shape.
        $this->assertFieldsInContract('base.page_request.customvars', [
            'page_url', 'cv1', 'cv2', 'cv3', 'visitor_id', 'is_new_session', 'is_new_visitor',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();
        $page_url   = 'https://example.com/dim-cvar/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+cvar; run=' . $guid . ')';

        // Unique-per-run values so the round-trip is unambiguously ours. Mixed
        // case proves the server lowercases both name and value.
        $cv1_name  = 'Color';
        $cv1_value = 'Blue' . $guid;
        $cv2_name  = 'Plan';
        $cv2_value = 'Pro' . $guid;

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.session', $session_id, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        $result = $this->fireEvent('base.page_request', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'session_id'      => $session_id,
            'page_url'        => $page_url,
            'HTTP_USER_AGENT' => $user_agent,
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitor_id,
            // Custom vars arrive exactly as the tracker sends them: cv{slot} =
            // "name=value". Slot 3+ intentionally left unset to prove defaults.
            'cv1'             => $cv1_name . '=' . $cv1_value,
            'cv2'             => $cv2_name . '=' . $cv2_value,
        ]);
        $this->assertNotFalse($result, 'custom-var page_request was dropped before persistence.');

        // The server split each cv{slot} into name/value on the event (raw
        // cv{slot} deleted), lowercased both.
        $event = $this->lastEvent();
        $this->assertSame(strtolower($cv1_name),  (string) $event->get('cv1_name'),  'cv1 name did not split/lowercase.');
        $this->assertSame(strtolower($cv1_value), (string) $event->get('cv1_value'), 'cv1 value did not split/lowercase.');
        $this->assertSame(strtolower($cv2_name),  (string) $event->get('cv2_name'),  'cv2 name did not split/lowercase.');
        $this->assertSame(strtolower($cv2_value), (string) $event->get('cv2_value'), 'cv2 value did not split/lowercase.');

        // The pairs must survive onto the fact row's cv{n}_name/value columns.
        $fact = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertSame(strtolower($cv1_name),  (string) $fact->get('cv1_name'),  'cv1_name not persisted on the fact row.');
        $this->assertSame(strtolower($cv1_value), (string) $fact->get('cv1_value'), 'cv1_value not persisted on the fact row.');
        $this->assertSame(strtolower($cv2_name),  (string) $fact->get('cv2_name'),  'cv2_name not persisted on the fact row.');
        $this->assertSame(strtolower($cv2_value), (string) $fact->get('cv2_value'), 'cv2_value not persisted on the fact row.');

        // Slots that were never set default to '(not set)' (required props with
        // a default_value), not null/empty.
        $this->assertSame('(not set)', (string) $fact->get('cv3_name'),  'unset cv3_name should default to (not set).');
        $this->assertSame('(not set)', (string) $fact->get('cv3_value'), 'unset cv3_value should default to (not set).');

        // Custom vars are not unique to the request fact: on a new-session
        // pageview the session handler (owa_sessionHandlers::logSession) does
        // setProperties($event->getProperties()) onto the base.session entity,
        // which extends owa_factTable and so carries the same cv{n}_name/value
        // columns. So the pairs must also land on the session fact row.
        $session = $this->assertRowPersisted('base.session', $session_id, 'id');
        $this->assertSame(strtolower($cv1_name),  (string) $session->get('cv1_name'),  'cv1_name not persisted on the session fact row.');
        $this->assertSame(strtolower($cv1_value), (string) $session->get('cv1_value'), 'cv1_value not persisted on the session fact row.');
        $this->assertSame(strtolower($cv2_name),  (string) $session->get('cv2_name'),  'cv2_name not persisted on the session fact row.');
        $this->assertSame(strtolower($cv2_value), (string) $session->get('cv2_value'), 'cv2_value not persisted on the session fact row.');
        $this->assertSame('(not set)',            (string) $session->get('cv3_name'),  'unset cv3_name should default to (not set) on the session fact row.');
    }

    /**
     * Custom vars are not specific to pageview facts. Every fact handler copies
     * the event's properties onto its entity via setProperties(), and every fact
     * entity extends owa_factTable (which defines the cv{n}_name/value columns),
     * so a custom var set on ANY tracked event rides onto that event's fact row.
     * This asserts it for a track.action event -> base.action_fact, proving the
     * fan-out is a property of the shared fact/handler machinery, not just the
     * request/session pageview path exercised above.
     */
    public function testCustomVariablesArePersistedToTheActionFact(): void
    {
        $this->assertFieldsInContract('track.action', [
            'action_group', 'action_name', 'action_label', 'visitor_id',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();
        $page_url   = 'https://example.com/cvar-action/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+cvar-action; run=' . $guid . ')';

        $cv1_name  = 'Widget';
        $cv1_value = 'Signup' . $guid;

        // action_fact keys on the event guid (actionHandler sets id = guid).
        $this->trackForCleanup('base.action_fact', $guid, 'id');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        $result = $this->fireEvent('track.action', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'session_id'      => $session_id,
            'page_url'        => $page_url,
            'HTTP_USER_AGENT' => $user_agent,
            'visitor_id'      => $visitor_id,
            'action_group'    => 'test group ' . $guid,
            'action_name'     => 'signup',
            'action_label'    => 'newsletter',
            'numeric_value'   => 1,
            'cv1'             => $cv1_name . '=' . $cv1_value,
        ]);
        $this->assertNotFalse($result, 'track.action was dropped before persistence.');

        // The custom var split/lowercased, and landed on the action fact row.
        $action = $this->assertRowPersisted('base.action_fact', $guid, 'id');
        $this->assertSame(strtolower($cv1_name),  (string) $action->get('cv1_name'),  'cv1_name not persisted on the action fact row.');
        $this->assertSame(strtolower($cv1_value), (string) $action->get('cv1_value'), 'cv1_value not persisted on the action fact row.');
        $this->assertSame('(not set)',            (string) $action->get('cv2_name'),  'unset cv2_name should default to (not set) on the action fact row.');
    }
}
