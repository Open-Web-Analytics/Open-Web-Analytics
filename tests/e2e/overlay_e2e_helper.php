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
const OVERLAY_CONSTRAINTS = 'pagePath==' . OVERLAY_PAGE_PATH;

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

    $document_id    = (string) sprintf('%d', crc32(FIXTURE_TAG . '-doc') + 4000000000);
    $domstream_guid = (string) sprintf('%d', crc32(FIXTURE_TAG . '-ds') + 4000000000);

    /*
     * A real document row, which this fixture did not need before.
     *
     * The heatmap is an ordinary dimensional query now -- domClicks grouped by
     * clickX and clickY, constrained on pagePath -- and pagePath resolves
     * through document_id, so the clicks are reached BY JOINING to the document.
     * Seeding clicks against an invented id used to be enough because the old
     * clicks report selected on document_id directly; now it would join to
     * nothing and the overlay would fetch an empty result set that still
     * answered 201, which is the shape of a test that passes for the wrong
     * reason.
     */
    $doc = owa_coreAPI::entityFactory('base.document');
    $doc->set('id', $document_id);
    $doc->set('url', OVERLAY_DOMAIN . OVERLAY_PAGE_PATH);
    $doc->set('uri', OVERLAY_PAGE_PATH);
    $doc->set('page_type', 'page');
    $doc->create();

    seedClicks($site_id, $document_id);
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
        'document_id'    => $document_id,
        'domstream_guid' => $domstream_guid,
        'clicks'         => countRows('owa_click', $site_id),
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
 * Click rows for the heatmap to plot. Written directly: the point of the spec
 * is the fetch, not the ingestion path, which tracker-beacon.spec.js covers.
 */
function seedClicks(string $site_id, string $document_id): void
{
    $now = time();

    for ($i = 0; $i < 5; $i++) {
        $c = owa_coreAPI::entityFactory('base.click');
        $c->set('id', $c->generateId(FIXTURE_TAG . '-click-' . $i));
        $c->set('site_id', $site_id);
        $c->set('document_id', $document_id);
        $c->set('timestamp', $now - $i);
        $c->set('yyyymmdd', (int) date('Ymd', $now));
        $c->set('click_x', 100 + ($i * 10));
        $c->set('click_y', 200 + ($i * 10));
        $c->set('page_width', 1200);
        $c->set('page_height', 800);
        $c->create();
    }
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

    foreach (['owa_click', 'owa_domstream'] as $table) {
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
