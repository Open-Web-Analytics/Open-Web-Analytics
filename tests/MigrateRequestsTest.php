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
        foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
            $db->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
        }
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

        $this->assertSame(1, (int) $entry['prior_sessions'], 'counted from v1 sessions (PRIOR), not v1\'s 3');
        $this->assertSame(self::T - 86400 * 10, (int) $entry['visitor_fsts']);
        $this->assertSame(self::T, (int) $entry['session_start_ts']);
        $this->assertSame(self::T - 86400, (int) $entry['prior_session_start_ts']);

        $this->assertSame(['plan' => 'pro'], json_decode((string) $entry['params'], true));
    }

    public function testTheEntryPageRaisesTheSessionMarker(): void
    {
        $this->visit();

        $this->migrator()->migrateSite(self::SITE);

        $types = array_count_values(array_column($this->rows(), 'event_type'));
        ksort($types);

        // v1 flagged the entry is_new_visitor, but the visitor had a session
        // before it (PRIOR), so it is not a first visit.
        $this->assertSame(['page_view' => 3, 'session_start' => 1], $types);
    }

    /**
     * The visitor's first session v1 kept is its first visit, whatever v1
     * flagged: older trackers wrote num_prior_sessions 1 for a new visitor and
     * later ones wrote is_new_visitor 0 for everyone.
     */
    public function testTheVisitorsFirstSessionIsItsFirstVisitWhateverV1Flagged(): void
    {
        $this->visit();
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf('UPDATE %srequest SET is_new_visitor = 0, num_prior_sessions = 1', V1Schema::PREFIX));
        $this->request('1790000000000000301', ['session_id' => self::PRIOR, 'timestamp' => self::T - 86400,
            'yyyymmdd' => self::DAY - 1]);

        $this->migrator()->migrateSite(self::SITE);

        $this->assertSame(['first_visit' => 1, 'page_view' => 1, 'session_start' => 1], $this->typesFor(self::PRIOR));
        $this->assertSame(['page_view' => 3, 'session_start' => 1], $this->typesFor(self::SESSION));

        $prior = [];
        foreach ($this->rows() as $r) {
            if ($r['event_type'] === 'page_view') {
                $prior[(string) $r['session_id']] = (int) $r['prior_sessions'];
            }
        }
        $this->assertSame([self::PRIOR => 0, self::SESSION => 1], $prior + [self::PRIOR => -1]);
    }

    /**
     * A session v1 lost the row for is still an earlier session of the
     * visitor's later ones: only the first of them is a first visit.
     */
    public function testASessionWithoutARowIsAnEarlierSession(): void
    {
        $first  = '1790000000000000097';
        $second = '1790000000000000098';
        $this->visit();
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DELETE FROM %ssession', V1Schema::PREFIX));
        $this->request('1790000000000000211', ['session_id' => $first, 'timestamp' => self::T - 7200]);
        $this->request('1790000000000000212', ['session_id' => $second, 'timestamp' => self::T - 3600]);

        $this->migrator()->migrateSite(self::SITE);

        $this->assertSame(['first_visit' => 1, 'page_view' => 1, 'session_start' => 1], $this->typesFor($first));
        $this->assertSame(['page_view' => 1, 'session_start' => 1], $this->typesFor($second));
        $this->assertSame(['page_view' => 3, 'session_start' => 1], $this->typesFor(self::SESSION));

        $prior = [];
        foreach ($this->rows() as $r) {
            if ($r['event_type'] === 'session_start') {
                $prior[(string) $r['session_id']] = (int) $r['prior_sessions'];
            }
        }
        ksort($prior);
        $this->assertSame([self::SESSION => 2, $first => 0, $second => 1], $prior);
    }

    /** Sessions on another site are not earlier sessions on this one. */
    public function testPriorSessionsAreCountedPerSite(): void
    {
        $this->visit();
        $this->insert('session', ['id' => '1790000000000000030', 'site_id' => 'mig-site-bob',
            'visitor_id' => self::VISITOR, 'timestamp' => self::T - 3600, 'yyyymmdd' => self::DAY]);

        $this->migrator()->migrateSite(self::SITE);

        foreach ($this->rows() as $r) {
            if ($r['event_type'] === 'page_view') {
                $this->assertSame(1, (int) $r['prior_sessions'], 'PRIOR only, not the other site\'s');
            }
        }
    }

    /**
     * A session v1 lost the row for begins at its earliest request, read
     * across batches, and counts the visitor's sessions before that.
     */
    public function testASessionWithoutARowCountsTheSessionsBeforeItsFirstRequest(): void
    {
        $orphan = '1790000000000000099';
        $this->visit();
        $this->request('1790000000000000202', ['session_id' => $orphan, 'timestamp' => self::T + 7200]);
        $this->request('1790000000000000201', ['session_id' => $orphan, 'timestamp' => self::T - 7200]);

        $this->migrator(1)->migrateSite(self::SITE);

        foreach ($this->rows() as $r) {
            if ($r['event_type'] === 'page_view' && (string) $r['session_id'] === $orphan) {
                $this->assertSame(1, (int) $r['prior_sessions'],
                    'PRIOR began before it; SESSION began after its first request, though before its second');
            }
        }
    }

    /** @return array event type => count, for one session */
    private function typesFor(string $session): array
    {
        $types = [];
        foreach ($this->rows() as $r) {
            if ((string) $r['session_id'] === $session) {
                $types[$r['event_type']] = ($types[$r['event_type']] ?? 0) + 1;
            }
        }
        ksort($types);

        return $types;
    }

    /**
     * v1 set is_entry_page on more than one request of a session (1,445
     * sessions on a real 1.x install). The session starts once, at its
     * earliest request.
     */
    public function testASessionV1FlaggedTwiceStartsOnce(): void
    {
        $this->visit();
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %srequest SET is_entry_page = 1, is_new_visitor = 1',
            V1Schema::PREFIX));

        $this->migrator()->migrateSite(self::SITE);

        $this->assertSame(['page_view' => 3, 'session_start' => 1], $this->typesFor(self::SESSION));
    }

    /**
     * v1 flagged no request of a session whose session row it had lost (9,279
     * sessions on a real 1.x install). Its earliest request still starts it,
     * as live ingest would have.
     */
    public function testASessionV1NeverFlaggedStartsAtItsEarliestRequest(): void
    {
        $orphan = '1790000000000000099';
        $this->visit();
        $this->request('1790000000000000203', ['session_id' => $orphan, 'timestamp' => self::T + 60]);
        $this->request('1790000000000000201', ['session_id' => $orphan, 'timestamp' => self::T + 120]);
        $this->request('1790000000000000202', ['session_id' => $orphan, 'timestamp' => self::T + 60]);

        $this->migrator(2)->migrateSite(self::SITE);

        $this->assertSame(['page_view' => 3, 'session_start' => 1], $this->typesFor($orphan),
            'one start, across batches of two');

        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();
        $start = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            "SELECT ts FROM $raw WHERE session_id = ? AND event_type = 'session_start'", [$orphan]);
        $this->assertSame(self::T + 60, intdiv((int) $start['ts'], 1000000),
            'at the earliest time, and of those the lowest id (202)');
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
        $this->assertSame(4, $progress['rows_written'], 'three page views and the session start');
        $this->assertNotEmpty($progress['completed_at']);
    }

    public function testAReplayWritesNothingTwice(): void
    {
        $this->visit();

        $this->migrator()->migrateSite(self::SITE);
        $first = $this->rows();

        // Forget the progress, as an interrupted run that lost it would.
        foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
            \OWA\Core\CoreAPI::dbSingleton()->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
        }

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
        $this->assertSame(4, $done['rows_written']);
        $this->assertCount(4, $this->rows());
        $this->assertNotEmpty($done['completed_at']);

        $again = $this->migrator(1)->migrateSite(self::SITE);
        $this->assertSame(3, $again['rows_read'], 'a completed site is not read again');
    }

    /**
     * An installation holding the dimension keys as VARCHAR, against BIGINT
     * dimension ids. MySQL compares a string with an integer exactly when it
     * looks the id up through its index, and as a double when it does not --
     * and 2^53 and 2^53+1 are one number as doubles. MariaDB compares them
     * exactly either way, so the trap is shown only on MySQL; the migrator must
     * resolve the right row on both.
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
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $joined = $db->get_results(
            "SELECT ua.ua FROM owa_v1fx_request r JOIN owa_v1fx_ua ua IGNORE INDEX (PRIMARY) ON r.ua_id = ua.id"
            . " WHERE r.id = 1790000000000000101");
        $mariadb = stripos((string) ($db->get_row('SELECT VERSION() AS v')['v'] ?? ''), 'mariadb') !== false;
        $this->assertCount($mariadb ? 1 : 2, (array) $joined,
            'the fixture no longer shows how this server compares the keys');

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

    /** A referrer on the site's own domain is no referring site; the URL is kept. */
    public function testASelfReferrerHasNoRefererHost(): void
    {
        $this->visit();
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_referer SET url = 'https://www.alice.example/blog/' WHERE id = 201");

        $this->migrator()->migrateSite(self::SITE);

        $entry = array_values(array_filter($this->rows(), fn ($r) => $r['user_id'] === 'alice'))[0];

        $this->assertNull($entry['referer_host']);
        $this->assertSame('https://www.alice.example/blog/', $entry['referer_url']);
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

    /**
     * A campaign link with no utm_medium and no referrer: v1 filled in its
     * own default medium, `direct`. As a tag it would say the link was tagged
     * direct, so it is dropped and only the campaign and ad are tags.
     */
    public function testACampaignWithV1sDefaultMediumKeepsOnlyTheCampaign(): void
    {
        $this->visit();
        $this->campaignDims();
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_v1fx_referer');
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET medium = 'direct', campaign_id = 701, ad_id = 801"
            . " WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $row = $this->tagged();

        $this->assertNull($row['tagged_medium']);
        $this->assertNull($row['tagged_source']);
        $this->assertSame('spring-sale', $row['tagged_campaign']);
        $this->assertSame('banner-a', $row['tagged_ad']);
    }

    /**
     * A campaign link with a referrer and no utm_source or utm_medium: v1 read
     * both off the referrer. Neither is a tag; the referrer is kept for the
     * cube to read.
     */
    public function testACampaignWithV1sReadingOfTheReferrerKeepsOnlyTheCampaign(): void
    {
        $this->visit();
        $this->campaignDims();
        $this->insert('source_dim', ['id' => '602', 'source_domain' => 'search.example']);
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET medium = 'organic-search', source_id = 602, campaign_id = 701"
            . " WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $row = $this->tagged();

        $this->assertNull($row['tagged_medium']);
        $this->assertNull($row['tagged_source'], 'the referring host, which the cube derives itself');
        $this->assertSame('spring-sale', $row['tagged_campaign']);
        $this->assertSame('search.example', $row['referer_host']);
    }

    /** Each of v1's own mediums is dropped; a medium a link named is kept. */
    public function testOnlyV1sOwnMediumsAreDropped(): void
    {
        $this->visit();
        $this->campaignDims();
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['direct', 'organic-search', 'social-network', 'referral', 'Direct'] as $medium) {
            $db->query('DELETE FROM ' . \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName()
                . ' WHERE site_id = ?', [self::SITE]);
            foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
                $db->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
            }
            $db->query("UPDATE owa_v1fx_request SET medium = ?, source_id = 601, campaign_id = 701"
                . " WHERE id = 1790000000000000101", [$medium]);

            $this->migrator()->migrateSite(self::SITE);

            $row = $this->tagged();

            $this->assertNull($row['tagged_medium'], $medium);
            $this->assertSame('newsletter', $row['tagged_source'], "$medium: a source the link named is kept");
        }

        $db->query('DELETE FROM ' . \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName()
            . ' WHERE site_id = ?', [self::SITE]);
        foreach (['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'] as $t) {
            $db->query("DELETE FROM $t WHERE site_id = ?", [self::SITE]);
        }
        $db->query("UPDATE owa_v1fx_request SET medium = 'cpc' WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $this->assertSame('cpc', $this->tagged()['tagged_medium']);
    }

    /** v1's `(direct)` source is its own label, not a tag. */
    public function testV1sDirectSourceIsNotATag(): void
    {
        $this->visit();
        $this->campaignDims();
        $this->insert('source_dim', ['id' => '603', 'source_domain' => '(direct)']);
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET medium = 'email', source_id = 603, campaign_id = 701"
            . " WHERE id = 1790000000000000101");

        $this->migrator()->migrateSite(self::SITE);

        $this->assertNull($this->tagged()['tagged_source']);
        $this->assertSame('email', $this->tagged()['tagged_medium']);
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
