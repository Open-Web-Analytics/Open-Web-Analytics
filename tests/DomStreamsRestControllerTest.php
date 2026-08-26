<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the domstreams REST endpoint:
 *
 *   GET /owa/api/domstream/v1/domstreams  -> owa_domstreamsRestController (view_reports)
 *
 * The route lives in the domstream module (modules/domstream/module.php:95) and
 * is only registered when that module is active, so these tests skip cleanly
 * when it is not. The controller has two modes:
 *   - list:   grouped-by-guid roster of recorded streams for a site (the default).
 *   - detail: a single stream's merged, time-ordered events, selected by the
 *             domstream_guid param.
 * Both modes REQUIRE siteId (validate() enforces it before action() runs).
 *
 * success() -> 201 (domstream.domstreamsRest); validation failure -> 422.
 */
final class DomStreamsRestControllerTest extends RestControllerTestCase
{
    private const CTRL = 'owa_domstreamsRestController';

    /** Absolute path — this controller lives in the domstream module, not base. */
    private function ctrlFile(): string
    {
        return OWA_MODULES_DIR . 'Domstream/Controller/DomstreamsRestController.php';
    }

    /*
     * No is_active guard.
     *
     * There used to be one, and it meant these five tests never ran on a fresh
     * install -- which is what CI provisions, so they had been reporting green
     * without executing. The reasoning behind it was that the ROUTE is only
     * registered when the module is active. True, and irrelevant here: every
     * test below reaches the controller through callEndpoint(), which loads the
     * class from its own file path. Route registration is never involved.
     *
     * Verified by removing the guard on a scratch install with the module
     * inactive: all five pass.
     */

    /**
     * Persist one domstream row for $site directly through the entity layer
     * (mirrors owa_domstreamHandlers::notify). Returns [guid, page_url, events].
     * The row id/guid is numeric — the columns are BIGINT and a non-numeric guid
     * is silently cast to 0 by MySQL (see IngestionTestCase::uniqueGuid).
     *
     * @return array{guid:string, page_url:string, events:string}
     */
    private function seedDomstream(array $site): array
    {
        $guid     = (string) time() . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $page_url = 'https://owatest-ds-' . $this->tok . '.example.com/page';
        $events   = json_encode([
            ['event_type' => 'dom.scroll', 'x' => 0, 'y' => 100],
            ['event_type' => 'dom.click',  'dom_element_id' => 'buy-now', 'click_x' => 10, 'click_y' => 20],
        ]);

        $ds = owa_coreAPI::entityFactory('base.domstream');
        $ds->set('id', $guid);
        $ds->set('domstream_guid', $guid);
        $ds->set('site_id', $site['site_id']);
        $ds->set('page_url', $page_url);
        $ds->set('document_id', $ds->generateId($page_url));
        $ds->set('events', $events);
        $ds->set('duration', 4200);
        $ds->set('page_width', 1280);
        $ds->set('page_height', 3000);
        $ds->set('timestamp', time());
        // yyyymmdd is NOT NULL and is the partition key. The real ingestion path
        // supplies it (live domstream tables carry no zero values), so a seed
        // that omits it is testing something the product never does -- and it
        // only ever worked because the escaping path wrote '' and a permissive
        // sql_mode coerced that to 0.
        $ds->set('yyyymmdd', (int) date('Ymd'));
        $ds->create();

        $this->trackForCleanup('base.domstream', $guid, 'id');

        return ['guid' => $guid, 'page_url' => $page_url, 'events' => $events];
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function testDomstreamsRejectsUnauthenticated(): void
    {
        $site = $this->makeSite();

        $resp = $this->callEndpoint(
            self::CTRL,
            $this->ctrlFile(),
            ['siteId' => $site['site_id']]
        );

        $this->assertNotAuthenticated($resp, 'GET /domstreams');
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function testDomstreamsRequiresSiteId(): void
    {
        $this->authenticateAs('admin');

        // siteId is a stopOnError required validation.
        $resp = $this->callEndpoint(self::CTRL, $this->ctrlFile(), []);

        $this->assertSame(422, $resp['status'],
            'A domstreams query without siteId should fail validation with 422.');
    }

    // ------------------------------------------------------------------
    // List mode
    // ------------------------------------------------------------------

    public function testDomstreamsListReturnsRecordedStreamForSite(): void
    {
        $site = $this->makeSite();
        $seed = $this->seedDomstream($site);
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            self::CTRL,
            $this->ctrlFile(),
            ['siteId' => $site['site_id']]
        );

        $this->assertSame(201, $resp['status'],
            'A valid domstreams list query should return 201.');
        $this->assertSame('domstream.domstreamsRest', $resp['view']);

        // Asserting the name alone would pass with the view deleted: the
        // controller only records a string. Resolve it the way displayView()
        // does -- owa_<name>View through the class map -- so a move that breaks
        // the mapping fails here rather than at runtime.
        $viewClass = \OWA\Core\Lib::resolveNamespacedClass('owa_domstreamsRestView');

        $this->assertNotNull($viewClass,
            'The view named by the controller does not resolve to a class.');
        $this->assertTrue(class_exists($viewClass),
            "Resolved view class {$viewClass} cannot be loaded.");
        $this->assertIsArray($resp['data'],
            'The list payload should be the serialized result set (an array).');

        // The seeded stream must surface in the roster. The result set shape is an
        // internal detail; assert on the raw JSON body so the test survives a
        // serialization refactor while still proving the row was returned. Match
        // on the slash-free host token, since JSON escapes '/' in the page_url.
        $this->assertStringContainsString($seed['guid'], $resp['raw'],
            'The seeded domstream guid should appear in the list response.');
        $this->assertStringContainsString('owatest-ds-' . $this->tok . '.example.com', $resp['raw'],
            'The seeded page_url should appear in the list response.');
    }

    public function testDomstreamsListIsScopedToTheRequestedSite(): void
    {
        // A stream on site A must not appear when listing site B.
        $siteA = $this->makeSite('a');
        $siteB = $this->makeSite('b');
        $seedA = $this->seedDomstream($siteA);
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            self::CTRL,
            $this->ctrlFile(),
            ['siteId' => $siteB['site_id']]
        );

        $this->assertSame(201, $resp['status']);
        $this->assertStringNotContainsString($seedA['guid'], $resp['raw'],
            'A domstream recorded on another site must not leak into this site\'s list.');
    }

    // ------------------------------------------------------------------
    // Detail mode (domstream_guid)
    // ------------------------------------------------------------------

    public function testDomstreamDetailReturnsMergedEventsForGuid(): void
    {
        $site = $this->makeSite();
        $seed = $this->seedDomstream($site);
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            self::CTRL,
            $this->ctrlFile(),
            ['siteId' => $site['site_id'], 'domstream_guid' => $seed['guid']]
        );

        $this->assertSame(201, $resp['status'],
            'A valid single-domstream query should return 201.');
        $this->assertSame('domstream.domstreamsRest', $resp['view']);

        // Asserting the name alone would pass with the view deleted: the
        // controller only records a string. Resolve it the way displayView()
        // does -- owa_<name>View through the class map -- so a move that breaks
        // the mapping fails here rather than at runtime.
        $viewClass = \OWA\Core\Lib::resolveNamespacedClass('owa_domstreamsRestView');

        $this->assertNotNull($viewClass,
            'The view named by the controller does not resolve to a class.');
        $this->assertTrue(class_exists($viewClass),
            "Resolved view class {$viewClass} cannot be loaded.");

        // getDomstream() decodes + merges the stored events blob into the payload;
        // the captured event types must round-trip to the client.
        $this->assertStringContainsString('dom.scroll', $resp['raw'],
            'The merged detail payload should carry the recorded dom.scroll event.');
        $this->assertStringContainsString('dom.click', $resp['raw'],
            'The merged detail payload should carry the recorded dom.click event.');
    }
}
