<?php
/**
 * v2.0 schema research: star schema vs a single fact table, measured.
 *
 * Builds three representations of the SAME event data and measures disk,
 * report-query latency, insert cost and enrichment cost on MySQL/InnoDB:
 *
 *   A  star   -- a fresh copy of the 1.x fact + dimension tables, production
 *               DDL (indexes and partitioning intact), so the baseline is not
 *               handicapped by years of page fragmentation.
 *   B  event  -- GA4-parity single event table: one row per event, lean typed
 *               columns for the contexts GA models as structs (page, traffic
 *               source, device, geo), a params JSON column for event-specific
 *               fields, commerce line items nested as a JSON array, and NO
 *               session table -- sessions are derived at query time.
 *   C  wide   -- the naive single table: every parameter a real column, raw
 *               user-agent string inlined per event. Exists to isolate what
 *               denormalizing full strings costs in a row store.
 *
 * Domstreams are excluded from every representation: they are recordings, not
 * facts, and no fact-model decision changes what to do with them.
 *
 * Connection and database names come from the environment, never from this
 * file:
 *
 *   V2_DB_HOST, V2_DB_USER, V2_DB_PASS   how to connect
 *   V2_SRC_DB                            existing OWA database to read (never written)
 *   V2_DST_DB                            scratch database to build in (created; dropped by `drop`)
 *
 * Usage: php run.php <build-star|build-event|build-wide|sizes|validate|bench|bench-insert|bench-enrich|drop-event|drop-wide|drop>
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

const EVENT_INDEXES = "
    KEY site_date (site_id, yyyymmdd),
    KEY site_type_date (site_id, event_type, yyyymmdd),
    KEY session (session_id),
    KEY visitor (visitor_id)";

function env(string $k): string {
    $v = getenv($k);
    if ($v === false || $v === '') {
        fwrite(STDERR, "Missing env var $k\n");
        exit(1);
    }
    return $v;
}

function db(): mysqli {
    static $c = null;
    if ($c === null) {
        $c = mysqli_connect(env('V2_DB_HOST'), env('V2_DB_USER'), env('V2_DB_PASS'));
        if (!$c) { fwrite(STDERR, "connect failed\n"); exit(1); }
        // Match the application's session mode so coercion behaves identically.
        $c->query("SET SESSION sql_mode = ''");
    }
    return $c;
}

function q(string $sql): mysqli_result|bool {
    $r = db()->query($sql);
    if ($r === false) {
        fwrite(STDERR, "SQL failed: " . db()->error . "\n" . substr($sql, 0, 400) . "\n");
        exit(1);
    }
    return $r;
}

function one(string $sql): array {
    $r = q($sql);
    $row = $r->fetch_assoc();
    return $row ?: [];
}

/** Yearly range partitions matching how 1.x lays out its fact tables. */
function partitionClause(): string {
    $parts = [];
    for ($y = 2001; $y <= 2027; $y++) {
        $parts[] = sprintf('PARTITION p%d0101 VALUES LESS THAN (%d0101)', $y, $y + 1);
    }
    $parts[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';
    return "PARTITION BY RANGE (yyyymmdd) (\n  " . implode(",\n  ", $parts) . "\n)";
}

// The star tables the comparison covers. Session is included deliberately:
// it is real 1.x storage, and its absence from B and C is a property of the
// single-table model, not an omission.
const STAR_FACTS = ['owa_request', 'owa_click', 'owa_action_fact',
                    'owa_commerce_transaction_fact', 'owa_commerce_line_item_fact', 'owa_session'];
const STAR_DIMS  = ['owa_document', 'owa_referer', 'owa_ua', 'owa_os', 'owa_host',
                    'owa_location_dim', 'owa_source_dim', 'owa_campaign_dim',
                    'owa_ad_dim', 'owa_search_term_dim', 'owa_site'];

function buildStar(string $src, string $dst): void {
    q("CREATE DATABASE IF NOT EXISTS `$dst`");
    foreach (array_merge(STAR_FACTS, STAR_DIMS) as $t) {
        $ddl = one("SHOW CREATE TABLE `$src`.`$t`")['Create Table'];
        $ddl = preg_replace('/^CREATE TABLE `' . $t . '`/', "CREATE TABLE `$dst`.`$t`", $ddl);
        q("DROP TABLE IF EXISTS `$dst`.`$t`");
        q($ddl);
        $t0 = microtime(true);
        q("INSERT INTO `$dst`.`$t` SELECT * FROM `$src`.`$t`");
        printf("  %-34s %8d rows  %6.1fs\n", $t, db()->affected_rows, microtime(true) - $t0);
    }
}

function eventTableDDL(string $dst, string $name): string {
    return "CREATE TABLE `$dst`.`$name` (
    id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    site_id VARCHAR(64) NOT NULL DEFAULT '',
    visitor_id BIGINT UNSIGNED,
    session_id BIGINT UNSIGNED,
    ts BIGINT UNSIGNED NOT NULL,
    yyyymmdd INT UNSIGNED NOT NULL,
    page_url VARCHAR(1024),
    page_title VARCHAR(512),
    referer_url VARCHAR(1024),
    medium VARCHAR(64),
    source VARCHAR(255),
    campaign VARCHAR(255),
    ad VARCHAR(255),
    search_terms VARCHAR(255),
    browser VARCHAR(128),
    browser_type VARCHAR(64),
    os VARCHAR(64),
    language VARCHAR(16),
    country VARCHAR(64),
    city VARCHAR(128),
    host VARCHAR(255),
    ip_address VARCHAR(45),
    is_new_visitor TINYINT,
    params JSON,
    PRIMARY KEY (id, event_type, yyyymmdd)," . EVENT_INDEXES . "
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 " . partitionClause();
}

/** The SELECT list shared by every event transform: resolve each dimension
 *  FK to its content, exactly what a 1.x report join produces. */
function commonJoins(string $src): string {
    return "LEFT JOIN `$src`.owa_ua u            ON u.id  = f.ua_id
            LEFT JOIN `$src`.owa_os o            ON o.id  = f.os_id
            LEFT JOIN `$src`.owa_location_dim l  ON l.id  = f.location_id
            LEFT JOIN `$src`.owa_source_dim s    ON s.id  = f.source_id
            LEFT JOIN `$src`.owa_campaign_dim c  ON c.id  = f.campaign_id
            LEFT JOIN `$src`.owa_ad_dim a        ON a.id  = f.ad_id
            LEFT JOIN `$src`.owa_search_term_dim st ON st.id = f.referring_search_term_id
            LEFT JOIN `$src`.owa_host h          ON h.id  = f.host_id";
}

function buildEvent(string $src, string $dst): void {
    q("CREATE DATABASE IF NOT EXISTS `$dst`");
    q("DROP TABLE IF EXISTS `$dst`.owa_event");
    q(eventTableDDL($dst, 'owa_event'));

    $steps = [];

    $steps['pageview'] = "INSERT INTO `$dst`.owa_event
        SELECT f.id, 'pageview', f.site_id, f.visitor_id, f.session_id, f.timestamp, f.yyyymmdd,
               d.url, d.page_title, r.url,
               f.medium, s.source_domain, c.name, a.name, st.terms,
               u.browser, u.browser_type, o.name, f.language,
               l.country, l.city, h.full_host, f.ip_address,
               f.is_new_visitor,
               CASE WHEN f.cv1_name <> '' THEN JSON_OBJECT(f.cv1_name, f.cv1_value) END
        FROM `$src`.owa_request f
        LEFT JOIN `$src`.owa_document d ON d.id = f.document_id
        LEFT JOIN `$src`.owa_referer  r ON r.id = f.referer_id
        " . commonJoins($src);

    $steps['click'] = "INSERT INTO `$dst`.owa_event
        SELECT f.id, 'click', f.site_id, f.visitor_id, f.session_id, f.timestamp, f.yyyymmdd,
               d.url, d.page_title, r.url,
               f.medium, s.source_domain, c.name, a.name, st.terms,
               u.browser, u.browser_type, o.name, f.language,
               l.country, l.city, h.full_host, f.ip_address,
               f.is_new_visitor,
               JSON_OBJECT('click_x', f.click_x, 'click_y', f.click_y,
                           'page_width', f.page_width, 'page_height', f.page_height,
                           'target_url', f.target_url,
                           'dom_tag', f.dom_element_tag, 'dom_id', f.dom_element_id,
                           'dom_class', f.dom_element_class, 'dom_text', f.dom_element_text,
                           'dom_name', f.dom_element_name, 'dom_parent_id', f.dom_element_parent_id)
        FROM `$src`.owa_click f
        LEFT JOIN `$src`.owa_document d ON d.id = f.document_id
        LEFT JOIN `$src`.owa_referer  r ON r.id = f.referer_id
        " . commonJoins($src);

    $steps['action'] = "INSERT INTO `$dst`.owa_event
        SELECT f.id, 'action', f.site_id, f.visitor_id, f.session_id, f.timestamp, f.yyyymmdd,
               d.url, d.page_title, r.url,
               f.medium, s.source_domain, c.name, a.name, st.terms,
               u.browser, u.browser_type, o.name, f.language,
               l.country, l.city, h.full_host, f.ip_address,
               f.is_new_visitor,
               JSON_OBJECT('action_name', f.action_name, 'action_label', f.action_label,
                           'action_group', f.action_group, 'value', f.numeric_value)
        FROM `$src`.owa_action_fact f
        LEFT JOIN `$src`.owa_document d ON d.id = f.document_id
        LEFT JOIN `$src`.owa_referer  r ON r.id = f.referer_id
        " . commonJoins($src);

    // GA4 nests commerce line items inside the purchase event; JSON_ARRAYAGG
    // is the closest MySQL construct to a repeated record.
    $steps['transaction'] = "INSERT INTO `$dst`.owa_event
        SELECT f.id, 'transaction', f.site_id, f.visitor_id, f.session_id, f.timestamp, f.yyyymmdd,
               d.url, d.page_title, r.url,
               f.medium, s.source_domain, c.name, a.name, st.terms,
               u.browser, u.browser_type, o.name, f.language,
               l.country, l.city, h.full_host, f.ip_address,
               f.is_new_visitor,
               JSON_OBJECT('order_id', f.order_id, 'gateway', f.gateway,
                           'order_source', f.order_source,
                           'total', f.total_revenue, 'tax', f.tax_revenue,
                           'shipping', f.shipping_revenue,
                           'items', (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                                        'sku', li.sku, 'name', li.product_name,
                                        'category', li.category, 'unit_price', li.unit_price,
                                        'quantity', li.quantity, 'revenue', li.item_revenue))
                                     FROM `$src`.owa_commerce_line_item_fact li
                                     WHERE li.order_id = f.order_id))
        FROM `$src`.owa_commerce_transaction_fact f
        LEFT JOIN `$src`.owa_document d ON d.id = f.document_id
        LEFT JOIN `$src`.owa_referer  r ON r.id = f.referer_id
        " . commonJoins($src);

    foreach ($steps as $type => $sql) {
        $t0 = microtime(true);
        q($sql);
        printf("  %-12s %8d rows  %6.1fs\n", $type, db()->affected_rows, microtime(true) - $t0);
    }
}

function buildWide(string $src, string $dst): void {
    q("CREATE DATABASE IF NOT EXISTS `$dst`");
    q("DROP TABLE IF EXISTS `$dst`.owa_event_wide");

    // Same lean columns as B, plus every event-specific parameter as a real
    // column, plus the raw user-agent string per event. NULL where a column
    // does not apply to the event type.
    q("CREATE TABLE `$dst`.owa_event_wide (
        id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(24) NOT NULL,
        site_id VARCHAR(64) NOT NULL DEFAULT '',
        visitor_id BIGINT UNSIGNED,
        session_id BIGINT UNSIGNED,
        ts BIGINT UNSIGNED NOT NULL,
        yyyymmdd INT UNSIGNED NOT NULL,
        page_url VARCHAR(1024),
        page_title VARCHAR(512),
        referer_url VARCHAR(1024),
        medium VARCHAR(64),
        source VARCHAR(255),
        campaign VARCHAR(255),
        ad VARCHAR(255),
        search_terms VARCHAR(255),
        ua VARCHAR(1024),
        browser VARCHAR(128),
        browser_type VARCHAR(64),
        os VARCHAR(64),
        language VARCHAR(16),
        country VARCHAR(64),
        city VARCHAR(128),
        host VARCHAR(255),
        ip_address VARCHAR(45),
        is_new_visitor TINYINT,
        click_x INT, click_y INT, page_width INT, page_height INT,
        target_url VARCHAR(1024),
        dom_tag VARCHAR(64), dom_id VARCHAR(255), dom_class VARCHAR(255),
        dom_text VARCHAR(255), dom_name VARCHAR(255), dom_parent_id VARCHAR(255),
        action_name VARCHAR(255), action_label VARCHAR(255), action_group VARCHAR(255),
        action_value INT,
        order_id VARCHAR(255), gateway VARCHAR(64), order_source VARCHAR(64),
        total_revenue BIGINT, tax_revenue BIGINT, shipping_revenue BIGINT,
        items JSON,
        PRIMARY KEY (id, event_type, yyyymmdd)," . EVENT_INDEXES . "
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 " . partitionClause());

    // Populate from B where the shared columns already exist, joining back to
    // the source only for what B deliberately does not carry (raw UA) and to
    // fan the params back out into columns.
    $common = "id, event_type, site_id, visitor_id, session_id, ts, yyyymmdd,
               page_url, page_title, referer_url, medium, source, campaign, ad, search_terms,
               browser, browser_type, os, language, country, city, host, ip_address, is_new_visitor";

    $steps = [];

    $steps['pageview'] = "INSERT INTO `$dst`.owa_event_wide
        SELECT e.id, e.event_type, e.site_id, e.visitor_id, e.session_id, e.ts, e.yyyymmdd,
               e.page_url, e.page_title, e.referer_url, e.medium, e.source, e.campaign, e.ad, e.search_terms,
               u.ua, e.browser, e.browser_type, e.os, e.language, e.country, e.city, e.host, e.ip_address, e.is_new_visitor,
               NULL,NULL,NULL,NULL, NULL, NULL,NULL,NULL,NULL,NULL,NULL,
               NULL,NULL,NULL,NULL, NULL,NULL,NULL, NULL,NULL,NULL, NULL
        FROM `$dst`.owa_event e
        JOIN `$src`.owa_request f ON f.id = e.id AND e.event_type = 'pageview'
        LEFT JOIN `$src`.owa_ua u ON u.id = f.ua_id";

    $steps['click'] = "INSERT INTO `$dst`.owa_event_wide
        SELECT e.id, e.event_type, e.site_id, e.visitor_id, e.session_id, e.ts, e.yyyymmdd,
               e.page_url, e.page_title, e.referer_url, e.medium, e.source, e.campaign, e.ad, e.search_terms,
               u.ua, e.browser, e.browser_type, e.os, e.language, e.country, e.city, e.host, e.ip_address, e.is_new_visitor,
               f.click_x, f.click_y, f.page_width, f.page_height, f.target_url,
               f.dom_element_tag, f.dom_element_id, f.dom_element_class,
               f.dom_element_text, f.dom_element_name, f.dom_element_parent_id,
               NULL,NULL,NULL,NULL, NULL,NULL,NULL, NULL,NULL,NULL, NULL
        FROM `$dst`.owa_event e
        JOIN `$src`.owa_click f ON f.id = e.id AND e.event_type = 'click'
        LEFT JOIN `$src`.owa_ua u ON u.id = f.ua_id";

    $steps['action'] = "INSERT INTO `$dst`.owa_event_wide
        SELECT e.id, e.event_type, e.site_id, e.visitor_id, e.session_id, e.ts, e.yyyymmdd,
               e.page_url, e.page_title, e.referer_url, e.medium, e.source, e.campaign, e.ad, e.search_terms,
               u.ua, e.browser, e.browser_type, e.os, e.language, e.country, e.city, e.host, e.ip_address, e.is_new_visitor,
               NULL,NULL,NULL,NULL, NULL, NULL,NULL,NULL,NULL,NULL,NULL,
               f.action_name, f.action_label, f.action_group, f.numeric_value,
               NULL,NULL,NULL, NULL,NULL,NULL, NULL
        FROM `$dst`.owa_event e
        JOIN `$src`.owa_action_fact f ON f.id = e.id AND e.event_type = 'action'
        LEFT JOIN `$src`.owa_ua u ON u.id = f.ua_id";

    $steps['transaction'] = "INSERT INTO `$dst`.owa_event_wide
        SELECT e.id, e.event_type, e.site_id, e.visitor_id, e.session_id, e.ts, e.yyyymmdd,
               e.page_url, e.page_title, e.referer_url, e.medium, e.source, e.campaign, e.ad, e.search_terms,
               u.ua, e.browser, e.browser_type, e.os, e.language, e.country, e.city, e.host, e.ip_address, e.is_new_visitor,
               NULL,NULL,NULL,NULL, NULL, NULL,NULL,NULL,NULL,NULL,NULL,
               NULL,NULL,NULL,NULL,
               f.order_id, f.gateway, f.order_source,
               f.total_revenue, f.tax_revenue, f.shipping_revenue,
               e.params->'$.items'
        FROM `$dst`.owa_event e
        JOIN `$src`.owa_commerce_transaction_fact f ON f.id = e.id AND e.event_type = 'transaction'
        LEFT JOIN `$src`.owa_ua u ON u.id = f.ua_id";

    foreach ($steps as $type => $sql) {
        $t0 = microtime(true);
        q($sql);
        printf("  %-12s %8d rows  %6.1fs\n", $type, db()->affected_rows, microtime(true) - $t0);
    }
}

function sizes(string $dst): void {
    $r = q("SELECT table_name t,
                   ROUND(data_length/1048576,1)  data_mb,
                   ROUND(index_length/1048576,1) index_mb,
                   table_rows tr
            FROM information_schema.tables
            WHERE table_schema = '$dst'
            ORDER BY data_length + index_length DESC");
    $tot = ['data' => 0.0, 'idx' => 0.0];
    printf("  %-36s %10s %10s %12s\n", 'table', 'data MB', 'index MB', 'rows');
    while ($row = $r->fetch_assoc()) {
        printf("  %-36s %10.1f %10.1f %12d\n", $row['t'], $row['data_mb'], $row['index_mb'], $row['tr']);
        $tot['data'] += $row['data_mb'];
        $tot['idx']  += $row['index_mb'];
    }
    printf("  %-36s %10.1f %10.1f\n", 'TOTAL', $tot['data'], $tot['idx']);
}

/** The same logical questions asked of each representation. Every query is a
 *  real 1.x report shape. %SITE% and the month bounds are substituted at run
 *  time. */
function querySuite(string $dst): array {
    $site = one("SELECT site_id s FROM `$dst`.owa_event GROUP BY site_id ORDER BY COUNT(*) DESC LIMIT 1")['s']
         ?? one("SELECT site_id s FROM `$dst`.owa_request GROUP BY site_id ORDER BY COUNT(*) DESC LIMIT 1")['s'];
    $m0 = 20110301; $m1 = 20110331;           // densest month in the dataset
    $y0 = 20110101; $y1 = 20111231;

    $star = [
        'pageviews_per_day'  => "SELECT yyyymmdd, COUNT(*) FROM `$dst`.owa_request WHERE site_id='$site' AND yyyymmdd BETWEEN $m0 AND $m1 GROUP BY yyyymmdd",
        'top_pages'          => "SELECT d.url, COUNT(*) n FROM `$dst`.owa_request f JOIN `$dst`.owa_document d ON d.id=f.document_id WHERE f.site_id='$site' AND f.yyyymmdd BETWEEN $m0 AND $m1 GROUP BY d.url ORDER BY n DESC LIMIT 20",
        'top_referers'       => "SELECT r.url, COUNT(*) n FROM `$dst`.owa_request f JOIN `$dst`.owa_referer r ON r.id=f.referer_id WHERE f.site_id='$site' AND f.yyyymmdd BETWEEN $m0 AND $m1 GROUP BY r.url ORDER BY n DESC LIMIT 20",
        'uniques_per_month'  => "SELECT FLOOR(yyyymmdd/100) ym, COUNT(DISTINCT visitor_id) FROM `$dst`.owa_request WHERE site_id='$site' AND yyyymmdd BETWEEN $y0 AND $y1 GROUP BY ym",
        'sessions_bounce'    => "SELECT COUNT(*) sessions, SUM(is_bounce) bounces FROM `$dst`.owa_session WHERE site_id='$site' AND yyyymmdd BETWEEN $m0 AND $m1",
        'avg_session_secs'   => "SELECT AVG(last_req - timestamp) FROM `$dst`.owa_session WHERE site_id='$site' AND yyyymmdd BETWEEN $m0 AND $m1",
        'browser_breakdown'  => "SELECT u.browser, COUNT(*) n FROM `$dst`.owa_request f JOIN `$dst`.owa_ua u ON u.id=f.ua_id WHERE f.site_id='$site' AND f.yyyymmdd BETWEEN $y0 AND $y1 GROUP BY u.browser ORDER BY n DESC LIMIT 15",
        'revenue_by_campaign'=> "SELECT c.name, SUM(f.total_revenue) rev FROM `$dst`.owa_commerce_transaction_fact f LEFT JOIN `$dst`.owa_campaign_dim c ON c.id=f.campaign_id GROUP BY c.name ORDER BY rev DESC",
        'clicks_for_page'    => "SELECT f.click_x, f.click_y FROM `$dst`.owa_click f JOIN `$dst`.owa_document d ON d.id=f.document_id WHERE f.yyyymmdd BETWEEN $m0 AND $m1 AND d.url = (SELECT d2.url FROM `$dst`.owa_click c2 JOIN `$dst`.owa_document d2 ON d2.id=c2.document_id WHERE c2.yyyymmdd BETWEEN $m0 AND $m1 GROUP BY d2.url ORDER BY COUNT(*) DESC LIMIT 1)",
        'pageviews_per_month_year' => "SELECT FLOOR(yyyymmdd/100) ym, COUNT(*) FROM `$dst`.owa_request WHERE site_id='$site' AND yyyymmdd BETWEEN $y0 AND $y1 GROUP BY ym",
    ];

    $eventFor = function (string $table, string $params) use ($dst, $site, $m0, $m1, $y0, $y1): array {
        // Sessionization: what 1.x precomputes into owa_session, derived from
        // events, GA4-style. One subquery shared by both session questions.
        $sessionize = "SELECT session_id,
                              SUM(event_type='pageview') pv,
                              MIN(ts) t0, MAX(ts) t1
                       FROM `$dst`.$table
                       WHERE site_id='$site' AND yyyymmdd BETWEEN $m0 AND $m1 AND session_id IS NOT NULL
                       GROUP BY session_id";
        return [
            'pageviews_per_day'  => "SELECT yyyymmdd, COUNT(*) FROM `$dst`.$table WHERE site_id='$site' AND event_type='pageview' AND yyyymmdd BETWEEN $m0 AND $m1 GROUP BY yyyymmdd",
            'top_pages'          => "SELECT page_url, COUNT(*) n FROM `$dst`.$table WHERE site_id='$site' AND event_type='pageview' AND yyyymmdd BETWEEN $m0 AND $m1 GROUP BY page_url ORDER BY n DESC LIMIT 20",
            'top_referers'       => "SELECT referer_url, COUNT(*) n FROM `$dst`.$table WHERE site_id='$site' AND event_type='pageview' AND yyyymmdd BETWEEN $m0 AND $m1 AND referer_url IS NOT NULL GROUP BY referer_url ORDER BY n DESC LIMIT 20",
            'uniques_per_month'  => "SELECT FLOOR(yyyymmdd/100) ym, COUNT(DISTINCT visitor_id) FROM `$dst`.$table WHERE site_id='$site' AND event_type='pageview' AND yyyymmdd BETWEEN $y0 AND $y1 GROUP BY ym",
            'sessions_bounce'    => "SELECT COUNT(*) sessions, SUM(pv = 1) bounces FROM ($sessionize) s",
            'avg_session_secs'   => "SELECT AVG(t1 - t0) FROM ($sessionize) s",
            'browser_breakdown'  => "SELECT browser, COUNT(*) n FROM `$dst`.$table WHERE site_id='$site' AND event_type='pageview' AND yyyymmdd BETWEEN $y0 AND $y1 GROUP BY browser ORDER BY n DESC LIMIT 15",
            'revenue_by_campaign'=> "SELECT campaign, SUM($params) rev FROM `$dst`.$table WHERE event_type='transaction' GROUP BY campaign ORDER BY rev DESC",
            'clicks_for_page'    => ($table === 'owa_event'
                ? "SELECT params->'$.click_x', params->'$.click_y' FROM `$dst`.$table WHERE event_type='click' AND yyyymmdd BETWEEN $m0 AND $m1 AND page_url = (SELECT page_url FROM `$dst`.$table WHERE event_type='click' AND yyyymmdd BETWEEN $m0 AND $m1 GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT 1)"
                : "SELECT click_x, click_y FROM `$dst`.$table WHERE event_type='click' AND yyyymmdd BETWEEN $m0 AND $m1 AND page_url = (SELECT page_url FROM `$dst`.$table WHERE event_type='click' AND yyyymmdd BETWEEN $m0 AND $m1 GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT 1)"),
            'pageviews_per_month_year' => "SELECT FLOOR(yyyymmdd/100) ym, COUNT(*) FROM `$dst`.$table WHERE site_id='$site' AND event_type='pageview' AND yyyymmdd BETWEEN $y0 AND $y1 GROUP BY ym",
        ];
    };

    return [
        'star'  => $star,
        'event' => $eventFor('owa_event', "CAST(params->>'$.total' AS UNSIGNED)"),
        'wide'  => $eventFor('owa_event_wide', 'total_revenue'),
    ];
}

function timeQuery(string $sql): array {
    $times = [];
    $rows  = 0;
    for ($i = 0; $i < 5; $i++) {
        $t0 = microtime(true);
        $r  = q($sql);
        $rows = $r instanceof mysqli_result ? $r->num_rows : 0;
        if ($r instanceof mysqli_result) { $r->free(); }
        $times[] = (microtime(true) - $t0) * 1000;
    }
    $cold = $times[0];
    $warm = array_slice($times, 1);
    sort($warm);
    return ['cold_ms' => round($cold, 1), 'warm_ms' => round($warm[1], 1), 'rows' => $rows];
}

function bench(string $dst): void {
    $suites  = querySuite($dst);
    $results = [];
    foreach ($suites as $repr => $queries) {
        // Skip representations that are not currently built.
        $probe = ['star' => 'owa_request', 'event' => 'owa_event', 'wide' => 'owa_event_wide'][$repr];
        if (!q("SHOW TABLES FROM `$dst` LIKE '$probe'")->num_rows) {
            printf("  (%s not built, skipped)\n", $repr);
            continue;
        }
        foreach ($queries as $name => $sql) {
            $results[$name][$repr] = timeQuery($sql);
            printf("  %-26s %-6s cold %8.1fms  warm %8.1fms  %6d rows\n",
                $name, $repr, $results[$name][$repr]['cold_ms'],
                $results[$name][$repr]['warm_ms'], $results[$name][$repr]['rows']);
        }
    }
    file_put_contents(__DIR__ . '/results/bench-' . date('Ymd-His') . '.json',
        json_encode($results, JSON_PRETTY_PRINT) . "\n");
}

function validate(string $dst): void {
    // The three representations hold the same logical data or the benchmark
    // is meaningless. Totals that must agree exactly:
    $checks = [
        'pageviews' => [
            'star'  => "SELECT COUNT(*) n FROM `$dst`.owa_request",
            'event' => "SELECT COUNT(*) n FROM `$dst`.owa_event WHERE event_type='pageview'",
            'wide'  => "SELECT COUNT(*) n FROM `$dst`.owa_event_wide WHERE event_type='pageview'",
        ],
        'clicks' => [
            'star'  => "SELECT COUNT(*) n FROM `$dst`.owa_click",
            'event' => "SELECT COUNT(*) n FROM `$dst`.owa_event WHERE event_type='click'",
            'wide'  => "SELECT COUNT(*) n FROM `$dst`.owa_event_wide WHERE event_type='click'",
        ],
        'revenue' => [
            'star'  => "SELECT SUM(total_revenue) n FROM `$dst`.owa_commerce_transaction_fact",
            'event' => "SELECT SUM(CAST(params->>'$.total' AS UNSIGNED)) n FROM `$dst`.owa_event WHERE event_type='transaction'",
            'wide'  => "SELECT SUM(total_revenue) n FROM `$dst`.owa_event_wide WHERE event_type='transaction'",
        ],
        'line_items' => [
            'star'  => "SELECT COUNT(*) n FROM `$dst`.owa_commerce_line_item_fact",
            'event' => "SELECT SUM(JSON_LENGTH(params->'$.items')) n FROM `$dst`.owa_event WHERE event_type='transaction'",
            'wide'  => "SELECT SUM(JSON_LENGTH(items)) n FROM `$dst`.owa_event_wide WHERE event_type='transaction'",
        ],
    ];
    foreach ($checks as $name => $per) {
        $vals = [];
        foreach ($per as $repr => $sql) {
            $probe = ['star' => 'owa_request', 'event' => 'owa_event', 'wide' => 'owa_event_wide'][$repr];
            if (!q("SHOW TABLES FROM `$dst` LIKE '$probe'")->num_rows) { continue; }
            $vals[$repr] = one($sql)['n'];
        }
        $distinct = count(array_unique(array_map('strval', $vals)));
        printf("  %-12s %s  %s\n", $name, json_encode($vals), $distinct === 1 ? 'MATCH' : '*** MISMATCH ***');
    }
}

function benchInsert(string $dst): void {
    // What one tracked pageview costs to WRITE in each model. The star write
    // is the request insert plus the session upsert 1.x performs per event,
    // plus the dimension existence checks its handlers issue. The single
    // table writes one row and looks nothing up: ids are content-derived.
    q("DROP TABLE IF EXISTS `$dst`.ins_request"); q("CREATE TABLE `$dst`.ins_request LIKE `$dst`.owa_request");
    q("DROP TABLE IF EXISTS `$dst`.ins_session"); q("CREATE TABLE `$dst`.ins_session LIKE `$dst`.owa_session");
    q("DROP TABLE IF EXISTS `$dst`.ins_event");   q("CREATE TABLE `$dst`.ins_event LIKE `$dst`.owa_event");

    $n = 3000;

    $t0 = microtime(true);
    for ($i = 1; $i <= $n; $i++) {
        $sid = 5000000000 + intdiv($i, 4);   // ~4 events per session
        q("INSERT INTO `$dst`.ins_request (id, visitor_id, session_id, timestamp, yyyymmdd, site_id, medium)
           VALUES ($i, 42, $sid, $i, 20250101, 'bench', 'direct')");
        q("INSERT INTO `$dst`.ins_session (id, visitor_id, timestamp, yyyymmdd, site_id, num_pageviews, last_req)
           VALUES ($sid, 42, $i, 20250101, 'bench', 1, $i)
           ON DUPLICATE KEY UPDATE num_pageviews = num_pageviews + 1, last_req = $i");
        // The five dimension existence checks the 1.x handlers issue.
        foreach (['owa_document', 'owa_ua', 'owa_os', 'owa_host', 'owa_referer'] as $dim) {
            q("SELECT id FROM `$dst`.$dim WHERE id = $i LIMIT 1");
        }
    }
    $star = microtime(true) - $t0;

    $t0 = microtime(true);
    for ($i = 1; $i <= $n; $i++) {
        $sid = 5000000000 + intdiv($i, 4);
        q("INSERT INTO `$dst`.ins_event (id, event_type, site_id, visitor_id, session_id, ts, yyyymmdd, medium)
           VALUES ($i, 'pageview', 'bench', 42, $sid, $i, 20250101, 'direct')");
    }
    $single = microtime(true) - $t0;

    printf("  star   (1 insert + 1 upsert + 5 dim checks per event): %6.1fs  %6.0f events/s  7 stmts/event\n", $star, $n / $star);
    printf("  single (1 insert per event):                           %6.1fs  %6.0f events/s  1 stmt/event\n", $single, $n / $single);
    foreach (['ins_request', 'ins_session', 'ins_event'] as $t) { q("DROP TABLE `$dst`.$t"); }
}

function benchEnrich(string $dst): void {
    // The late-arriving-attribute problem. 1.x crawls a referer once and
    // updates ONE dimension row; every event pointing at it sees the new
    // attribute through the join. Denormalized, the same fact must be written
    // onto every event row that carries that referer.
    $top = one("SELECT referer_url u, COUNT(*) n FROM `$dst`.owa_event
                WHERE referer_url IS NOT NULL GROUP BY referer_url ORDER BY n DESC LIMIT 1");
    $url = db()->real_escape_string($top['u']);

    $t0 = microtime(true);
    q("UPDATE `$dst`.owa_referer SET snippet = 'enriched' WHERE url = '$url'");
    $star = microtime(true) - $t0;
    $starRows = db()->affected_rows;

    $t0 = microtime(true);
    q("UPDATE `$dst`.owa_event SET params = JSON_SET(COALESCE(params,'{}'), '$.referer_snippet', 'enriched')
       WHERE referer_url = '$url'");
    $single = microtime(true) - $t0;
    $singleRows = db()->affected_rows;

    printf("  most-referenced referer occurs on %d event rows\n", (int)$top['n']);
    printf("  star:   %8.3fs  %6d row(s) written\n", $star, $starRows);
    printf("  single: %8.3fs  %6d row(s) written\n", $single, $singleRows);
}

// ---------------------------------------------------------------------------

$src = env('V2_SRC_DB');
$dst = env('V2_DST_DB');
if ($src === $dst && !in_array($argv[1] ?? "", ["build-event","build-wide","sizes","validate","bench","bench-insert","bench-enrich","drop-event","drop-wide","drop"])) { fwrite(STDERR, "source and scratch must differ\n"); exit(1); }

switch ($argv[1] ?? '') {
    case 'build-star':   buildStar($src, $dst);  break;
    case 'build-event':  buildEvent($src, $dst); break;
    case 'build-wide':   buildWide($src, $dst);  break;
    case 'sizes':        sizes($dst);            break;
    case 'validate':     validate($dst);         break;
    case 'bench':        bench($dst);            break;
    case 'bench-insert': benchInsert($dst);      break;
    case 'bench-enrich': benchEnrich($dst);      break;
    case 'drop-event':   q("DROP TABLE IF EXISTS `$dst`.owa_event");      echo "  dropped owa_event\n"; break;
    case 'drop-wide':    q("DROP TABLE IF EXISTS `$dst`.owa_event_wide"); echo "  dropped owa_event_wide\n"; break;
    case 'drop':         q("DROP DATABASE IF EXISTS `$dst`");             echo "  dropped $dst\n"; break;
    default:
        fwrite(STDERR, "usage: php run.php <build-star|build-event|build-wide|sizes|validate|bench|bench-insert|bench-enrich|drop-event|drop-wide|drop>\n");
        exit(1);
}
