<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * A forged property must not reach the fact row.
 *
 * The behavioural half of ServerOwnedPropertyTest. That one checks the wire
 * filter's arithmetic; this one fires a real event and reads the row back,
 * which is the only way to cover the defect that actually caused the incident:
 * ProcessEvent re-applied client input OVER the derivation it had just run.
 *
 * Written after a mutation check showed the unit tests alone did not cover it
 * -- restoring the original re-apply left all of them passing.
 *
 * The production symptom was "Incorrect integer value: 'ludhiana' for column
 * 'is_browser'": a request supplied is_browser, the derivation correctly
 * computed false, and the re-apply put the string back.
 */
final class ServerOwnedPropertyIngestionTest extends IngestionTestCase
{
    public function testAForgedComputedPropertyDoesNotReachTheRow(): void
    {
        $guid    = $this->uniqueGuid();
        $site_id = md5('owa-test-site');

        $this->trackForCleanup('base.request', $guid, 'id');

        $result = $this->fireEvent('base.page_request', [
            'guid'       => $guid,
            'site_id'    => $site_id,
            'page_url'   => 'https://example.com/forged-property',
            'page_title' => 'Forged Property Test',
            // exactly what the failing production request carried
            'is_browser' => 'ludhiana',
            'day'        => 'united states',
        ]);

        $this->assertNotFalse($result, 'logEvent returned false — the event was dropped.');

        $row = $this->assertRowPersisted('base.request', $guid, 'id');

        $this->assertNotSame(
            'ludhiana',
            (string) $row->get('is_browser'),
            'A client-supplied value reached is_browser. The derivation runs and is then '
            . 'overwritten, which is what put a city name in a boolean column in production.'
        );

        $this->assertNotSame(
            'united states',
            (string) $row->get('day'),
            'A client-supplied value reached day, which is a derived date part.'
        );

        /*
         * The derivation's own answer survived -- checked as a plausible day of
         * month rather than against date('j').
         *
         * Comparing to PHP's clock made this order-dependent: OWA derives date
         * parts in the CONFIGURED timezone, which another test can change and
         * not restore, so it passed alone and failed in the suite. The exact
         * day is incidental here; that a number replaced the forged string is
         * the point.
         */
        $this->assertIsNumeric( $row->get('day') );

        $this->assertGreaterThanOrEqual( 1, (int) $row->get('day') );
        $this->assertLessThanOrEqual( 31, (int) $row->get('day') );
    }
}
