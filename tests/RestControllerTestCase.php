<?php

use PHPUnit\Framework\TestCase;

/**
 * Shared base for the documented REST API endpoint tests
 * (routes registered in modules/base/module.php:563-569).
 *
 * Unlike the tracker ingestion tests (IngestionTestCase), these drive the
 * REST request path: a controller's doAction() followed by rendering its
 * REST view to the final JSON body. Rendering through the view matters --
 * several endpoints sanitize secrets in the *view*, not the controller
 * (e.g. owa_usersRestView drops password/temp_passkey), so a controller-only
 * assertion would miss a view-level disclosure.
 *
 * Authentication is simulated exactly as owa_auth::authByApiKey() does on
 * success (loadNewUserByObject + setAuthStatus(true)); OWA_REST_DEBUG is
 * defined so api-key auth would skip the request-signature check if a test
 * ever exercises the real auth layer. No HTTP is involved, so no signature
 * needs forging.
 *
 * Cleanup: every fixture row (users, sites, site_user relations) is removed
 * in tearDown regardless of assertion outcome. Tests skip cleanly when the
 * OWA database is unreachable.
 */
abstract class RestControllerTestCase extends TestCase
{
    /** @var string unique per-test suffix so repeat/parallel runs never collide */
    protected $tok;

    /** @var array<int, array{entity:string, value:mixed, col:string}> rows to delete */
    private $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('OWA_REST_DEBUG')) {
            // Skip the api-key request-signature check if the real auth layer
            // is ever exercised. Harmless for the current controller-driven tests.
            define('OWA_REST_DEBUG', true);
        }

        if (!defined('OWA_TEST_REST_BOOTSTRAPPED')) {
            define('OWA_TEST_REST_BOOTSTRAPPED', true);

            $owa_root = dirname(__DIR__) . '/';

            $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT']
                ?? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                 . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
            $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

            require_once($owa_root . 'owa.php');

            $GLOBALS['owa_test_rest_instance'] = new owa([
                'instance_role' => 'rest_api',
            ]);
        }

        owa_coreAPI::setSetting('base', 'request_mode', 'rest_api');

        // The new-account email observer fires when POST /users creates a user.
        // The default derived mailer-from ("owa@localhost") is rejected by
        // PHPMailer (no dot in the domain), which would abort the request path
        // with an exception unrelated to what we're testing. Pin a valid,
        // clearly non-deliverable from-address for the test process.
        owa_coreAPI::setSetting('base', 'mailer-from', 'owa@owatest.example.com');
    }

    protected function setUp(): void
    {
        if (!$this->dbAvailable()) {
            $this->markTestSkipped('OWA database not reachable; skipping REST endpoint test.');
        }
        $this->tok = substr(md5(uniqid('owarest', true)), 0, 12);
        // Start every test anonymous.
        $this->resetCurrentUser();
    }

    protected function tearDown(): void
    {
        // Delete in LIFO order so relations go before the rows they reference.
        foreach (array_reverse($this->cleanup) as $row) {
            try {
                owa_coreAPI::entityFactory($row['entity'])->delete($row['value'], $row['col']);
            } catch (\Throwable $ex) {
                // best-effort
            }
        }
        $this->cleanup = [];
        $this->resetCurrentUser();
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * Create a user with the given role. Returns [id, user_id, api_key].
     *
     * @return array{id:mixed, user_id:string, api_key:string}
     */
    protected function makeUser(string $role, string $label = 'u'): array
    {
        $user_id = $role . '-' . $label . '-' . $this->tok . '@owatest.example.com';
        $u = owa_coreAPI::entityFactory('base.user');
        $u->createNewUser($user_id, $role, 'pw' . $this->tok, $user_id, 'OWA Test ' . $role);
        $u->load($user_id, 'user_id');
        $this->assertNotEmpty($u->get('id'), "Failed to create {$role} user fixture.");

        $this->trackForCleanup('base.user', $u->get('id'), 'id');

        return [
            'id'      => $u->get('id'),
            'user_id' => $user_id,
            'api_key' => $u->get('api_key'),
        ];
    }

    /**
     * Create a site. Returns [id, site_id, domain].
     *
     * @return array{id:mixed, site_id:string, domain:string}
     */
    protected function makeSite(string $label = 's'): array
    {
        $domain = 'https://owatest-' . $label . '-' . $this->tok . '.example.com';
        $sm = owa_coreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite($domain, 'OWA Test Site ' . $label . ' ' . $this->tok);
        $this->assertNotEmpty($site, 'Failed to create site fixture.');

        $this->trackForCleanup('base.site', $site->get('id'), 'id');

        /*
         * AND THE PROPERTY IT MINTED.
         *
         * Creating a site creates the Property above it -- a site is an
         * Observation Profile now, and a Profile has to hang off something.
         * This helper is the one every REST test builds its fixture site
         * through, so a Property left here is one per test across the whole
         * suite: they had accumulated in the hundreds, each one a row in the
         * site picker's Properties column.
         */
        $property = $site->get('property_id');

        if ($property) {
            $this->trackForCleanup('base.property', $property, 'id');
        }

        return [
            'id'      => $site->get('id'),
            'site_id' => $site->get('site_id'),
            'domain'  => $domain,
        ];
    }

    protected function trackForCleanup(string $entity, $value, string $col = 'id'): void
    {
        $this->cleanup[] = ['entity' => $entity, 'value' => $value, 'col' => $col];
    }

    // ---------------------------------------------------------------------
    // Auth
    // ---------------------------------------------------------------------

    /**
     * Authenticate the current request as a freshly created user of $role.
     * Returns the fixture array from makeUser().
     *
     * @return array{id:mixed, user_id:string, api_key:string}
     */
    protected function authenticateAs(string $role): array
    {
        $fixture = $this->makeUser($role, 'auth');

        $entity = owa_coreAPI::entityFactory('base.user');
        $entity->load($fixture['id'], 'id');

        $cu = owa_coreAPI::getCurrentUser();
        $cu->loadNewUserByObject($entity);
        $cu->setAuthStatus(true);

        return $fixture;
    }

    protected function resetCurrentUser(): void
    {
        $anon = owa_coreAPI::entityFactory('base.user');
        $anon->set('user_id', '');
        $anon->set('role', 'everyone');

        $cu = owa_coreAPI::getCurrentUser();
        $cu->loadNewUserByObject($anon);

        // Force the authenticated flag off (setAuthStatus has no "false" path,
        // and the current-user object is a process singleton).
        $ref = new \ReflectionObject($cu);
        if ($ref->hasProperty('is_authenticated')) {
            $p = $ref->getProperty('is_authenticated');
            $p->setAccessible(true);
            $p->setValue($cu, false);
        }
    }

    // ---------------------------------------------------------------------
    // Driving the endpoint
    // ---------------------------------------------------------------------

    /**
     * Instantiate a controller, run doAction(), and return the raw $data array.
     *
     * $controllerFile is resolved relative to modules/base/controllers/ for the
     * common case; an absolute path (e.g. a controller in another module such as
     * domstream) is required verbatim.
     */
    protected function runControllerData(string $class, string $controllerFile, array $params): array
    {
        // Controllers autoload by name (compat bridge + Composer PSR-4); only fall
        // back to an explicit require if the class is somehow not yet defined. The
        // legacy 'controllers/<file>.php' path no longer exists post-PSR-4, so this
        // require must stay guarded.
        if (!class_exists($class)) {
            $path = ($controllerFile[0] === '/')
                ? $controllerFile
                : OWA_BASE_MODULE_DIR . 'controllers/' . $controllerFile;
            require_once($path);
        }
        $ctrl = new $class($params);
        $data = $ctrl->doAction();
        return is_array($data) ? $data : [];
    }

    /**
     * Full pipeline: run the controller, render its view to the final JSON,
     * and return the decoded response array:
     *   ['status' => int, 'json' => array, 'raw' => string, 'data' => mixed]
     *
     * 'json' is the decoded top-level envelope (requestId/httpResponse/error/data),
     * 'data' is the payload (json['data']).
     */
    protected function callEndpoint(string $class, string $controllerFile, array $params): array
    {
        $data = $this->runControllerData($class, $controllerFile, $params);

        $raw = '';
        if (!empty($data['view'])) {
            ob_start();
            $returned = owa_coreAPI::displayView($data);
            $captured = ob_get_clean();
            $raw = ($returned !== null && $returned !== '') ? $returned : $captured;
        }

        $json = json_decode($raw, true);

        return [
            'status'    => is_array($json) ? ($json['httpResponse']['status_code'] ?? null) : null,
            'json'      => is_array($json) ? $json : [],
            'raw'       => $raw,
            'data'      => is_array($json) ? ($json['data'] ?? null) : null,
            'view'      => $data['view'] ?? null,
            'data_raw'  => $data, // controller-level data, pre-render
        ];
    }

    // ---------------------------------------------------------------------
    // Assertions / helpers
    // ---------------------------------------------------------------------

    /**
     * Assert a response is the "not authenticated" REST rejection.
     */
    protected function assertNotAuthenticated(array $resp, string $context = ''): void
    {
        $this->assertSame('base.restApi', $resp['view'],
            "Unauthenticated request should route to the restApi error view. {$context}");
        $this->assertSame(401, $resp['status'],
            "Unauthenticated request should return HTTP 401. {$context}");
        $this->assertNull($resp['data'],
            "Unauthenticated request must not return a data payload. {$context}");
    }

    /**
     * Assert a substring (case-insensitive) does NOT appear anywhere in the raw
     * JSON body -- used to prove a secret column value is not disclosed.
     */
    protected function assertNotInBody(string $needle, string $raw, string $msg): void
    {
        $this->assertStringNotContainsStringIgnoringCase($needle, $raw, $msg);
    }

    protected function countSiteUserRows($siteNumericId): int
    {
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_site_user');
        $db->selectColumn('*');
        $db->where('site_id', $siteNumericId);
        $rows = $db->getAllRows();
        return is_array($rows) ? count($rows) : 0;
    }

    protected function userExists(string $user_id): bool
    {
        $u = owa_coreAPI::entityFactory('base.user');
        $u->load($user_id, 'user_id');
        return !empty($u->get('id'));
    }

    private function dbAvailable(): bool
    {
        try {
            $db = owa_coreAPI::dbSingleton();
            $row = $db->get_row('SELECT 1 AS ok');
            return is_array($row) && isset($row['ok']) && $row['ok'] == 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
