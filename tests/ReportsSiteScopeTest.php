<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * A report of one record reads it from the site the request names.
 *
 * view_reports is checked against the siteId on the request, so that is the
 * site whose records a caller may read. The clickstream, the transaction
 * reports and the recording player took the record's id alone, so a user
 * granted one site could read another's by naming their own site and the other
 * site's session, order or recording.
 *
 * Each case seeds the record on site B and asks for it as a viewer granted
 * site A only, then -- the control -- asks for it on site B as an admin, so a
 * report that returns nothing for any request cannot pass.
 */
final class ReportsSiteScopeTest extends RestControllerTestCase
{
    private const REPORTS = \OWA\Module\Base\Controller\ReportsRest::class;

    private const PLAYER = \OWA\Module\Domstream\Controller\DomstreamsRestController::class;

    /** @var array site A, the viewer's */
    private $siteA;

    /** @var array site B, someone else's */
    private $siteB;

    protected function setUp(): void
    {
        // Skips without a database.
        parent::setUp();

        $this->siteA = $this->makeSite('a');
        $this->siteB = $this->makeSite('b');
    }

    /** A viewer granted site A and nothing else, signed in. */
    private function viewerOfSiteA(): void
    {
        $fixture = $this->makeUser('viewer', 'scope');

        $grant = owa_coreAPI::entityFactory('base.site_user');
        $grant->set('user_id', $fixture['id']);
        $grant->set('site_id', $this->siteA['id']);
        $grant->create();

        $entity = owa_coreAPI::entityFactory('base.user');
        $entity->load($fixture['id'], 'id');

        $cu = owa_coreAPI::getCurrentUser();
        $cu->loadNewUserByObject($entity);
        $cu->setAuthStatus(true);

        $this->assertTrue($cu->isCapable('view_reports', $this->siteA['site_id']), 'precondition: granted site A');
        $this->assertFalse($cu->isCapable('view_reports', $this->siteB['site_id']), 'precondition: not site B');
    }

    private function cleanupSiteUserRows(): void
    {
        $db = owa_coreAPI::dbSingleton();
        $db->deleteFrom('owa_site_user');
        $db->where('site_id', $this->siteA['id']);
        $db->executeQuery();
    }

    protected function tearDown(): void
    {
        if ($this->siteA) {
            $this->cleanupSiteUserRows();
        }

        parent::tearDown();
    }

    /** One page request on $site, in session $session. */
    private function seedRequest(array $site, string $session): void
    {
        $id = (string) random_int(1000000000, 9999999999) . (string) random_int(100000, 999999);

        $r = owa_coreAPI::entityFactory('base.request');
        $r->set('id', $id);
        $r->set('site_id', $site['site_id']);
        $r->set('session_id', $session);
        $r->set('visitor_id', $session);
        $r->set('timestamp', time());
        $r->set('yyyymmdd', (int) date('Ymd'));
        $this->assertTrue((bool) $r->create(), 'seeding owa_request');

        $this->trackForCleanup('base.request', $id, 'id');
    }

    /** An order with one line item on $site. */
    private function seedOrder(array $site, string $order): void
    {
        $t = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $t->set('id', $t->generateId('scope-test-' . $order));
        $t->set('site_id', $site['site_id']);
        $t->set('order_id', $order);
        $t->set('revenue', 4200);
        $t->set('timestamp', time());
        $t->set('yyyymmdd', (int) date('Ymd'));
        $this->assertTrue((bool) $t->create(), 'seeding owa_commerce_transaction_fact');
        $this->trackForCleanup('base.commerce_transaction_fact', $t->get('id'), 'id');

        $li = owa_coreAPI::entityFactory('base.commerce_line_item_fact');
        $li->set('id', $li->generateId('scope-test-li-' . $order));
        $li->set('site_id', $site['site_id']);
        $li->set('order_id', $order);
        $li->set('sku', 'SCOPE-SKU');
        $li->set('timestamp', time());
        $li->set('yyyymmdd', (int) date('Ymd'));
        $this->assertTrue((bool) $li->create(), 'seeding owa_commerce_line_item_fact');
        $this->trackForCleanup('base.commerce_line_item_fact', $li->get('id'), 'id');
    }

    /** A recording on $site. @return string its guid */
    private function seedRecording(array $site): string
    {
        $guid = (string) time() . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $ds = owa_coreAPI::entityFactory('base.domstream');
        $ds->set('id', $guid);
        $ds->set('domstream_guid', $guid);
        $ds->set('site_id', $site['site_id']);
        $ds->set('page_url', 'https://' . $site['site_id'] . '.example.test/recorded');
        $ds->set('document_id', $ds->generateId('recorded'));
        $ds->set('events', json_encode([['event_type' => 'dom.click', 'dom_element_id' => 'scope-probe']]));
        $ds->set('timestamp', time());
        $ds->set('yyyymmdd', (int) date('Ymd'));
        $ds->create();

        $this->trackForCleanup('base.domstream', $guid, 'id');

        return $guid;
    }

    private function report(string $name, array $params): array
    {
        return $this->callEndpoint(self::REPORTS, 'reportsRestController.php', ['report_name' => $name] + $params);
    }

    public function testAClickstreamIsReadFromTheSiteTheRequestNames(): void
    {
        $session = (string) (1700000000000000000 + random_int(1, 999999999));
        $this->seedRequest($this->siteB, $session);

        $this->viewerOfSiteA();
        $theirs = $this->report('clickstream', ['siteId' => $this->siteA['site_id'], 'sessionId' => $session]);

        $this->assertSame(201, $theirs['status']);
        $this->assertSame(0, (int) ($theirs['data']['resultsTotal'] ?? -1), 'site B\'s session read by naming site A');

        $this->authenticateAs('admin');
        $own = $this->report('clickstream', ['siteId' => $this->siteB['site_id'], 'sessionId' => $session]);

        $this->assertSame(1, (int) ($own['data']['resultsTotal'] ?? -1), 'the session on its own site');
    }

    public function testATransactionIsReadFromTheSiteTheRequestNames(): void
    {
        $order = 'scope-order-' . random_int(100000, 999999);
        $this->seedOrder($this->siteB, $order);

        $this->viewerOfSiteA();

        foreach (['transaction', 'transaction_items'] as $name) {
            $theirs = $this->report($name, ['siteId' => $this->siteA['site_id'], 'transactionId' => $order]);

            $this->assertSame(201, $theirs['status'], $name);
            $this->assertNotInBody('SCOPE-SKU', $theirs['raw'], "site B's order read through $name by naming site A");
            $this->assertNotInBody('4200', $theirs['raw'], "site B's revenue read through $name");
        }

        $this->authenticateAs('admin');

        foreach (['transaction', 'transaction_items'] as $name) {
            $own = $this->report($name, ['siteId' => $this->siteB['site_id'], 'transactionId' => $order]);

            $this->assertStringContainsString('SCOPE-SKU', $own['raw'], "$name on its own site");
        }
    }

    public function testARecordingIsPlayedFromTheSiteTheRequestNames(): void
    {
        $guid = $this->seedRecording($this->siteB);
        $file = OWA_MODULES_DIR . 'Domstream/Controller/DomstreamsRestController.php';

        $this->viewerOfSiteA();
        $theirs = $this->callEndpoint(self::PLAYER, $file, ['siteId' => $this->siteA['site_id'], 'domstream_guid' => $guid]);

        $this->assertNotInBody('scope-probe', $theirs['raw'], 'site B\'s recording played by naming site A');

        $this->authenticateAs('admin');
        $own = $this->callEndpoint(self::PLAYER, $file, ['siteId' => $this->siteB['site_id'], 'domstream_guid' => $guid]);

        $this->assertStringContainsString('scope-probe', $own['raw'], 'the recording on its own site');
    }

    /** And naming site B outright is refused, as it always was. */
    public function testTheOtherSiteCannotBeNamed(): void
    {
        $this->viewerOfSiteA();

        $resp = $this->report('transactions', ['siteId' => $this->siteB['site_id']]);

        $this->assertNotSame(201, $resp['status']);
    }
}
