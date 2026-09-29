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
        $db->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);
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
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);
        // Per Property, not per Profile: a Property's revenue sums in one cube.
        $this->assertNotFalse(\OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', 'currencyISO3', 'JPY'));
        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE owa_v1fx_commerce_transaction_fact SET total_revenue = 120000');

        (new PurchaseMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $jpy = $this->rows('purchase')[0];
        $this->assertSame('JPY', $jpy['currency']);
        $this->assertSame(1200, (int) $jpy['revenue']);
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
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);

        (new VisitorMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $this->assertSame('live', ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT acq_source FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]))['acq_source']);
    }

    public function testAVisitorWithNoEvidenceGetsNoRow(): void
    {
        $this->insert('session', ['id' => self::SESSION, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY]);

        $progress = (new VisitorMigrator(V1Schema::PREFIX))->migrateSite(self::SITE);

        $this->assertFalse((bool) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT visitor_id FROM owa_visitor_acquisition WHERE visitor_id = ?', [self::VISITOR]));
        $this->assertEquals(['no_evidence' => 1], $progress['refusals']);
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
