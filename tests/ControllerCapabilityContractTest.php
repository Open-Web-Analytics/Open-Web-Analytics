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

        // CLI-only. Core\Controller\Cli::__construct() exits unless
        // request_mode === 'cli', so these are not web-reachable.
        'FlushCacheCli',
        'InstallCli',
        'UpdatesApplyCli',

        // Public/embeddable UI surfaces.
        'OverlayLauncher',
        'WidgetOwaNews',

        // The "your schema is out of date" notice. Core\Controller::updateAction()
        // redirects here as part of that interception, so the page has to be
        // able to render as the destination of that redirect -- declaring a
        // capability would put a login wall in front of the notice it exists
        // to show. It lists module names only; base.updatesApply, which
        // applies the updates, declares its own.
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
            "A controller's authorization is declared by setRequiredCapability(),\n"
            . "and a controller that declares nothing has nothing enforced.\n\n"
            . "If you added a controller, either declare a capability in its\n"
            . "constructor (see ModuleActivate) or add it to INTENTIONALLY_PUBLIC\n"
            . "with the reason it does not need one. Do not use 'install_schema'\n"
            . "as a gate -- the 'everyone' role holds it, so it authorizes nothing."
        );
    }

    /**
     * base.updatesApply applies module schema updates, so an admin-only
     * capability is the floor. Pinned separately from the list above because
     * "declares something" is not enough here -- it has to declare a
     * capability the admin role alone holds.
     *
     * Its sibling base.updates declares nothing, deliberately -- see the entry
     * in INTENTIONALLY_PUBLIC. Only the applying action needs the capability,
     * so the notice page still renders.
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
