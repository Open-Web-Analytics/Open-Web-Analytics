<?php
/**
 * Fixtures for app-passwords.spec.js and app-passwords-screens.spec.js: a
 * user, a site, and application passwords for the user. Runs only against the
 * self-host runner's scratch database, as rest_e2e_helper.php does.
 *
 *   php app_password_e2e_helper.php provision   user, site, a full and a read-only password
 *   php app_password_e2e_helper.php revoke <lookup_id>
 *   php app_password_e2e_helper.php list        the user's passwords, without hashes
 *   php app_password_e2e_helper.php cleanup
 *
 * Its own tag, so it never touches rest_e2e_helper.php's fixture.
 */

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

const SCRATCH_DB_SENTINEL = 'owa_e2e_selfhost';
const FIXTURE_TAG         = 'e2e-apppw';
const FIXTURE_USER        = FIXTURE_TAG . '-admin@owatest.example.com';
const FIXTURE_PASSWORD    = 'pw-' . FIXTURE_TAG;

use OWA\Module\Base\Classes\AppPasswords;

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

$connected_db = (string) \OWA\Core\CoreAPI::getSetting('base', 'db_name');
$allowed_db   = getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_SENTINEL;

if ($connected_db !== $allowed_db) {
    fwrite(STDERR, "[app_password_e2e_helper] REFUSING to run: connected DB '$connected_db' "
        . "is not the scratch sentinel '$allowed_db'.\n");
    exit(3);
}

switch ($argv[1] ?? '') {
    case 'provision': out(provision()); break;
    case 'revoke':    out(['revoked' => AppPasswords::revoke((string) ($argv[2] ?? ''), FIXTURE_USER)]); break;
    case 'list':      out(['passwords' => AppPasswords::forUser(FIXTURE_USER)]); break;
    case 'cleanup':   out(cleanup()); break;
    default:
        fwrite(STDERR, "Use: provision | revoke <lookup_id> | list | cleanup\n");
        exit(2);
}

function out(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

function provision(): array
{
    cleanup();

    $u = \OWA\Core\CoreAPI::entityFactory('base.user');
    $u->createNewUser(FIXTURE_USER, 'admin', FIXTURE_PASSWORD, FIXTURE_USER, 'OWA app password e2e admin');

    $site_domain = 'https://' . FIXTURE_TAG . '.example.com';

    $s = \OWA\Core\CoreAPI::entityFactory('base.site');
    $s->set('id', $s->generateId($site_domain));
    $s->set('site_id', $s->generateId($site_domain));
    $s->set('domain', $site_domain);
    $s->set('name', 'OWA app password e2e site');
    $s->create();

    return [
        'user_id' => FIXTURE_USER,
        'password' => FIXTURE_PASSWORD,
        'site_id' => $s->get('site_id'),
        'full'    => AppPasswords::create(FIXTURE_USER, 'e2e full', AppPasswords::SCOPE_FULL),
        'read'    => AppPasswords::create(FIXTURE_USER, 'e2e read', AppPasswords::SCOPE_READ),
    ];
}

function cleanup(): array
{
    AppPasswords::revokeAllForUser(FIXTURE_USER);

    $db = \OWA\Core\CoreAPI::dbSingleton();
    $db->deleteFrom('owa_user');
    $db->where('user_id', FIXTURE_USER);
    $db->executeQuery();

    $db = \OWA\Core\CoreAPI::dbSingleton();
    $db->deleteFrom('owa_site');
    $db->where('domain', '%' . FIXTURE_TAG . '%', 'LIKE');
    $db->executeQuery();

    return ['cleaned' => FIXTURE_TAG];
}
