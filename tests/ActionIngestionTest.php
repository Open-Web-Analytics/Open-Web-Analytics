<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Beacon-contract test for the track.action ingestion pipeline (Layer 1).
 *
 * Fires a synthetic track.action event through the real pipeline
 * (owa_coreAPI::logEvent -> processRequest controller -> eventDispatch ->
 * owa_actionHandler -> owa_action_fact::create) exactly as log.php does, then
 * asserts the resulting owa_action_fact row. This locks the end-to-end
 * "event in -> correct fact row out" contract so a future refactor of the
 * dispatch/handler/entity layer can't silently break action logging.
 *
 * Writes to the configured OWA database. Each test uses a unique GUID and
 * removes its own row in tearDown, so residue is bounded to a single row.
 */
final class ActionIngestionTest extends TestCase
{
    /** @var string GUID (primary key) of the row created by the current test. */
    private $guid = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping ingestion test.');
        }
    }

    protected function tearDown(): void
    {
        // Remove this test's row regardless of assertion outcome.
        if ($this->guid !== '') {
            try {
                $e = owa_coreAPI::entityFactory('base.action_fact');
                $e->delete($this->guid, 'id');
            } catch (\Throwable $ex) {
                // best-effort cleanup
            }
            $this->guid = '';
        }
    }

    public function testTrackActionPersistsFactRow(): void
    {
        // Unique per run so the idempotency guard (load-by-guid) never
        // short-circuits and cleanup targets exactly this row.
        $this->guid = 'owatest_' . uniqid('', true);
        $site_id = md5('owa-test-site');

        $props = [
            'guid'         => $this->guid,
            'site_id'      => $site_id,
            'event_type'   => 'track.action',
            'action_group' => 'Test Group',
            'action_name'  => 'Test Action',
            'action_label' => 'This Is Just A Test',
            'numeric_value' => 10,
            'page_url'     => 'https://example.com/ingestion-test',
            // Anonymous, non-robotic request so logEvent does not drop it.
            'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'],
        ];

        $event = owa_coreAPI::supportClassFactory('base', 'event');
        $event->setEventType('track.action');
        $event->setProperties($props);

        $result = owa_coreAPI::logEvent('track.action', $event);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the event was dropped before persistence.'
        );

        // Load the row back through the entity layer.
        $row = owa_coreAPI::entityFactory('base.action_fact');
        $row->load($this->guid, 'id');

        $this->assertTrue(
            $row->wasPersisted(),
            'No owa_action_fact row was written for the fired event.'
        );

        $this->assertSame($site_id, $row->get('site_id'));
        // The handler lowercases + trims these three fields on write.
        $this->assertSame('test group', $row->get('action_group'));
        $this->assertSame('test action', $row->get('action_name'));
        $this->assertSame('this is just a test', $row->get('action_label'));
        // numeric_value is coerced to int (* 1) by the handler.
        $this->assertEquals(10, $row->get('numeric_value'));
    }
}
