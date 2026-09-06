<?php

/*
 * The full framework, the way CapabilitiesConfigFileOnlyTest loads it.
 *
 * Reading a setting or the action map spins up the service singleton, which
 * needs OWA_BASE_DIR -- defined by owa_env.php, which this bootstrap loads.
 * Without it these pass only when some earlier test in the same process has
 * already booted OWA, and fail when the file is run alone. CI runs an isolation
 * sweep that does exactly that.
 */
require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The signed-in user's own account screen.
 *
 * WHAT ONLY THESE TESTS CAN CHECK
 *
 * The capability shape. Whether editing your own address is admin-only, and
 * whether this screen is reachable by an analyst, are decisions expressed as
 * entries in one array in Settings.php -- so a change to that array is exactly
 * the change these assert against. The e2e suite drives the screen; this pins
 * what it is allowed to do.
 */
class MyProfileTest extends TestCase
{
    /** The shipped role -> capability map. */
    private function capabilities(): array
    {
        return (array) \OWA\Core\CoreAPI::getSetting('base', 'capabilities');
    }

    /**
     * Changing your own email address is a capability of its own.
     *
     * It is not edit_users: that is about other people's accounts, and an
     * analyst who could edit nobody's account still has one of their own. It is
     * not free either -- the address is where password resets are sent, so
     * changing it moves who can recover the account.
     */
    public function testChangingYourOwnEmailIsAdminOnlyByDefault(): void
    {
        $capabilities = $this->capabilities();

        $this->assertContains('edit_own_email', $capabilities['admin'],
            'an admin can change their own address');

        foreach (array('analyst', 'viewer', 'everyone') as $role) {

            $this->assertNotContains('edit_own_email', $capabilities[$role],
                sprintf('%s must not change their own address without being granted it', $role));
        }
    }

    /**
     * ...and it is not site-scoped.
     *
     * capabilitiesThatRequireSiteAccess is checked against ONE site. An account
     * is not held per site, so a capability about it listed there would be
     * asked an unanswerable question and refused for everyone.
     */
    public function testTheEmailCapabilityIsNotSiteScoped(): void
    {
        $this->assertNotContains(
            'edit_own_email',
            (array) \OWA\Core\CoreAPI::getSetting('base', 'capabilitiesThatRequireSiteAccess'));
    }

    /**
     * The screen is reachable by every signed-in role.
     *
     * view_site_list is what admin, analyst and viewer all carry and nothing
     * else does, so it is the capability that means "signed in". Gating this on
     * edit_settings -- which is what every other entry in the Installation nav
     * group uses -- would leave most users unable to change their own name.
     */
    public function testEverySignedInRoleCanReachTheScreen(): void
    {
        $capabilities = $this->capabilities();

        foreach (array('admin', 'analyst', 'viewer') as $role) {

            $this->assertContains('view_site_list', $capabilities[$role],
                sprintf('%s must be able to open their own account screen', $role));
        }
    }

    /** @dataProvider controllerCapabilityProvider */
    public function testTheControllersRequireThatCapability(string $class, string $expected): void
    {
        $controller = new $class(array());

        $this->assertSame($expected, $controller->getRequiredCapability());
    }

    public static function controllerCapabilityProvider(): array
    {
        return array(
            'the form' => array(\OWA\Module\Base\Controller\MyProfile::class, 'view_site_list'),
            'the save' => array(\OWA\Module\Base\Controller\MyProfileSave::class, 'view_site_list'),
        );
    }

    /**
     * The save is nonce-required.
     *
     * It writes to an account, so a link somebody follows must not be able to
     * rename them or start a password change on their behalf.
     */
    public function testTheSaveRequiresANonce(): void
    {
        $save = new \OWA\Module\Base\Controller\MyProfileSave(array());

        $this->assertTrue($save->is_nonce_required);
    }

    /**
     * Both actions are registered, or the screen is unreachable however it is
     * linked.
     */
    public function testBothActionsAreRegistered(): void
    {
        $actions = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap('actions');

        foreach (array('base.myProfile', 'base.myProfileSave') as $action) {

            $this->assertArrayHasKey($action, $actions,
                sprintf('%s must be registered, or the screen is unreachable', $action));

            $this->assertTrue(class_exists($actions[$action]['class_name']),
                sprintf('%s names a class that does not load', $action));
        }
    }
}
