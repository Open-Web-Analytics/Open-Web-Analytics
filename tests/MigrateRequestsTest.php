<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Migration\RequestMigrator;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/V1Schema.php';

/**
 * v1 page views (owa_request) migrated into owa_event_raw.
 *
 * Run against the frozen 1.14 schema under its own prefix, filled with
 * hand-built rows: one per rule the migration applies.
 */
final class MigrateRequestsTest extends TestCase
{
    private const SITE = 'mig-site-alice';

    private const T = 1790000000;          // a second in the retained range
    private const DAY = 20260921;

    private const VISITOR = '1790000000000000011';
    private const SESSION = '1790000000000000021';
    private const PRIOR   = '1790000000000000020';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        V1Schema::load();
        $this->clean();

        // Only sites that still exist are migrated.
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_site (id, site_id, domain, name) VALUES (?, ?, ?, ?)',
            [\OWA\Core\Lib::setStringGuid(self::SITE), self::SITE, 'alice.example', 'Alice']);
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
        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();

        $db->query("DELETE FROM $raw WHERE site_id = ?", [self::SITE]);
        $db->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);
        $db->query('DELETE FROM owa_site WHERE site_id = ?', [self::SITE]);
    }

    private function insert(string $table, array $row): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('INSERT INTO %s%s (%s) VALUES (%s)',
            V1Schema::PREFIX, $table,
            implode(',', array_map(fn ($c) => "`$c`", array_keys($row))),
            implode(',', array_fill(0, count($row), '?'))), array_values($row));
    }

    private function request(string $id, array $row): void
    {
        $this->insert('request', $row + [
            'id'            => $id,
            'site_id'       => self::SITE,
            'visitor_id'    => self::VISITOR,
            'session_id'    => self::SESSION,
            'timestamp'     => self::T,
            'yyyymmdd'      => self::DAY,
            'document_id'   => '101',
            'referer_id'    => '201',
            'ua_id'         => '301',
            'os_id'         => '401',
            'location_id'   => '501',
            'ip_address'    => '203.0.113.9',
            'language'      => 'en-US',
            'num_prior_sessions' => 3,
            'is_entry_page' => 0,
            'is_new_visitor' => 0,
        ]);
    }

    /** One visit of three page views, two of them in the same second. */
    private function visit(): void
    {
        $this->insert('document', ['id' => '101', 'url' => 'https://alice.example/pricing/?plan=pro',
            'page_title' => 'Pricing']);
        $this->insert('referer', ['id' => '201', 'url' => 'https://search.example/results?q=owa']);
        $this->insert('ua', ['id' => '301', 'ua' => 'Mozilla/5.0 (X11) Test', 'browser_type' => 'Chrome']);
        $this->insert('os', ['id' => '401', 'name' => 'Linux']);
        $this->insert('location_dim', ['id' => '501', 'country' => 'United States', 'country_code' => 'US',
            'state' => 'CA', 'city' => 'Berkeley']);
        $this->insert('visitor', ['id' => self::VISITOR, 'first_session_timestamp' => self::T - 86400 * 10]);
        $this->insert('session', ['id' => self::SESSION, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T, 'yyyymmdd' => self::DAY, 'prior_session_id' => self::PRIOR]);
        $this->insert('session', ['id' => self::PRIOR, 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'timestamp' => self::T - 86400, 'yyyymmdd' => self::DAY - 1]);

        $this->request('1790000000000000101', ['is_entry_page' => 1, 'is_new_visitor' => 1,
            'user_name' => 'alice', 'cv1_name' => 'plan', 'cv1_value' => 'pro']);
        $this->request('1790000000000000102', []);
        $this->request('1790000000000000103', ['timestamp' => self::T + 30]);
    }

    /** @return array[] this site's raw rows, by event type then time */
    private function rows(): array
    {
        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();

        return array_map(fn ($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            "SELECT * FROM $raw WHERE site_id = ? ORDER BY event_type, ts", [self::SITE]));
    }

    private function migrator(int $batch = 500): RequestMigrator
    {
        return new RequestMigrator(V1Schema::PREFIX, $batch);
    }

    public function testAPageViewCarriesWhatV1Recorded(): void
    {
        $this->visit();

        $this->migrator()->migrateSite(self::SITE);

        $views = array_values(array_filter($this->rows(), fn ($r) => $r['event_type'] === 'page_view'));

        // The entry request, by what only it carries: the order within one
        // second is the derived sub-second offset's.
        $entry = array_values(array_filter($views, fn ($r) => $r['user_id'] === 'alice'))[0];

        $this->assertCount(3, $views);
        $this->assertSame(self::VISITOR, (string) $entry['visitor_id']);
        $this->assertSame(self::SESSION, (string) $entry['session_id']);
        $this->assertSame(self::DAY, (int) $entry['yyyymmdd']);
        $this->assertSame(self::T, intdiv((int) $entry['ts'], 1000000));

        $this->assertSame('https://alice.example/pricing/?plan=pro', $entry['page_location']);
        $this->assertSame('/pricing', $entry['page_path'], 'canonicalised as ingest does');
        $this->assertSame('plan=pro', $entry['page_query']);
        $this->assertSame('alice.example', $entry['host']);
        $this->assertSame('Pricing', $entry['page_title']);
        $this->assertSame('search.example', $entry['referer_host']);
        $this->assertSame('q=owa', $entry['referer_query']);

        $this->assertSame('Mozilla/5.0 (X11) Test', $entry['raw_ua']);
        $this->assertSame('Chrome', $entry['browser_type']);
        $this->assertSame('Linux', $entry['os']);
        $this->assertSame('US', $entry['country_code']);
        $this->assertSame('CA', $entry['region']);
        $this->assertSame('Berkeley', $entry['city']);
        $this->assertSame('203.0.113.9', $entry['ip_address']);
        $this->assertSame('en-US', $entry['language']);
        $this->assertSame('alice', $entry['user_id']);

        $this->assertSame(3, (int) $entry['prior_sessions']);
        $this->assertSame(self::T - 86400 * 10, (int) $entry['visitor_fsts']);
        $this->assertSame(self::T, (int) $entry['session_start_ts']);
        $this->assertSame(self::T - 86400, (int) $entry['prior_session_start_ts']);

        $this->assertSame(['plan' => 'pro'], json_decode((string) $entry['params'], true));
    }

    public function testTheEntryPageRaisesTheSessionAndVisitorMarkers(): void
    {
        $this->visit();

        $this->migrator()->migrateSite(self::SITE);

        $types = array_count_values(array_column($this->rows(), 'event_type'));

        $this->assertSame(['first_visit' => 1, 'page_view' => 3, 'session_start' => 1], $types);
    }

    public function testTwoPageViewsInOneSecondAreTwoRows(): void
    {
        $this->visit();

        $this->migrator()->migrateSite(self::SITE);

        $same = array_filter($this->rows(), fn ($r) =>
            $r['event_type'] === 'page_view' && intdiv((int) $r['ts'], 1000000) === self::T);

        $this->assertCount(2, $same);
        $this->assertCount(2, array_unique(array_column($same, 'id')));
    }

    public function testRowsWithoutIdentityAreRefusedAndCountedByReason(): void
    {
        $this->visit();
        $this->request('1790000000000000104', ['visitor_id' => '0']);
        $this->request('1790000000000000105', ['timestamp' => 0]);
        $this->request('1790000000000000106', ['session_id' => '0']);

        $progress = $this->migrator()->migrateSite(self::SITE);

        $this->assertSame(6, $progress['rows_read']);
        $this->assertSame(3, $progress['rows_refused']);
        $this->assertEquals(['no_visitor' => 1, 'no_timestamp' => 1, 'no_session' => 1], $progress['refusals']);
        $this->assertSame(5, $progress['rows_written'], 'three page views and two markers');
        $this->assertNotEmpty($progress['completed_at']);
    }

    public function testAReplayWritesNothingTwice(): void
    {
        $this->visit();

        $this->migrator()->migrateSite(self::SITE);
        $first = $this->rows();

        // Forget the progress, as an interrupted run that lost it would.
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);

        $progress = $this->migrator()->migrateSite(self::SITE);

        $this->assertSame(0, $progress['rows_written']);
        $this->assertSame(array_column($first, 'id'), array_column($this->rows(), 'id'));
    }

    public function testAnInterruptedRunResumesWhereItStopped(): void
    {
        $this->visit();

        $partial = $this->migrator(1)->migrateSite(self::SITE, 2);

        $this->assertSame(2, $partial['rows_read']);
        $this->assertSame('1790000000000000102', $partial['last_id']);
        $this->assertEmpty($partial['completed_at']);

        $done = $this->migrator(1)->migrateSite(self::SITE);

        $this->assertSame(3, $done['rows_read'], 'the count carries over');
        $this->assertSame(5, $done['rows_written']);
        $this->assertCount(5, $this->rows());
        $this->assertNotEmpty($done['completed_at']);

        $again = $this->migrator(1)->migrateSite(self::SITE);
        $this->assertSame(3, $again['rows_read'], 'a completed site is not read again');
    }

    /**
     * An installation holding the dimension keys as VARCHAR, against BIGINT
     * dimension ids. MySQL compares a string with an integer exactly when it
     * looks the id up through its index, and as a double when it does not --
     * and 2^53 and 2^53+1 are one number as doubles.
     */
    public function testVarcharKeysResolveTheExactDimensionRow(): void
    {
        $this->visit();
        V1Schema::asVarcharKeys();

        $this->insert('ua', ['id' => '9007199254740992', 'ua' => 'Wrong UA', 'browser_type' => 'Wrong']);
        $this->insert('ua', ['id' => '9007199254740993', 'ua' => 'Right UA', 'browser_type' => 'Right']);

        \OWA\Core\CoreAPI::dbSingleton()->query(
            'UPDATE owa_v1fx_request SET ua_id = ? WHERE site_id = ?', ['9007199254740993', self::SITE]);

        // The trap, shown: the same join without the index matches both rows.
        $joined = \OWA\Core\CoreAPI::dbSingleton()->get_results(
            "SELECT ua.ua FROM owa_v1fx_request r JOIN owa_v1fx_ua ua IGNORE INDEX (PRIMARY) ON r.ua_id = ua.id"
            . " WHERE r.id = 1790000000000000101");
        $this->assertCount(2, (array) $joined, 'the fixture no longer shows the double comparison');

        $this->migrator()->migrateSite(self::SITE);

        foreach ($this->rows() as $row) {
            $this->assertSame('Right UA', $row['raw_ua'], $row['event_type']);
        }
    }

    public function testNotSetInV1BecomesNull(): void
    {
        $this->visit();
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_document SET page_title = '(not set)' WHERE id = 101");
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_location_dim SET city = '(not set)' WHERE id = 501");

        $this->migrator()->migrateSite(self::SITE);

        $entry = array_values(array_filter($this->rows(), fn ($r) => $r['user_id'] === 'alice'))[0];

        $this->assertNull($entry['page_title']);
        $this->assertNull($entry['city']);
    }

    public function testAMissingDimensionRowLeavesTheValueEmptyNotTheRow(): void
    {
        $this->visit();
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_v1fx_referer');

        $progress = $this->migrator()->migrateSite(self::SITE);

        $this->assertSame(0, $progress['rows_refused']);
        $this->assertNull($this->rows()[0]['referer_host']);
    }

    private function tagged(): array
    {
        return array_values(array_filter($this->rows(), fn ($r) => $r['event_type'] === 'page_view'
            && $r['user_id'] === 'alice'))[0];
    }

    private function campaignDims(): void
    {
        $this->insert('source_dim', ['id' => '601', 'source_domain' => 'newsletter']);
        $this->insert('campaign_dim', ['id' => '701', 'name' => 'spring-sale']);
        $this->insert('ad_dim', ['id' => '801', 'name' => 'banner-a']);
        $this->insert('search_term_dim', ['id' => '901', 'terms' => 'web analytics']);
    }

    /** For a row that recorded a campaign, v1's verdict was the tags. */
    public function testACampaignTaggedRowCarriesItsTags(): void
    {
        $this->visit();
        $this->campaignDims();
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET medium = 'email', source_id = 601, campaign_id = 701, ad_id = 801,"
            . " referring_search_term_id = 901 WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $row = $this->tagged();

        $this->assertSame('newsletter', $row['tagged_source']);
        $this->assertSame('email', $row['tagged_medium']);
        $this->assertSame('spring-sale', $row['tagged_campaign']);
        $this->assertSame('banner-a', $row['tagged_ad']);
        $this->assertSame('web analytics', $row['tagged_search_terms']);
    }

    /** An organic or referral verdict is not evidence: the cube classifies the referrer. */
    public function testAnUntaggedRowCarriesNoTags(): void
    {
        $this->visit();
        $this->campaignDims();
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET medium = 'organic-search', source_id = 601,"
            . " referring_search_term_id = 901 WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $row = $this->tagged();

        foreach (['tagged_source', 'tagged_medium', 'tagged_campaign', 'tagged_ad', 'tagged_search_terms'] as $column) {
            $this->assertNull($row[$column], $column);
        }

        $this->assertSame('search.example', $row['referer_host'], 'the evidence the cube classifies');
    }

    public function testACampaignOfNotSetIsNoCampaign(): void
    {
        $this->visit();
        $this->insert('campaign_dim', ['id' => '702', 'name' => '(not set)']);
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET medium = 'organic-search', campaign_id = 702 WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $this->assertNull($this->tagged()['tagged_medium']);
    }

    public function testTheSitesAreThoseV1HoldsPageViewsForThatStillExist(): void
    {
        $this->visit();
        $this->request('1790000000000000199', ['site_id' => 'mig-site-gone']);

        $this->assertSame([self::SITE], $this->migrator()->sites());

        $volume = $this->migrator()->volume();
        $gone = array_values(array_filter($volume, fn ($v) => $v['site_id'] === 'mig-site-gone'))[0];
        $kept = array_values(array_filter($volume, fn ($v) => $v['site_id'] === self::SITE))[0];

        $this->assertFalse($gone['known']);
        $this->assertTrue($kept['known']);
        $this->assertSame(3, $kept['rows']);
        $this->assertSame(2026, $kept['year']);
    }

    public function testRowsOlderThanTheCutoffAreNotMigrated(): void
    {
        $this->visit();
        $this->request('1790000000000000150', ['yyyymmdd' => 20240101, 'timestamp' => 1704110400]);

        $progress = (new RequestMigrator(V1Schema::PREFIX, 500, 20250101))->migrateSite(self::SITE);

        $this->assertSame(3, $progress['rows_read'], 'the 2024 row is not read');
        $this->assertSame(20250101, (int) $progress['since']);
        $this->assertSame([], array_filter($this->rows(), fn ($r) => (int) $r['yyyymmdd'] < 20250101));
    }

    public function testALaterRunWithAnotherCutoffIsRefused(): void
    {
        $this->visit();

        (new RequestMigrator(V1Schema::PREFIX, 1, 20250101))->migrateSite(self::SITE, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('would leave a gap');

        (new RequestMigrator(V1Schema::PREFIX, 1, null))->migrateSite(self::SITE);
    }
}
