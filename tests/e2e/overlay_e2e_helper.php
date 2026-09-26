<?php
/**
 * Cross-origin overlay fixture provisioner for the self-host e2e runner.
 *
 * The heatmap overlay and the domstream player are the only genuinely
 * cross-origin consumers of the API: they run on the *tracked* site and fetch
 * from the OWA origin. That is why they used JSONP, and why replacing it with
 * CORS needs proving in a browser rather than with curl.
 *
 * What a spec needs to do that:
 *
 *   provision   a site whose domain host is 'localhost', a document with click
 *               data, a domstream recording, and a scoped overlay token for
 *               each -- returned along with the ids the spec must assert on
 *   cleanup     remove all of it
 *
 * The 'localhost' domain is the whole trick. The self-host runner serves one
 * php -S on 127.0.0.1, and http://localhost:PORT is a *different origin* from
 * http://127.0.0.1:PORT -- same server, different host string. So a harness
 * page loaded from localhost fetching the API on 127.0.0.1 is a real
 * cross-origin request, with a real Origin header, without needing a second
 * host or a DNS entry. CORS then has to allow it on the merits: the matcher
 * compares the Origin's host against configured sites, so a site at
 * 'http://localhost' is what makes the request legitimate rather than a
 * special case.
 *
 * This owns its own site and its own tag. provision() is destructive by design
 * elsewhere in this suite -- rest_e2e_helper's calls cleanup() first, so two
 * callers of one fixture delete each other's user mid-run -- so nothing here
 * touches anything another helper created.
 *
 * Like the other e2e helpers this writes, so it HARD-REFUSES unless booted
 * against the throwaway scratch DB the self-host harness provisions.
 */

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.11';

const SCRATCH_DB_SENTINEL = 'owa_e2e_selfhost';
const FIXTURE_TAG         = 'e2e-overlay';
const OVERLAY_DOMAIN      = 'http://localhost';

// The page the heatmap is drawn over, and the constraint that selects its
// clicks. Held as constants so the fixture, the token and the spec cannot
// disagree about which page is being asked for.
const OVERLAY_PAGE_PATH  = '/overlay-e2e-page';

/**
 * Where the fixture's clicks landed: x, y, and how many at that point.
 *
 * SOME OF THEM SHARE A COORDINATE, and that is the whole point of the shape.
 * Five clicks at five distinct points would give five rows of weight one, and a
 * heatmap that threw the counts away and plotted every row identically would
 * look exactly the same -- so the spec could only ever assert that SOMETHING
 * came back.
 *
 * Three at one point and one each at two others means the grouped result has
 * FEWER ROWS THAN CLICKS, which is a claim about weighting that survives
 * whatever the response envelope looks like.
 */
const OVERLAY_CLICK_POINTS = [
    ['x' => 100, 'y' => 200, 'n' => 3],
    ['x' => 110, 'y' => 210, 'n' => 1],
    ['x' => 120, 'y' => 220, 'n' => 1],
];
/*
 * The heatmap's query, and what its token is bound to.
 *
 * eventName==click is part of it: the heatmap groups by clickX and clickY, and
 * without the filter every non-click row of the page folds into one bucket at
 * NULL,NULL. The overlay token is minted over this exact string, so the constant
 * is what keeps the mint and the request in step.
 */
const OVERLAY_CONSTRAINTS = 'pagePath==' . OVERLAY_PAGE_PATH . ',eventName==click';

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

$connected_db = (string) owa_coreAPI::getSetting('base', 'db_name');
$allowed_db   = getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_SENTINEL;

if ($connected_db !== $allowed_db) {
    fwrite(STDERR, "[overlay_e2e_helper] REFUSING to run: connected DB '$connected_db' "
        . "is not the scratch sentinel '$allowed_db'. This helper only runs under "
        . "the self-host e2e runner.\n");
    exit(3);
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'provision': out(provision()); break;
    case 'cleanup':   out(cleanup());   break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: provision | cleanup\n");
        exit(2);
}

function out(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

function db()
{
    return owa_coreAPI::dbSingleton();
}

/**
 * A site at localhost, a document with clicks, a domstream, and a scoped token
 * for each overlay.
 */
function provision(): array
{
    cleanup();

    $site_id = md5(OVERLAY_DOMAIN);

    $s = owa_coreAPI::entityFactory('base.site');
    $s->set('id', $s->generateId($site_id));
    $s->set('site_id', $site_id);
    $s->set('domain', OVERLAY_DOMAIN);
    $s->set('name', 'OWA overlay cross-origin e2e site');
    $s->set('description', FIXTURE_TAG);
    $s->create();

    // A user for the token to name. The token carries this user's privileges,
    // scoped to one action and one resource.
    $user_id = FIXTURE_TAG . '-admin@owatest.example.com';
    $u = owa_coreAPI::entityFactory('base.user');
    $u->createNewUser($user_id, 'admin', 'pw-' . FIXTURE_TAG, $user_id, 'OWA overlay e2e admin');
    $u->load($u->generateId($user_id), 'user_id');

    $domstream_guid = (string) sprintf('%d', crc32(FIXTURE_TAG . '-ds') + 4000000000);

    /*
     * NO DOCUMENT ROW, and no owa_click rows either.
     *
     * The heatmap is an ordinary dimensional query -- eventCount grouped by clickX
     * and clickY, constrained on pagePath and eventName -- and on v2 every one of
     * those is a COLUMN on the click row itself. owa_document existed because a v1
     * click held only a foreign key; page_path and page_location ride the raw row
     * now, so there is nothing to join to and nothing to pre-create.
     *
     * The clicks are seeded THROUGH logEvent below rather than written as
     * base.click rows. That fixture was self-consistent -- it wrote owa_click and
     * read owa_click back -- and self-consistency was the problem: the v1 chain is
     * unregistered, so a real click never reaches that table, and the specs passed
     * over data no tracked site can produce. The overlay was in fact broken, in
     * two places at once, and neither showed here.
     */

    seedClicks($site_id);

    /*
     * And the cube the heatmap's query reads. Refuses loudly rather than letting
     * the overlay fetch an empty result that still answers 201.
     */
    $cube = buildOverlayCube($site_id);

    if (! empty($cube['status'])) {
        fwrite(STDERR, "[overlay_e2e_helper] cube not built: {$cube['status']}\n");
        exit(4);
    }
    seedDomstream($site_id, $domstream_guid);

    // The player's route is registered by the Domstream module, and a stock
    // install activates 'base' only (Settings.php: 'modules' => array('base')).
    // Without this the /domstreams route simply does not exist, and the request
    // fails during authentication rather than at routing -- the API answers 401
    // "Not authenticated", which reads as a broken credential and is not one.
    // Worth stating because it cost a real debugging detour: the heatmap half of
    // this spec passed throughout, since 'reports' is a Base route.
    $domstream_was_active = (bool) owa_coreAPI::getSetting('domstream', 'is_active');

    if (!$domstream_was_active) {
        owa_coreAPI::activateModule('domstream');
    }

    return [
        'site_id'        => $site_id,
        'domain'         => OVERLAY_DOMAIN,
        'user_id'        => $user_id,
        /*
         * document_id is no longer returned. It named a base.document row this
         * fixture used to mint so a v1 click had something to join to, and the
         * heatmap's query joins nothing now -- page_path and page_location are
         * columns on the click row. Returning an id for a row that does not exist
         * is worse than omitting it.
         */
        'domstream_guid' => $domstream_guid,
        'clicks'         => countClickEvents($site_id),
        // How many DISTINCT points those clicks land on. Fewer than the clicks
        // themselves, which is what lets the spec assert they were weighted
        // rather than merely returned.
        'click_points'   => count(OVERLAY_CLICK_POINTS),
        'domstream_module_activated' => !$domstream_was_active,
        // Scoped tokens: one action, one resource, minutes. Minted here because
        // only the server can sign them.
        'page_path'      => OVERLAY_PAGE_PATH,
        'constraints'    => OVERLAY_CONSTRAINTS,
        /*
         * Bound to `constraints` rather than to a bespoke document_id, because
         * that is the parameter the dimensional query actually carries the page
         * in. The token machinery is generic -- resource_key names whichever
         * request parameter is being pinned -- so this is the same guarantee,
         * on the parameter that now exists.
         */
        'heatmap_token'  => \OWA\Core\OverlayToken::mint(
            $user_id, 'reports', 'constraints', OVERLAY_CONSTRAINTS, 600
        ),
        'player_token'   => \OWA\Core\OverlayToken::mint(
            $user_id, 'domstreams', 'domstream_guid', $domstream_guid, 600
        ),
    ];
}

/**
 * Clicks for the heatmap to plot, through the real beacon.
 *
 * WRITTEN AS base.click ROWS BEFORE, on the reasoning that the spec is about the
 * fetch and not the ingestion path. That reasoning held while logEvent() wrote
 * owa_click; the v1 chain is unregistered now, so those rows are somewhere no
 * query reaches and the fixture was proving the overlay could read a table no
 * visitor writes to.
 *
 * Each point is its own session, so a click contributes to the coordinate grid
 * without a page view having to exist for it -- which is also the honest shape:
 * the heatmap plots clicks, not pages.
 */
function seedClicks(string $site_id): void
{
    $rc  = owa_coreAPI::requestContainerSingleton();
    $now = time();
    $i   = 0;

    foreach (OVERLAY_CLICK_POINTS as $point) {

        for ($k = 0; $k < $point['n']; $k++) {

            $rc->setTimestamp($now - $i);

            $url = OVERLAY_DOMAIN . OVERLAY_PAGE_PATH;

            $event = owa_coreAPI::supportClassFactory('base', 'event');
            $event->setEventType('dom.click');
            $event->setProperties([
                'site_id'         => $site_id,
                'session_id'      => overlayGuid(),
                'visitor_id'      => overlayGuid(),
                'guid'            => overlayGuid(),
                'page_url'        => $url,
                'page_location'   => $url,
                'page_title'      => 'Overlay e2e page',
                'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'],
                'ip_address'      => '203.0.113.44',
                'target_url'      => $url . '#overlay',
                'click_x'         => $point['x'],
                'click_y'         => $point['y'],
                'page_width'      => 1200,
                'page_height'     => 800,
                'dom_element_id'  => 'overlay-target',
                'dom_element_tag' => 'a',
            ]);

            owa_coreAPI::logEvent('dom.click', $event);

            $i++;
        }
    }

    $rc->setTimestamp($now);
}

/** Click rows stored for this site, counted where a real click lands. */
function countClickEvents(string $site_id): int
{
    $db  = db();
    $raw = owa_coreAPI::entityFactory('base.event_raw')->getTableName();

    $rows = $db->get_results(sprintf(
        "SELECT COUNT(*) AS c FROM %s WHERE site_id = '%s' AND event_type = 'click'",
        $raw, $db->prepare($site_id)));

    return is_array($rows) && $rows ? (int) ((array) $rows[0])['c'] : 0;
}

/** Numeric GUID in the tracker's format (BIGINT-safe). */
function overlayGuid(): string
{
    return ((string) time())
        . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
        . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

/**
 * The cube, which is what the heatmap's query reads.
 *
 * Raw alone is not enough: every report goes through the cube, so a fixture that
 * seeds raw and stops hands the overlay an empty result that still answers 201 --
 * the shape of a test passing for the wrong reason, which is what this fixture
 * was already doing by another route.
 */
function buildOverlayCube(string $site_id): array
{
    $site = owa_coreAPI::entityFactory('base.site');
    $site->load($site_id, 'site_id');

    $property_id = (string) $site->get('property_id');

    if (! $property_id) {
        return ['status' => 'no property on the overlay fixture site'];
    }

    $db  = db();
    $raw = owa_coreAPI::entityFactory('base.event_raw')->getTableName();

    $span = $db->get_row(sprintf(
        "SELECT MIN(yyyymmdd) AS lo, MAX(yyyymmdd) AS hi FROM %s WHERE site_id = '%s'",
        $raw, $db->prepare($site_id)));

    if (empty($span['lo'])) {
        return ['status' => 'no raw rows to build from'];
    }

    if (! $db->tableExists(\OWA\Module\Base\Classes\Cube\Cubes::tableFor($property_id))
        && ! \OWA\Module\Base\Classes\Cube\Cubes::create($property_id)) {
        return ['status' => 'could not create the cube'];
    }

    $builder = new \OWA\Module\Base\Classes\Cube\Builder($property_id);
    $rows    = 0;

    foreach ($builder->partitions((int) $span['lo'], (int) $span['hi']) as $partition) {

        $result = $builder->rebuild($partition);

        if (empty($result['ok'])) {
            return ['status' => 'partition ' . $partition['name'] . ' failed to build'];
        }

        $rows += (int) $result['rows'];
    }

    return ['property' => $property_id, 'rows_built' => $rows];
}

function seedDomstream(string $site_id, string $domstream_guid): void
{
    $now = time();

    $d = owa_coreAPI::entityFactory('base.domstream');
    $d->set('id', $d->generateId(FIXTURE_TAG . '-ds'));
    $d->set('site_id', $site_id);
    $d->set('domstream_guid', $domstream_guid);
    $d->set('timestamp', $now);
    $d->set('yyyymmdd', (int) date('Ymd', $now));
    $d->set('duration', 12);
    $d->set('stream', json_encode([
        ['type' => 'mousemove', 'x' => 10, 'y' => 20, 'ts' => 0],
        ['type' => 'mousemove', 'x' => 30, 'y' => 40, 'ts' => 1],
    ]));
    $d->create();
}

function countRows(string $table, string $site_id): int
{
    $db = db();
    $db->selectFrom($table);
    $db->selectColumn('COUNT(*) AS n');
    $db->where('site_id', $site_id);
    $row = $db->getOneRow();

    return (int) ($row['n'] ?? 0);
}

function cleanup(): array
{
    $site_id = md5(OVERLAY_DOMAIN);
    $removed = [];

    $site = owa_coreAPI::entityFactory('base.site');
    $site->load($site_id, 'site_id');

    if ($site->get('property_id')) {
        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor((string) $site->get('property_id'));
        try { db()->query('DROP TABLE IF EXISTS ' . $table); } catch (\Throwable $e) {}
    }

    foreach ([owa_coreAPI::entityFactory('base.event_raw')->getTableName(),
              'owa_click', 'owa_domstream'] as $table) {
        $db = db();
        $db->deleteFrom($table);
        $db->where('site_id', $site_id);
        $db->executeQuery();
        $removed[$table] = 'cleared';
    }

    $db = db();
    $db->deleteFrom('owa_site');
    $db->where('site_id', $site_id);
    $db->executeQuery();
    $removed['owa_site'] = 'cleared';

    $u = owa_coreAPI::entityFactory('base.user');
    $u->delete(FIXTURE_TAG . '-admin@owatest.example.com', 'user_id');
    $removed['owa_user'] = 'cleared';

    // Put the install back to the base-only default this fixture found it in.
    // persistSetting(..., false) removes the key from the settings blob rather
    // than storing a false, which is exactly the state an unactivated module is
    // in -- see the settings-blob falsy-write behaviour.
    owa_coreAPI::deactivateModule('domstream');
    $removed['domstream_module'] = 'deactivated';

    return ['status' => 'cleaned', 'removed' => $removed];
}
