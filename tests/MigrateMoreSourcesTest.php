<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Migration\ActionMigrator;
use OWA\Module\Base\Classes\Migration\ClickMigrator;
use OWA\Module\Base\Classes\Migration\GoalMigrator;
use OWA\Module\Base\Classes\Migration\PurchaseMigrator;
use OWA\Module\Base\Classes\Migration\RequestMigrator;
use OWA\Module\Base\Classes\Migration\VisitorMigrator;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/V1Schema.php';

/**
 * v1's clicks, actions and purchases, the visitor store and recorded goal
 * completions, migrated -- against the frozen 1.14 schema under its own
 * prefix. Page views are MigrateRequestsTest's.
 */
final class MigrateMoreSourcesTest extends TestCase
{
    private const SITE = 'mig-more-site';
    private const T = 1790000000;
    private const DAY = 20260921;
    private const VISITOR = '1790000000000000411';
    private const SESSION = '1790000000000000421';
    private const PROPERTY = 92000004;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        V1Schema::load();
        $this->clean();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('INSERT INTO owa_property (id, name, domain) VALUES (?, ?, ?)',
            [self::PROPERTY, 'Shop property', 'shop.example']);
        $db->query('INSERT INTO owa_site (id, site_id, domain, name, property_id) VALUES (?, ?, ?, ?, ?)',
            [\OWA\Core\Lib::setStringGuid(self::SITE), self::SITE, 'shop.example', 'Shop', self::PROPERTY]);

        $this->insert('document', ['id' => '101', 'url' => 'https://shop.example/checkout', 'page_title' => 'Checkout']);
        $this->insert('document', ['id' => '102', 'url' => 'https://shop.example/thanks', 'page_title' => 'Thanks']);
        $this->insert('referer', ['id' => '201', 'url' => 'https://search.example/?q=shop']);
        $this->insert('visitor', ['id' => self::VISITOR, 'first_session_id' => self::SESSION,
            'first_session_timestamp' => self::T]);
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->clean();
            V1Schema::drop();
        }
    }

    private function clean(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query('DELETE FROM owa_event_raw WHERE site_id = ?', [self::SITE]);
        foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
            $db->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
        }
        $db->query('DELETE FROM owa_visitor_acquisition WHERE site_id = ?', [self::SITE]);
        $db->query('DELETE FROM owa_setting WHERE scope_id IN (?, ?)', [self::SITE, (string) self::PROPERTY]);
        $db->query('DELETE FROM owa_site WHERE site_id = ?', [self::SITE]);
        $db->query('DELETE FROM owa_property WHERE id = ?', [self::PROPERTY]);
        \OWA\Core\CoreAPI::settingCacheFlush();

        foreach ($this->goalIds() as $id) {
            $db->query('DELETE FROM owa_goal_event_condition WHERE goal_event_id = ?', [$id]);
            $db->query('DELETE FROM owa_goal_event WHERE id = ?', [$id]);
        }
    }

    /**
     * Slots 1 and 2 as Update025 made them on an installation still deriving
     * 32-bit ids: the migrator must find them by property and slot, not by
     * deriving an id.
     */
    private function goalIds(): array
    {
        $property = \OWA\Module\Base\Classes\Migration\GoalMigrator::propertyFor(self::SITE);

        return array_map(fn ($n) => (string) crc32('goal_event:' . $property . ':' . $n), [1, 2]);
    }

    private function insert(string $table, array $row): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('INSERT INTO %s%s (%s) VALUES (%s)',
            V1Schema::PREFIX, $table, implode(',', array_map(fn ($c) => "`$c`", array_keys($row))),
            implode(',', array_fill(0, count($row), '?'))), array_values($row));
    }

    private function fact(string $table, string $id, array $row): void
    {
        $this->insert($table, $row + ['id' => $id, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'session_id' => self::SESSION, 'timestamp' => self::T, 'yyyymmdd' => self::DAY, 'document_id' => '101']);
    }

    private function rows(string $type): array
    {
        return array_map(fn ($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            'SELECT * FROM owa_event_raw WHERE site_id = ? AND event_type = ? ORDER BY ts', [self::SITE, $type]));
    }

    public function testAClickCarriesWhereItLandedAndWhetherItLeft(): void
    {
        $this->fact('click', '1790000000000000501', ['click_x' => 12, 'click_y' => 34, 'page_width' => 1280,
            'page_height' => 800, 'dom_element_id' => 'buy', 'dom_element_tag' => 'a',
            'target_url' => 'https://other.example/offer']);
        $this->fact('click', '1790000000000000502', ['target_url' => 'https://shop.example/cart']);

        (new ClickMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $clicks = $this->rows('click');
        $this->assertCount(2, $clicks);

        $out = array_values(array_filter($clicks, fn ($r) => $r['element_id'] === 'buy'))[0];
        $this->assertSame(12, (int) $out['click_x']);
        $this->assertSame(1280, (int) $out['page_width']);
        $this->assertSame('a', $out['element_tag']);
        $this->assertSame('other.example', $out['target_host']);
        $this->assertSame(1, (int) $out['is_outbound']);
        $this->assertSame('/checkout', $out['page_path']);

        $in = array_values(array_filter($clicks, fn ($r) => $r['element_id'] !== 'buy'))[0];
        $this->assertSame(0, (int) $in['is_outbound'], 'a click to the same host stays');
    }

    public function testAnActionIsACustomEventNamedByTheAction(): void
    {
        $this->fact('action_fact', '1790000000000000601', ['action_name' => 'Signup Form Submit',
            'action_group' => 'Signup', 'action_label' => 'form-a', 'numeric_value' => 5]);
        $this->fact('action_fact', '1790000000000000602', ['action_name' => 'submit']);
        $this->fact('action_fact', '1790000000000000603', ['action_name' => '  ']);

        $progress = (new ActionMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $renamed = $this->rows('signup_form_submit');
        $this->assertCount(1, $renamed);
        $this->assertSame(['group' => 'Signup', 'label' => 'form-a', 'value' => 5, 'action_name' => 'Signup Form Submit'],
            json_decode($renamed[0]['params'], true));

        $plain = $this->rows('submit');
        $this->assertCount(1, $plain);
        $this->assertArrayNotHasKey('action_name', (array) json_decode((string) $plain[0]['params'], true),
            'the original is kept only where the name changed');

        $this->assertEquals(['no_name' => 1], $progress['refusals']);
    }

    public function testActionNamesTakeTheTrackersShape(): void
    {
        $this->assertSame('signup_form_submit', ActionMigrator::eventName('Signup Form Submit'));
        $this->assertSame('action_404', ActionMigrator::eventName('404'));
        $this->assertSame('', ActionMigrator::eventName('!!!'));
        $this->assertSame(40, strlen(ActionMigrator::eventName(str_repeat('a', 60))));
    }

    /** An action named like a built-in event is prefixed, not turned into one. */
    public function testAnActionNamedLikeABuiltInEventIsPrefixed(): void
    {
        $this->assertSame('action_purchase', ActionMigrator::eventName('Purchase'));
        $this->assertSame('action_page_view', ActionMigrator::eventName('Page View'));
        $this->assertSame('action_click', ActionMigrator::eventName('click'));
        $this->assertSame('download', ActionMigrator::eventName('Download'), 'not a built-in name');
    }

    /** So is one a module routes to its own processor. */
    public function testAnActionNamedLikeAModulesEventIsPrefixed(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $maps    = $service->maps;

        try {
            $this->assertSame('recording_probe', ActionMigrator::eventName('Recording Probe'));

            $processors = (array) $service->getMap('event_processors');
            $processors[\OWA\Core\CoreAPI::trackingDispatchName('recording_probe')] = 'probe.processEvent';
            $service->setMap('event_processors', $processors);

            $this->assertSame('action_recording_probe', ActionMigrator::eventName('Recording Probe'));
        } finally {
            $service->maps = $maps;
        }
    }

    /** The row carries the original name when the prefix changed it. */
    public function testAPrefixedActionKeepsItsOriginalName(): void
    {
        $this->fact('action_fact', '1790000000000000605', ['action_name' => 'Purchase']);

        (new ActionMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $this->assertSame([], $this->rows('purchase'), 'not stored as a purchase');

        $row = $this->rows('action_purchase')[0];
        $this->assertSame('Purchase', json_decode($row['params'], true)['action_name']);
    }

    /** v1 stored the amount times 100 whatever the currency; v2 stores minor units. */
    public function testAPurchaseIsInMinorUnitsOfTheProfilesCurrency(): void
    {
        $this->fact('commerce_transaction_fact', '1790000000000000701', ['order_id' => 'A-1', 'total_revenue' => 1250,
            'tax_revenue' => 100, 'shipping_revenue' => 50, 'gateway' => 'stripe']);

        (new PurchaseMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $usd = $this->rows('purchase')[0];
        $this->assertSame('A-1', $usd['transaction_id']);
        $this->assertSame(1250, (int) $usd['revenue'], '12.50 USD is 1250 cents');
        $this->assertSame(100, (int) $usd['tax']);
        $this->assertSame('USD', $usd['currency']);
        $this->assertSame('stripe', json_decode($usd['params'], true)['gateway']);

        // A yen Profile: v1's 120000 was 1200 yen, and yen has no minor unit.
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_event_raw WHERE site_id = ?', [self::SITE]);
        foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
            \OWA\Core\CoreAPI::dbSingleton()->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
        }
        // Per Property, not per Profile: a Property's revenue sums in one cube.
        $this->assertNotFalse(\OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', 'currencyISO3', 'JPY'));
        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE owa_v1fx_commerce_transaction_fact SET total_revenue = 120000');

        (new PurchaseMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $jpy = $this->rows('purchase')[0];
        $this->assertSame('JPY', $jpy['currency']);
        $this->assertSame(1200, (int) $jpy['revenue']);
    }

    private function lineItem(string $id, array $row): void
    {
        $this->fact('commerce_line_item_fact', $id, $row);
    }

    /**
     * v1's line items become params.items on their purchase, in the shape the
     * tracker sends: price in major units, whole amounts as integers, empty
     * fields absent, in the order v1 recorded them. They are migrated because
     * v1-drop would otherwise take the only record of what was sold.
     */
    public function testAPurchaseCarriesItsLineItemsInTheTrackersShape(): void
    {
        $this->fact('commerce_transaction_fact', '1790000000000000711', ['order_id' => 'B-1', 'total_revenue' => 4498]);
        $this->lineItem('1790000000000000721', ['order_id' => 'B-1', 'sku' => 'MUG-1',
            'product_name' => 'Bob\'s "Big" Mug & Co', 'category' => 'Kitchen', 'unit_price' => 1999, 'quantity' => 2]);
        $this->lineItem('1790000000000000722', ['order_id' => 'B-1', 'sku' => 'CARD-1',
            'product_name' => 'Card', 'category' => '', 'unit_price' => 500, 'quantity' => 1]);
        // Another order's item, and one naming neither a SKU nor a product.
        $this->lineItem('1790000000000000723', ['order_id' => 'B-2', 'sku' => 'OTHER', 'unit_price' => 100, 'quantity' => 1]);
        $this->lineItem('1790000000000000724', ['order_id' => 'B-1', 'sku' => '', 'product_name' => '',
            'unit_price' => 100, 'quantity' => 1]);

        (new PurchaseMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $params = json_decode($this->rows('purchase')[0]['params'], true);

        // Keys sorted: the JSON column stores an object's keys in its own order.
        $sorted = fn (array $items) => array_map(function ($item) { ksort($item); return $item; }, $items);

        $this->assertSame($sorted([
            ['item_id' => 'MUG-1', 'item_name' => 'Bob\'s "Big" Mug & Co', 'item_category' => 'Kitchen',
             'price' => 19.99, 'quantity' => 2],
            ['item_id' => 'CARD-1', 'item_name' => 'Card', 'price' => 5, 'quantity' => 1],
        ]), $sorted($params['items'] ?? []));
    }

    /** The same order id on another site is another order. */
    public function testLineItemsAreMatchedWithinTheSite(): void
    {
        $this->fact('commerce_transaction_fact', '1790000000000000731', ['order_id' => 'C-1', 'total_revenue' => 100]);
        $this->insert('commerce_line_item_fact', ['id' => '1790000000000000741', 'site_id' => 'another-site',
            'order_id' => 'C-1', 'sku' => 'NOT-OURS', 'unit_price' => 100, 'quantity' => 1,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY]);

        (new PurchaseMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $this->assertArrayNotHasKey('items', (array) json_decode((string) $this->rows('purchase')[0]['params'], true));
    }

    /**
     * Migrated items read the way live ones do: a refund with no value is
     * priced from its items, in major units, and a migrated purchase's items
     * give the amount v1 recorded.
     */
    public function testMigratedItemsPriceTheWayIngestReadsThem(): void
    {
        $this->fact('commerce_transaction_fact', '1790000000000000751', ['order_id' => 'D-1', 'total_revenue' => 4498]);
        $this->lineItem('1790000000000000761', ['order_id' => 'D-1', 'sku' => 'MUG-1', 'unit_price' => 1999, 'quantity' => 2]);
        $this->lineItem('1790000000000000762', ['order_id' => 'D-1', 'sku' => 'CARD-1', 'unit_price' => 500, 'quantity' => 1]);

        (new PurchaseMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $items = json_decode($this->rows('purchase')[0]['params'], true)['items'];

        $refund = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $refund->setEventType('refund');
        $refund->set('ct_line_items', json_encode($items));
        $refund->set('currency', 'USD');

        $this->assertSame(4498, \OWA\Module\Base\Classes\TrackingEventHelpers::refundAmount($refund));
    }

    /** The reconciliation counts line items, so a purchase that lost them does not pass. */
    public function testTheReconciliationCountsLineItems(): void
    {
        $this->fact('commerce_transaction_fact', '1790000000000000771', ['order_id' => 'E-1', 'total_revenue' => 300]);
        $this->lineItem('1790000000000000781', ['order_id' => 'E-1', 'sku' => 'A', 'unit_price' => 100, 'quantity' => 1]);
        $this->lineItem('1790000000000000782', ['order_id' => 'E-1', 'sku' => 'B', 'unit_price' => 200, 'quantity' => 1]);

        $migrator = new PurchaseMigrator(V1Schema::PREFIX);
        $migrator->migrateSite(self::SITE);

        $this->assertSame([], PurchaseMigrator::discrepancies($migrator->reconcileSite(self::SITE)));

        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_event_raw SET params = JSON_REMOVE(params, '$.items[1]') WHERE site_id = ? AND event_type = 'purchase'",
            [self::SITE]);

        $wrong = PurchaseMigrator::discrepancies($migrator->reconcileSite(self::SITE));

        $this->assertCount(1, $wrong);
        $this->assertStringContainsString('purchase line items: 2 expected, 1 in v2', $wrong[0]);
    }

    public function testTheVisitorStoreTakesTheFirstSessionsEvidence(): void
    {
        $this->insert('session', ['id' => self::SESSION, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY, 'referer_id' => '201']);

        // A later session of the same visitor does not speak for them.
        $this->insert('session', ['id' => '1790000000000000422', 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T + 86400, 'yyyymmdd' => self::DAY + 1]);

        (new VisitorMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT * FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]);

        $this->assertSame('https://search.example/?q=shop', $row['acq_referer_url']);
        $this->assertSame('search.example', $row['acq_referer_host']);
        $this->assertNull($row['acq_source'], 'an untagged visit carries no tags: the build classifies the referrer');
        $this->assertSame(self::T * 1000000, (int) $row['acq_ts']);
        $this->assertSame(202609, (int) $row['last_seen']);
    }

    public function testATaggedFirstVisitCarriesItsTagsAndAnExistingRowWins(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE owa_v1fx_visitor SET first_session_source = ?, first_session_medium = ?,'
            . ' first_session_campaign = ? WHERE id = ?', ['newsletter', 'email', 'spring', self::VISITOR]);
        $this->insert('session', ['id' => self::SESSION, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY]);

        (new VisitorMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT * FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]);
        $this->assertSame(['newsletter', 'email', 'spring'], [$row['acq_source'], $row['acq_medium'], $row['acq_campaign']]);

        // Insert-if-absent: a second pass, or a row v2 collected, is left alone.
        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE owa_visitor_acquisition SET acq_source = ? WHERE visitor_id = ?',
            ['live', self::VISITOR]);
        foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
            \OWA\Core\CoreAPI::dbSingleton()->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
        }

        (new VisitorMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $this->assertSame('live', ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT acq_source FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]))['acq_source']);
    }

    /**
     * A first session with neither a referrer nor a campaign was a direct
     * visit: the session is the landing, so that is the answer, not a gap. It
     * is written as an acquisition with nothing in it and acq_ts set, which
     * the build reads as direct rather than as the sentinel.
     */
    public function testAFirstSessionWithNoEvidenceIsDirect(): void
    {
        $this->insert('session', ['id' => self::SESSION, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY]);

        $progress = (new VisitorMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT * FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]);

        $this->assertSame((string) (self::T * 1000000), (string) ($row['acq_ts'] ?? ''), 'captured');
        $this->assertNull($row['acq_source']);
        $this->assertNull($row['acq_referer_host']);
        $this->assertEquals([], $progress['refusals'], 'nothing refused');
    }

    /** v1's record decides which sessions converted; the definition finds the row. */
    public function testARecordedCompletionMarksTheRowThatMetTheGoal(): void
    {
        $property = \OWA\Module\Base\Classes\Migration\GoalMigrator::propertyFor(self::SITE);
        [$thanks, $gone] = $this->goalIds();

        $goal = \OWA\Core\CoreAPI::entityFactory('base.goal_event');
        $goal->setProperties(['id' => $thanks, 'property_id' => $property, 'name' => 'Thanks', 'goal_number' => 1,
            'trigger_event_type' => 'page_view', 'condition_match' => 'all', 'is_active' => 1]);
        $goal->create();

        $condition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_condition');
        $condition->setProperties(['id' => (string) ((int) $thanks + 1), 'goal_event_id' => $thanks,
            'condition_property' => 'page_path', 'condition_operator' => 'exact', 'condition_value' => '/thanks']);
        $condition->create();

        // A session v1 says completed goal 1 (and goal 2, which no longer exists).
        $this->insert('session', ['id' => self::SESSION, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY, 'goal_1' => 1, 'goal_2' => 1]);
        $this->fact('request', '1790000000000000801', ['document_id' => '101', 'is_entry_page' => 1]);
        $this->fact('request', '1790000000000000802', ['document_id' => '102', 'timestamp' => self::T + 60]);

        // And one v1 did NOT record converting, which also saw /thanks.
        $this->insert('session', ['id' => '1790000000000000423', 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T + 3600, 'yyyymmdd' => self::DAY]);
        $this->fact('request', '1790000000000000803', ['document_id' => '102', 'session_id' => '1790000000000000423',
            'timestamp' => self::T + 3600]);

        (new RequestMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);
        $progress = (new GoalMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $marked = array_values(array_filter($this->rows('page_view'), fn ($r) => (int) $r['is_goal_event'] === 1));

        $this->assertCount(1, $marked, 'only the session v1 counted, and only its /thanks view');
        $this->assertSame('/thanks', $marked[0]['page_path']);
        $this->assertSame(self::SESSION, (string) $marked[0]['session_id']);
        $this->assertSame(1, $progress['rows_written']);
        $this->assertEquals(['goal_not_located' => 1], $progress['refusals'], 'goal 2 no longer exists');

        // A re-run marks nothing new and does not call the marked row unlocated.
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_migration_progress WHERE site_id = ? AND source = ?',
            [self::SITE, 'session_goals']);
        $again = (new GoalMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $this->assertSame(0, $again['rows_written']);
        $this->assertEquals(['goal_not_located' => 1], $again['refusals']);
    }
}
