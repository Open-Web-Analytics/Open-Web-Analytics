<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the track.action ingestion pipeline.
 *
 * event_type track.action -> owa_actionHandler -> base.action_fact
 * (table owa_action_fact), loaded back by the guid PK (column id).
 */
final class ActionIngestionTest extends IngestionTestCase
{
    public function testTrackActionPersistsFactRow(): void
    {
        $guid    = $this->uniqueGuid();
        $site_id = md5('owa-test-site');
        $this->trackForCleanup('base.action_fact', $guid, 'id');

        $result = $this->fireEvent('track.action', [
            'guid'          => $guid,
            'site_id'       => $site_id,
            'action_group'  => 'Test Group',
            'action_name'   => 'Test Action',
            'action_label'  => 'This Is Just A Test',
            'numeric_value' => 10,
            'page_url'      => 'https://example.com/ingestion-test',
        ]);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the event was dropped before persistence.'
        );

        $row = $this->assertRowPersisted('base.action_fact', $guid, 'id');

        $this->assertSame($site_id, $row->get('site_id'));
        // The handler lowercases + trims these three fields on write.
        $this->assertSame('test group', $row->get('action_group'));
        $this->assertSame('test action', $row->get('action_name'));
        $this->assertSame('this is just a test', $row->get('action_label'));
        // numeric_value is coerced to int (* 1) by the handler.
        $this->assertEquals(10, $row->get('numeric_value'));
    }
}
