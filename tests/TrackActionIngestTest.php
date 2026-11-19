<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * A track.action beacon is stored as the custom event a migrated 1.x action
 * is: named by the action, its group, label and value in params.
 * BeaconCompatActionTest covers the reshaping without a database.
 */
final class TrackActionIngestTest extends IngestionTestCase
{
    public function testATrackActionIsStoredAsTheMigratedCustomEvent(): void
    {
        $site    = md5('owa-test-site');
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('track.action', [
            'site_id'       => $site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'page_location' => 'https://owa-test-site/recipes/soup',
            'action_group'  => 'cooking',
            'action_name'   => 'Recipe Print',
            'action_label'  => 'Italian Chicken Soup',
            'numeric_value' => '1',
            'fsts' => time(),
            'sts'  => time(),
            'num_prior_sessions' => 0,
        ]);

        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            'SELECT id, event_type, params FROM owa_event_raw WHERE site_id = ? AND visitor_id = ? AND session_id = ?',
            [$site, $visitor, $session]);

        $byType = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $byType[$row['event_type']] = $row;
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');
        }

        $this->assertArrayNotHasKey('track.action', $byType);
        $this->assertArrayHasKey('recipe_print', $byType, 'stored under the migrated name');

        $params = json_decode((string) $byType['recipe_print']['params'], true);

        $this->assertSame('cooking', $params['group'] ?? null);
        $this->assertSame('Italian Chicken Soup', $params['label'] ?? null);
        $this->assertEquals(1, $params['value'] ?? null);
        $this->assertSame('Recipe Print', $params['action_name'] ?? null);
        $this->assertArrayNotHasKey('action_group', $params);
    }
}
