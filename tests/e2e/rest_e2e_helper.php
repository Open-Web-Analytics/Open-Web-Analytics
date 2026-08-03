<?php
/**
 * REST route fixture provisioner for the self-host e2e runner.
 *
 * The REST routes have unit coverage but nothing exercises them over HTTP, so a
 * registration or wiring error -- a class that no longer exists, a route name
 * that no longer matches, a controller that cannot be constructed -- passes both
 * suites and only surfaces in production. This helper supplies what a spec needs
 * to call them for real:
 *
 *   provision            create an admin user + a site, return their identifiers
 *                        along with the auth key needed to sign requests
 *   cleanup              remove what provision() created
 *   modules              which modules are active -- a module that is not active
 *                        never runs registerRestApiRoute(), so its routes do not
 *                        exist and a spec must not assert them
 *
 * Requests are signed rather than run with OWA_REST_DEBUG enabled. That constant
 * makes authByApiKey() skip signature verification entirely, so a suite relying
 * on it would never exercise the auth path it is meant to be calling through.
 * The signature scheme is Auth::generateSignature():
 *
 *   base64( hash('sha256', 'OWASIGNATURE' . apiKey . requestUrl . Ymd(UTC) . OWA_AUTH_KEY ) )
 *
 * -- note it base64-encodes the HEX digest, not raw bytes.
 *
 * Like the other e2e helpers this writes, so it HARD-REFUSES unless booted
 * against the throwaway scratch DB the self-host harness provisions.
 */

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

const SCRATCH_DB_SENTINEL = 'owa_e2e_selfhost';
const FIXTURE_TAG         = 'e2e-rest';

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

$connected_db = (string) owa_coreAPI::getSetting('base', 'db_name');
$allowed_db   = getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_SENTINEL;

if ($connected_db !== $allowed_db) {
    fwrite(STDERR, "[rest_e2e_helper] REFUSING to run: connected DB '$connected_db' "
        . "is not the scratch sentinel '$allowed_db'. This helper only runs under "
        . "the self-host e2e runner.\n");
    exit(3);
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'provision': out(provision()); break;
    case 'cleanup':   out(cleanup());   break;
    case 'modules':   out(['active' => (array) owa_coreAPI::getSetting('base', 'modules')]); break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: provision | cleanup | modules\n");
        exit(2);
}

function out(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

/**
 * An admin user (so every route's capability check passes) and a site to
 * operate on. Returns the auth key too: without it a spec cannot sign a
 * request, and unsigned requests are rejected before reaching any controller.
 */
function provision(): array
{
    cleanup();

    $user_id = FIXTURE_TAG . '-admin@owatest.example.com';

    $u = owa_coreAPI::entityFactory('base.user');
    $u->createNewUser($user_id, 'admin', 'pw-' . FIXTURE_TAG, $user_id, 'OWA REST e2e admin');
    $u->load($u->generateId($user_id), 'user_id');

    $site_domain = 'https://' . FIXTURE_TAG . '.example.com';

    $s = owa_coreAPI::entityFactory('base.site');
    $s->set('id', $s->generateId($site_domain));
    $s->set('site_id', $s->generateId($site_domain));
    $s->set('domain', $site_domain);
    $s->set('name', 'OWA REST e2e site');
    $s->set('description', 'fixture');
    $s->create();

    return [
        'user_id'  => $user_id,
        'api_key'  => $u->get('api_key'),
        'auth_key' => defined('OWA_AUTH_KEY') ? OWA_AUTH_KEY : '',
        'site_id'  => $s->get('site_id'),
        'domain'   => $site_domain,
    ];
}

function cleanup(): array
{
    $db = owa_coreAPI::dbSingleton();

    // Users and sites this fixture created, matched on the tag so a stray run
    // cannot delete anything a different spec relies on.
    $db->deleteFrom('owa_user');
    $db->where('user_id', '%' . FIXTURE_TAG . '%', 'LIKE');
    $db->executeQuery();

    $db = owa_coreAPI::dbSingleton();
    $db->deleteFrom('owa_site');
    $db->where('domain', '%' . FIXTURE_TAG . '%', 'LIKE');
    $db->executeQuery();

    return ['cleaned' => FIXTURE_TAG];
}
