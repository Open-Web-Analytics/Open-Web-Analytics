<?php

use PHPUnit\Framework\TestCase;

/**
 * "Every non-public controller declares a capability" contract test.
 *
 * WHY THIS EXISTS
 * ---------------
 * Authorization in the controller layer is DECLARED, not inherited:
 * Core/Controller::checkCapabilityAndAuthenticateUser() enforces whatever
 * setRequiredCapability() named, and a controller that names nothing has
 * nothing to enforce. That is correct and necessary for the tracker, login
 * and install endpoints, which have to work before anyone is logged in.
 *
 * The risk is a declaration being FORGOTTEN on an admin controller rather than
 * deliberately omitted. By inspection the two look identical, and nothing in
 * the framework distinguishes them.
 *
 * So this test freezes the set. Every controller without a declaration has to
 * be named below with a written reason why it does not need one. A new
 * controller without one turns the test red; an entry added purely to make it
 * pass defeats the point of having it.
 *
 * Being on the list is not itself a finding. Most entries are protected by
 * something other than a capability -- a credential the request carries, the
 * CLI boundary, or a check the controller performs itself -- and the reason
 * against each says which.
 *
 * NOTE ON 'install_schema': it is granted to the "everyone" role, so declaring
 * it does NOT gate anything -- isEveryoneCapable() short-circuits the check.
 * It is the correct choice for the install flow and the wrong choice anywhere
 * else. Controllers that declare it are therefore out of scope here.
 *
 * Maintenance contract: this list may only SHRINK, or grow with a written
 * justification for why the new controller cannot declare a capability.
 * Never add an entry merely to make the test pass.
 */
final class ControllerCapabilityContractTest extends TestCase
{
    /**
     * Controllers that do not declare a capability, each with the reason it
     * does not need one. Every entry needs a reason.
     */
    private const INTENTIONALLY_PUBLIC = [
        // Tracker / ingestion -- called directly by visitors' browsers, so
        // they cannot require a login by definition.
        'ProcessEvent',
        'ProcessRequest',
        'ProcessFirstRequest',
        'NotifyNewSession',

        // Authentication entry points -- these are how a session is
        // established in the first place.
        'Login',
        'LoginForm',
        'Logout',

        // Password reset / account setup. The request carries its own
        // credential (the emailed reset token), and that is what authorizes
        // it; a capability check would make the flow impossible.
        'PasswordResetForm',
        'PasswordResetRequest',
        'UsersChangePassword',
        'UsersResetPassword',
        'UsersSetPassword',
        'UsersPasswordEntry',
        'UsersNewAccount',

        // REST plumbing. ApiRequest runs the same capability check itself
        // before dispatching; CorsPreflight answers OPTIONS only.
        'ApiRequest',
        'CorsPreflight',

        // Report dispatch, and the same shape as ApiRequest above: it resolves
        // a reportId to a report and hands the request on, delegating at
        // doAction() -- which is where checkCapabilityAndAuthenticateUser()
        // lives, so the TARGET report's own requirement is what runs.
        //
        // Declaring one here as well would mean a report could be gated by two
        // different answers, with the stricter winning by accident rather than
        // by decision. Resolution is not the thing being authorized; the report
        // is, and each report already says what it needs.
        //
        // Pinned by ReportRegistryTest, which fails if delegation ever moves to
        // action() and skips the target's check.
        'Report',

        // CLI-only. Core\Controller\Cli::__construct() exits unless
        // request_mode === 'cli', so these are not web-reachable.
        'FlushCacheCli',
        'InstallCli',
        'UpdatesApplyCli',
        // Runs from the scheduler and the shell only, like the others here.
        'NotificationsFetchCli',

        // Public/embeddable UI surfaces.
        'OverlayLauncher',

        // The "your schema is out of date" notice. Core\Controller::updateAction()
        // redirects here as part of that interception, so the page has to be
        // able to render as the destination of that redirect -- declaring a
        // capability would put a login wall in front of the notice it exists
        // to show. It lists module names only; base.updatesApply, which
        // applies the updates, is public for the same reason.
        'Updates',
        // Applying those updates. Public deliberately: the schema has to be
        // brought forward before the rest of the application can be relied on,
        // and the authentication path is part of what may be waiting on it.
        // Gating it made the documented upgrade unusable for a signed-out admin
        // (#979) -- base.updates renders anonymously, so its Apply link carried a
        // nonce minted with no user_id, and createNonce() binds to user_id, so it
        // could never verify once they signed in.
        //
        // WordPress takes the same position: wp-admin/upgrade.php loads
        // wp-load.php rather than admin.php and runs wp_upgrade() with no
        // capability check and no nonce. The control is that the work is
        // idempotent and does nothing unless the schema is actually behind.
        'UpdatesApply',

    ];

    public function testEveryControllerEitherDeclaresACapabilityOrIsKnownPublic(): void
    {
        $classes = $this->scanClasses();

        $undeclared = [];
        foreach ($classes as $name => $meta) {
            if (! $meta['is_controller']) {
                continue;
            }
            if ($this->effectiveCapability($name, $classes) === null) {
                $undeclared[] = $name;
            }
        }
        sort($undeclared);

        $expected = self::INTENTIONALLY_PUBLIC;
        sort($expected);

        $this->assertSame(
            $expected,
            $undeclared,
            "A controller's authorization is declared by setRequiredCapability(),\n"
            . "and a controller that declares nothing has nothing enforced.\n\n"
            . "If you added a controller, either declare a capability in its\n"
            . "constructor (see ModuleActivate) or add it to INTENTIONALLY_PUBLIC\n"
            . "with the reason it does not need one. Do not use 'install_schema'\n"
            . "as a gate -- the 'everyone' role holds it, so it authorizes nothing."
        );
    }

    /**
     * base.updatesApply declares NO capability and NO nonce, deliberately.
     *
     * Pinned as its own test because it reverses an earlier decision and the
     * reasoning has to survive: the schema must be brought forward before the
     * rest of the application can be relied on, and the authentication path is
     * part of what may be waiting on it. Gating it made the documented upgrade
     * unusable for a signed-out admin -- reported as #979 -- because
     * base.updates renders anonymously, so its Apply link carried a nonce minted
     * with no user_id, and createNonce() binds to user_id, so that nonce could
     * never verify once they signed in. The request was turned away and correct
     * credentials appeared to be rejected.
     *
     * WordPress takes the same position for the equivalent step:
     * wp-admin/upgrade.php loads wp-load.php rather than admin.php and calls
     * wp_upgrade() with no capability check and no nonce.
     *
     * Re-gating it would reintroduce that flow, so this fails rather than lets
     * it happen quietly.
     */
    public function testUpdatesApplyIsDeliberatelyUngated(): void
    {
        $classes = $this->scanClasses();

        $this->assertNull(
            $this->effectiveCapability('UpdatesApply', $classes),
            'base.updatesApply must not declare a capability -- see #979. Gating it '
            . 'makes the upgrade unusable for a signed-out admin, because the Apply '
            . 'link on the anonymous notice page carries a nonce that can never verify.'
        );

        $source = (string) file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/UpdatesApply.php'
        );

        $this->assertStringNotContainsString(
            'setNonceRequired',
            $source,
            'base.updatesApply must not require a nonce: the notice page it is reached '
            . 'from renders anonymously, so the nonce is minted without a user_id and '
            . 'cannot verify afterwards.'
        );
    }

    /**
     * Static scan: class name => [parent, declared capability, is_controller].
     * No framework boot -- this is a source-level contract.
     */
    private function scanClasses(): array
    {
        $root = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/modules/*/Controller/*.php') ?: [],
            glob($root . '/Core/*.php') ?: [],
            glob($root . '/Core/Controller/*.php') ?: []
        );

        $classes = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }

            if (! preg_match('/^\s*(?:abstract\s+)?class\s+(\w+)(?:\s+extends\s+([\\\\\w]+))?/m', $src, $m)) {
                continue;
            }

            $name   = $m[1];
            $parent = isset($m[2]) ? substr(strrchr('\\' . $m[2], '\\'), 1) : '';

            // Only real CALLS on $this -- never the method definition in the
            // base class, and never a variable argument.
            $capability = null;
            if (preg_match_all('/->setRequiredCapability\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $src, $calls)) {
                $capability = $calls[1][0];
            }

            $classes[$name] = [
                'parent'        => $parent,
                'capability'    => $capability,
                'is_controller' => strpos($file, '/Controller/') !== false
                                   && strpos($file, '/modules/') !== false,
            ];
        }

        // The base class defines setRequiredCapability(); it grants nothing.
        if (isset($classes['Controller'])) {
            $classes['Controller']['capability'] = null;
        }

        return $classes;
    }

    /** Walk the inheritance chain for the first declared capability. */
    private function effectiveCapability(string $name, array $classes, array $seen = []): ?string
    {
        if (! isset($classes[$name]) || isset($seen[$name])) {
            return null;
        }
        $seen[$name] = true;

        return $classes[$name]['capability']
            ?? $this->effectiveCapability($classes[$name]['parent'], $classes, $seen);
    }
}
