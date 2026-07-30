<?php

use PHPUnit\Framework\TestCase;

/**
 * Shared base for the documented CLI command tests
 * (commands registered in modules/base/module.php:537-552, invoked via
 * `php cli.php cmd=<name> arg=val ...`).
 *
 * These drive the same controller lifecycle cli.php does -- boot owa in the
 * 'admin_cli' instance role, set request_mode to 'cli', resolve the command
 * name to its controller class through the service registry, then run the
 * controller's doAction(). No shell process is spawned; we call the controller
 * directly, exactly as the REST tests (RestControllerTestCase) do.
 *
 * Auth model: the CLI is role-based, not api-key based. cli.php calls
 * $owa->setCurrentUser('admin', 'cli-user'); we mirror that with
 * authenticateAs($role) so capability gates (owa_controller::doAction ->
 * checkCapabilityAndAuthenticateUser) can be exercised for under-privileged
 * roles.
 *
 * Cleanup: every fixture row (users, sites) is removed in tearDown regardless
 * of assertion outcome. Tests skip cleanly when the OWA database is unreachable.
 */
abstract class CliControllerTestCase extends TestCase
{
    /** @var string unique per-test suffix so repeat/parallel runs never collide */
    protected $tok;

    /** @var array<int, array{entity:string, value:mixed, col:string}> rows to delete */
    private $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('OWA_TEST_CLI_BOOTSTRAPPED')) {
            define('OWA_TEST_CLI_BOOTSTRAPPED', true);

            $owa_root = dirname(__DIR__) . '/';

            // cli.php sets these before booting; some code paths read them.
            $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Open Web Analytics CLI test';
            $_SERVER['REMOTE_ADDR']     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            // OWA_CLI is normally derived from php_sapi_name(); under phpunit the
            // SAPI is 'cli' anyway, but a few controllers key off argc.
            $_SERVER['argc'] = $_SERVER['argc'] ?? 1;

            require_once($owa_root . 'owa.php');
            // cli.php requires the CLI controller base before any command loads;
            // the individual command files do not require it themselves.

            $GLOBALS['owa_test_cli_instance'] = new owa([
                'instance_role' => 'admin_cli',
            ]);
        }

        owa_coreAPI::setSetting('base', 'request_mode', 'cli');
    }

    protected function setUp(): void
    {
        if (!$this->dbAvailable()) {
            $this->markTestSkipped('OWA database not reachable; skipping CLI command test.');
        }
        $this->tok = substr(md5(uniqid('owacli', true)), 0, 12);
        // Start every test as an authenticated admin, matching cli.php's
        // setCurrentUser('admin', ...). Individual tests drop privileges when
        // exercising a capability gate.
        $this->authenticateAs('admin');
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
    }

    // ---------------------------------------------------------------------
    // Auth (role-based, as the CLI uses)
    // ---------------------------------------------------------------------

    /**
     * Set the current user's role and mark it authenticated -- the CLI runs as
     * a synthetic user, so a role is all the capability checks need.
     */
    protected function authenticateAs(string $role): void
    {
        $cu = owa_coreAPI::getCurrentUser();
        $cu->setRole($role);
        $cu->setAuthStatus(true);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * Create a user with the given role. Returns [id, user_id].
     *
     * @return array{id:mixed, user_id:string}
     */
    protected function makeUser(string $role, string $label = 'u', string $password = null): array
    {
        $user_id  = $role . '-' . $label . '-' . $this->tok . '@owatest.example.com';
        $password = $password ?? ('pw' . $this->tok);
        $u = owa_coreAPI::entityFactory('base.user');
        $u->createNewUser($user_id, $role, $password, $user_id, 'OWA CLI Test ' . $role);
        $u->load($user_id, 'user_id');
        $this->assertNotEmpty($u->get('id'), "Failed to create {$role} user fixture.");

        $this->trackForCleanup('base.user', $u->get('id'), 'id');

        return [
            'id'      => $u->get('id'),
            'user_id' => $user_id,
        ];
    }

    protected function trackForCleanup(string $entity, $value, string $col = 'id'): void
    {
        $this->cleanup[] = ['entity' => $entity, 'value' => $value, 'col' => $col];
    }

    // ---------------------------------------------------------------------
    // Driving a command
    // ---------------------------------------------------------------------

    /**
     * Resolve a registered command name to its controller class through the
     * service registry -- proves the command is actually wired up, the way
     * cli.php resolves it.
     */
    protected function commandClass(string $cmd): ?string
    {
        $s = owa_coreAPI::serviceSingleton();
        $s->loadCliCommands();
        $module_class = $s->getCliCommandClass($cmd); // e.g. 'base.flushCacheCli'
        return $module_class ?: null;
    }

    /**
     * Run a CLI command by its controller file + class and return a normalized
     * result:
     *   ['view' => ?string, 'data' => array]
     *
     * $controllerFile is resolved relative to modules/base/ for the common case
     * (that is where the CLI controllers live, NOT in controllers/); a path with
     * a leading slash is used verbatim, and a value containing '/' is treated as
     * relative to modules/base/ (e.g. 'controllers/resetSecretsCli.php').
     *
     * @return array{view:?string, data:array}
     */
    protected function runCommand(string $class, string $controllerFile, array $params): array
    {
        // Controllers autoload by name (compat bridge + Composer PSR-4); only fall
        // back to an explicit require if the class is somehow not yet defined. The
        // legacy module-relative path no longer exists post-PSR-4, so this require
        // must stay guarded.
        if (!class_exists($class)) {
            $path = ($controllerFile[0] === '/')
                ? $controllerFile
                : OWA_BASE_MODULE_DIR . $controllerFile;
            require_once($path);
        }

        $ctrl = new $class($params);
        $data = $ctrl->doAction();
        $data = is_array($data) ? $data : [];

        return [
            'view' => $data['view'] ?? null,
            'data' => $data,
        ];
    }

    // ---------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------

    /**
     * Assert a command rejected an under-privileged (but authenticated) user.
     * The admin controller stack routes a failed capability check to the
     * 'base.error' view (authenticatedButNotCapableAction).
     */
    protected function assertNotCapable(array $result, string $context = ''): void
    {
        $this->assertSame('base.error', $result['view'],
            "An under-privileged user should be routed to the error view. {$context}");
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
