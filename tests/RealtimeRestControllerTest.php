<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the realtime REST endpoint:
 *
 *   GET /owa/api/base/v1/realtime  -> RealtimeRest (view_reports)
 *
 * success() -> 200 (base.realtimeRest); a missing siteId -> 422 (base.restApi).
 */
final class RealtimeRestControllerTest extends RestControllerTestCase
{
    public function testRealtimeRejectsUnauthenticated(): void
    {
        $resp = $this->callEndpoint(\OWA\Module\Base\Controller\RealtimeRest::class,
            'RealtimeRest.php', ['siteId' => 'any']);

        $this->assertNotAuthenticated($resp, 'GET /realtime');
    }

    public function testASiteIsRequired(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(\OWA\Module\Base\Controller\RealtimeRest::class, 'RealtimeRest.php', []);

        $this->assertSame(422, $resp['status']);
        $this->assertSame('base.restApi', $resp['view']);
    }

    /**
     * Every card, for a Profile whose Property has no cube: realtime reads
     * raw, so a site whose reports are not ready yet is still answered.
     */
    public function testEveryCardIsReturnedWithoutACube(): void
    {
        $this->authenticateAs('admin');

        $site = $this->makeSite('realtime');

        $resp = $this->callEndpoint(\OWA\Module\Base\Controller\RealtimeRest::class,
            'RealtimeRest.php', ['siteId' => $site['site_id']]);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('base.realtimeRest', $resp['view']);

        foreach (['window', 'activeUsers', 'perMinute', 'pages', 'events', 'goals', 'sources',
                  'countries', 'located', 'devices', 'recent', 'queued'] as $card) {
            $this->assertArrayHasKey($card, (array) $resp['data'], $card);
        }

        $this->assertSame(0, $resp['data']['activeUsers']['last30']);
        $this->assertCount(30, $resp['data']['perMinute']);
    }

    public function testOneVisitorsEventsAreReturned(): void
    {
        $this->authenticateAs('admin');

        $site = $this->makeSite('realtime-visitor');

        $resp = $this->callEndpoint(\OWA\Module\Base\Controller\RealtimeRest::class,
            'RealtimeRest.php', ['siteId' => $site['site_id'], 'visitorId' => '8899500000000001']);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('8899500000000001', $resp['data']['visitor']);
        $this->assertSame([], $resp['data']['events']);
    }
}
