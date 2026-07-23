<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the siteUsers REST controller
 * (modules/base/controllers/siteAddAllowedUserRestController.php).
 *
 * These lock the behavior of the security hardening for the endpoint
 * POST /owa/api/base/v1/siteUsers:
 *
 *   1. An unauthenticated request is rejected (401) and never reaches
 *      action() -- the edit_sites capability gate fires.
 *   2. A legitimate authenticated admin request still succeeds -- proving
 *      that moving the parent from owa_cliController (CLI-only, exits for
 *      web/REST) to owa_adminController did NOT break the intended use.
 *   3. The api_key (and password / temp_passkey) is NOT disclosed in the
 *      response body.
 *   4. A bogus siteId / user_id is rejected by validation and NEVER writes
 *      a site_user relation row (the DoS / bad-foreign-key vector).
 *
 * The controller is driven at the controller layer (doAction) rather than
 * over HTTP so the authenticated success path can be exercised without
 * forging the request signature that api-key auth requires. Authentication
 * is simulated exactly as owa_auth::authByApiKey does on success:
 * loadNewUserByObject() + setAuthStatus(true) on the service current user.
 *
 * Cleanup: every fixture row (user, site, site_user) is removed in tearDown
 * regardless of assertion outcome, so residue is bounded even on failure.
 */
final class SiteAddAllowedUserRestControllerTest extends TestCase
{
    /** @var string unique suffix so parallel/repeat runs never collide */
    private $tok;

    /** @var array{id:?int,user_id:string}|null created admin fixture */
    private $admin = null;

    /** @var array{id:?int,user_id:string}|null created target user fixture */
    private $targetUser = null;

    /** @var array{id:?int,site_id:string}|null created site fixture */
    private $site = null;

    public static function setUpBeforeClass(): void
    {
        if (!defined('OWA_TEST_REST_BOOTSTRAPPED')) {
            define('OWA_TEST_REST_BOOTSTRAPPED', true);

            $owa_root = dirname(__DIR__) . '/';

            // Non-robotic UA + a REMOTE_ADDR so the framework treats this as a
            // normal request context.
            $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT']
                ?? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                 . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
            $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

            require_once($owa_root . 'owa.php');

            // Boot in the REST role, matching api/index.php's entry point.
            $GLOBALS['owa_test_rest_instance'] = new owa([
                'instance_role' => 'rest_api',
            ]);
        }

        owa_coreAPI::setSetting('base', 'request_mode', 'rest_api');
    }

    protected function setUp(): void
    {
        if (!$this->dbAvailable()) {
            $this->markTestSkipped('OWA database not reachable; skipping REST controller test.');
        }

        // uniqid() is fine in PHP tests (the no-random rule applies to workflow scripts).
        $this->tok = substr(md5(uniqid('owatest', true)), 0, 12);
    }

    protected function tearDown(): void
    {
        // Remove the site_user relation first (keyed by the numeric site id).
        if ($this->site && !empty($this->site['id'])) {
            $this->safeDelete('base.site_user', $this->site['id'], 'site_id');
        }
        if ($this->site && !empty($this->site['id'])) {
            $this->safeDelete('base.site', $this->site['id'], 'id');
        }
        if ($this->targetUser && !empty($this->targetUser['id'])) {
            $this->safeDelete('base.user', $this->targetUser['id'], 'id');
        }
        if ($this->admin && !empty($this->admin['id'])) {
            $this->safeDelete('base.user', $this->admin['id'], 'id');
        }

        // Reset the authenticated current user so it can't leak across tests.
        $this->resetCurrentUser();

        $this->admin = $this->targetUser = $this->site = null;
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * Finding #1 / #2: an unauthenticated request is rejected and action()
     * never runs (no relation written, no data disclosed).
     */
    public function testUnauthenticatedRequestIsRejected(): void
    {
        $this->createSiteFixture();
        $this->createTargetUserFixture();
        $this->resetCurrentUser(); // ensure anonymous

        $ctrl = $this->makeController([
            'siteId'  => $this->site['site_id'],
            'user_id' => $this->targetUser['user_id'],
        ]);
        $data = $ctrl->doAction();

        // notAuthenticatedAction() routes to the restApi view with a 401 error.
        $this->assertArrayNotHasKey('response', $data,
            'Unauthenticated request must not produce a success response.');
        $this->assertSame('base.restApi', $data['view'] ?? null,
            'Unauthenticated REST request should route to the restApi error view.');

        $this->assertRelationRowCount(0,
            'No site_user relation may be written for an unauthenticated request.');
    }

    /**
     * Finding #2 (regression): a legitimate authenticated admin request still
     * succeeds under owa_adminController -- the CLI-only parent would have
     * exited before ever reaching here.
     * Finding #1 (disclosure): api_key / password / temp_passkey are absent
     * from the response body.
     */
    public function testAuthenticatedAdminRequestSucceedsAndHidesSecrets(): void
    {
        $this->createSiteFixture();
        $this->createTargetUserFixture();
        $this->authenticateAsAdmin();

        $ctrl = $this->makeController([
            'siteId'  => $this->site['site_id'],
            'user_id' => $this->targetUser['user_id'],
        ]);
        $data = $ctrl->doAction();

        $this->assertArrayHasKey('response', $data,
            'Authenticated admin request should produce a response (proves the '
            . 'controller is reachable under owa_adminController).');

        $allowedUser = $data['response']['allowed_user'] ?? [];
        $this->assertNotEmpty($allowedUser, 'Response should include the allowed_user record.');

        foreach (['api_key', 'password', 'temp_passkey'] as $secret) {
            $this->assertArrayNotHasKey($secret, $allowedUser,
                "Response must not disclose the user's {$secret}.");
        }

        // The relation was actually created.
        $this->assertRelationRowCount(1,
            'A site_user relation should be written for a valid authenticated request.');
    }

    /**
     * Finding #4: a bogus siteId (site does not exist) is rejected by
     * validation and NEVER writes a site_user relation row.
     */
    public function testBogusSiteIdIsRejectedAndWritesNoRelation(): void
    {
        $this->createSiteFixture();       // real site (for relation-count scoping)
        $this->createTargetUserFixture(); // real user
        $this->authenticateAsAdmin();

        $ctrl = $this->makeController([
            'siteId'  => 'this-site-id-does-not-exist-' . $this->tok,
            'user_id' => $this->targetUser['user_id'],
        ]);
        $data = $ctrl->doAction();

        $this->assertArrayHasKey('validation_errors', $data,
            'A non-existent siteId should fail entityExists validation.');
        $this->assertArrayNotHasKey('response', $data,
            'A rejected request should not emit a success response payload.');

        // No relation for the (real) site, and none anywhere for the target user.
        $this->assertRelationRowCount(0,
            'A bogus siteId must not write any site_user relation.');
        $this->assertUserRelationRowCount(0,
            'A bogus siteId must not write a relation for the target user.');
    }

    /**
     * Finding #4: a bogus user_id is likewise rejected and writes nothing.
     */
    public function testBogusUserIdIsRejectedAndWritesNoRelation(): void
    {
        $this->createSiteFixture();
        $this->authenticateAsAdmin();

        $ctrl = $this->makeController([
            'siteId'  => $this->site['site_id'],
            'user_id' => 'no-such-user-' . $this->tok,
        ]);
        $data = $ctrl->doAction();

        $this->assertArrayHasKey('validation_errors', $data,
            'A non-existent user_id should fail entityExists validation.');
        $this->assertArrayNotHasKey('response', $data);
        $this->assertRelationRowCount(0,
            'A bogus user_id must not write any site_user relation.');
    }

    // ---------------------------------------------------------------------
    // Fixtures & helpers
    // ---------------------------------------------------------------------

    private function makeController($params)
    {
        require_once(OWA_BASE_MODULE_DIR . 'controllers/siteAddAllowedUserRestController.php');
        return new owa_siteAddAllowedUserRestController($params);
    }

    private function createSiteFixture(): void
    {
        $domain = 'https://owatest-' . $this->tok . '.example.com';
        $sm = owa_coreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite($domain, 'OWA Test Site ' . $this->tok);
        $this->assertNotEmpty($site, 'Failed to create site fixture.');

        $this->site = [
            'id'      => $site->get('id'),
            'site_id' => $site->get('site_id'),
        ];
    }

    private function createTargetUserFixture(): void
    {
        $this->targetUser = $this->createUser('viewer');
    }

    private function authenticateAsAdmin(): void
    {
        $this->admin = $this->createUser('admin');

        $adminEntity = owa_coreAPI::entityFactory('base.user');
        $adminEntity->load($this->admin['id'], 'id');

        // Mirror owa_auth::authByApiKey()'s success path.
        $cu = owa_coreAPI::getCurrentUser();
        $cu->loadNewUserByObject($adminEntity);
        $cu->setAuthStatus(true);
    }

    /** @return array{id:?int,user_id:string} */
    private function createUser(string $role): array
    {
        $user_id = $role . '-' . $this->tok . '@owatest.example.com';
        $u = owa_coreAPI::entityFactory('base.user');
        $u->createNewUser($user_id, $role, 'x' . $this->tok, $user_id, 'OWA Test ' . $role);
        // Reload to get the assigned primary key.
        $u->load($user_id, 'user_id');
        $this->assertNotEmpty($u->get('id'), "Failed to create {$role} user fixture.");

        return ['id' => $u->get('id'), 'user_id' => $user_id];
    }

    private function resetCurrentUser(): void
    {
        $anon = owa_coreAPI::entityFactory('base.user');
        $anon->set('user_id', '');
        $anon->set('role', 'everyone');
        $cu = owa_coreAPI::getCurrentUser();
        $cu->loadNewUserByObject($anon);
        // Leave auth status false (anonymous).
        if (method_exists($cu, 'setAuthStatus')) {
            // no explicit "false" setter; a fresh anon object is unauthenticated,
            // but force the flag off via reflection if the singleton retained it.
            $ref = new \ReflectionObject($cu);
            if ($ref->hasProperty('is_authenticated')) {
                $p = $ref->getProperty('is_authenticated');
                $p->setAccessible(true);
                $p->setValue($cu, false);
            }
        }
    }

    private function assertRelationRowCount(int $expected, string $msg): void
    {
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_site_user');
        $db->selectColumn('*');
        $db->where('site_id', $this->site['id']);
        $rows = $db->getAllRows();
        $count = is_array($rows) ? count($rows) : 0;
        $this->assertSame($expected, $count, $msg);
    }

    private function assertUserRelationRowCount(int $expected, string $msg): void
    {
        if (empty($this->targetUser['id'])) {
            return;
        }
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_site_user');
        $db->selectColumn('*');
        $db->where('user_id', $this->targetUser['id']);
        $rows = $db->getAllRows();
        $count = is_array($rows) ? count($rows) : 0;
        $this->assertSame($expected, $count, $msg);
    }

    private function safeDelete(string $entity, $value, string $col): void
    {
        try {
            $e = owa_coreAPI::entityFactory($entity);
            $e->delete($value, $col);
        } catch (\Throwable $ex) {
            // best-effort cleanup
        }
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
