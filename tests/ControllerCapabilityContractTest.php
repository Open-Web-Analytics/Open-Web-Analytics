<?php

use PHPUnit\Framework\TestCase;

/**
 * "Every non-public controller declares a capability" contract test.
 *
 * WHY THIS EXISTS
 * ---------------
 * Core/Controller::checkCapabilityAndAuthenticateUser() is the ONLY
 * authorization gate in the controller layer, and it is opt-in:
 *
 *     if ( ( !empty($capability) && ! isEveryoneCapable($capability) )
 *          || ( getStateParam('u') && getStateParam('p') ) ) { ...authenticate... }
 *     return true;
 *
 * A controller that never calls setRequiredCapability() has $capability === null,
 * so the whole block is skipped and the action runs unauthenticated. That is
 * correct and necessary for the tracker, login and install endpoints -- but it
 * means forgetting the declaration on an admin controller silently ships an
 * unauthenticated admin action, with nothing to catch it.
 *
 * That is exactly what happened to base.updatesApply, which executed module
 * schema updates with no capability, no nonce and no auth check of any kind.
 *
 * This test freezes the set of controllers with no effective capability. Adding
 * a controller without a declaration turns it red.
 *
 * Being on that list is not automatically a bug -- the update-notice page
 * (base.updates) has to stay reachable pre-auth because the schema-out-of-date
 * interception redirects to it before any capability check runs. The point is
 * that every entry is a deliberate, written-down decision rather than an
 * omission nobody noticed.
 *
 * NOTE ON 'install_schema': it is granted to the "everyone" role, so declaring
 * it does NOT gate anything -- isEveryoneCapable() short-circuits the check.
 * It is the correct choice for the install flow and the wrong choice anywhere
 * else. Controllers that declare it are therefore out of scope here.
 *
 * Maintenance contract: this list may only SHRINK, or grow with a written
 * justification for why the new controller must be reachable unauthenticated.
 * Never add an entry merely to make the test pass.
 */
final class ControllerCapabilityContractTest extends TestCase
{
    /**
     * Controllers that are legitimately reachable without authentication.
     * Every entry needs a reason.
     */
    private const INTENTIONALLY_PUBLIC = [
        // Tracker / ingestion -- hit directly by visitors' browsers.
        'ProcessEvent',
        'ProcessRequest',
        'ProcessFirstRequest',
        'NotifyNewSession',

        // Authentication entry points -- must be reachable while logged out.
        'Login',
        'LoginForm',
        'Logout',

        // Password reset / account setup. Guarded by the emailed temp_passkey
        // (Auth::authenticateUserTempPasskey), which is the credential -- a
        // capability check would make the flow impossible.
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

        // CLI-only. Core\Controller\Cli::__construct() exits unless
        // request_mode === 'cli', so these are not web-reachable.
        'FlushCacheCli',
        'InstallCli',
        'UpdatesApplyCli',

        // Public/embeddable UI surfaces.
        'OverlayLauncher',
        'WidgetOwaNews',

        // The "your schema is out of date" notice. Core\Controller::updateAction()
        // redirects here, and that interception happens BEFORE the capability
        // check on a possibly-unauthenticated request -- so gating this would
        // put a login wall in front of the page the interception exists to
        // show. It only lists module names; base.updatesApply, which actually
        // mutates the schema, is gated instead.
        'Updates',

        // Not a controller at all -- a View (extends Core\View\RestApi)
        // misfiled into a Controller/ directory. Harmless here; worth
        // relocating on its own merits.
        'DomstreamsRestView',
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
            "A controller's authorization is declared by setRequiredCapability().\n"
            . "Controllers with no declaration run UNAUTHENTICATED.\n\n"
            . "If you added a controller, either declare a capability in its\n"
            . "constructor (see ModuleActivate) or add it to INTENTIONALLY_PUBLIC\n"
            . "with a reason. Do not use 'install_schema' as a gate -- the\n"
            . "'everyone' role holds it, so it authorizes nothing."
        );
    }

    /**
     * base.updatesApply executes module schema updates. Pinned explicitly
     * because it is the case that motivated this test.
     *
     * Its sibling base.updates is intentionally left ungated -- see the entry
     * in INTENTIONALLY_PUBLIC. Only the mutating action is gated, so the
     * pre-auth update notice still renders.
     */
    public function testUpdatesApplyRequiresAnAdminOnlyCapability(): void
    {
        $classes = $this->scanClasses();

        // Capabilities held ONLY by the admin role (Settings.php 'capabilities').
        // 'install_schema' is excluded on purpose: the 'everyone' role holds it.
        $adminOnly = ['edit_settings', 'edit_sites', 'edit_users', 'edit_modules'];

        $cap = $this->effectiveCapability('UpdatesApply', $classes);

        $this->assertNotNull($cap, 'UpdatesApply must declare a capability.');
        $this->assertContains(
            $cap,
            $adminOnly,
            "UpdatesApply must require an admin-only capability; '$cap' is "
            . 'held by a non-admin role, so it would not gate the action.'
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
