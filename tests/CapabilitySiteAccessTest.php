<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * addCapabilityToRole()'s third argument was unreachable.
 *
 * The guard read
 *
 *     if ( ! $role === 'everyone' && $isSiteAccessRequired )
 *
 * which PHP parses as `(! $role) === 'everyone'` -- a boolean compared
 * identically against a string, so always false. The whole condition was
 * therefore always false and the body never ran: a capability could never be
 * added to `capabilitiesThatRequireSiteAccess`, whatever the caller asked for.
 *
 * Nothing in the tree passes `true` today, so the list is populated entirely
 * from the hard-coded default and the defect has never bitten. It would have
 * bitten the first caller that tried -- silently, by leaving a capability
 * ungated by site access while the code plainly says otherwise. That is the
 * kind of failure worth a test rather than a comment.
 *
 * The intent was `$role !== 'everyone'`: the 'everyone' role is the
 * unauthenticated one, and site access cannot be required of it.
 */
final class CapabilitySiteAccessTest extends TestCase
{
    use SettingsSingletonSnapshot;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
    }

    private function siteAccessList(): array
    {
        $list = $this->settings()->get('base', 'capabilitiesThatRequireSiteAccess');

        return is_array($list) ? $list : [];
    }

    /**
     * The defect: this asked for the capability to be site-access gated, and
     * before the fix the request was silently dropped.
     */
    public function testACapabilityCanBeAddedToTheSiteAccessList(): void
    {
        $c = $this->settings();

        $this->assertNotContains('test_gated_capability', $this->siteAccessList());

        $c->addCapabilityToRole('analyst', 'test_gated_capability', true);

        $this->assertContains(
            'test_gated_capability',
            $this->siteAccessList(),
            'a capability registered as site-access-required must land in the list'
        );
    }

    /**
     * Not requesting the gating leaves the list alone -- the fix must not
     * add everything.
     */
    public function testACapabilityIsNotGatedUnlessAsked(): void
    {
        $c = $this->settings();

        $c->addCapabilityToRole('analyst', 'test_ungated_capability');

        $this->assertNotContains('test_ungated_capability', $this->siteAccessList());
    }

    /**
     * The 'everyone' role is unauthenticated, so site access cannot be
     * required of it -- this is the case the broken guard was reaching for.
     */
    public function testTheEveryoneRoleNeverRequiresSiteAccess(): void
    {
        $c = $this->settings();

        $c->addCapabilityToRole('everyone', 'test_public_capability', true);

        $this->assertNotContains(
            'test_public_capability',
            $this->siteAccessList(),
            "site access cannot be required of the unauthenticated 'everyone' role"
        );
    }

    /**
     * The capability still reaches the role itself either way -- the guard
     * governs only the site-access list.
     */
    public function testTheCapabilityIsGrantedToTheRoleRegardless(): void
    {
        $c = $this->settings();

        $c->addCapabilityToRole('analyst', 'test_gated_capability', true);

        $caps = $c->get('base', 'capabilities');

        $this->assertContains('test_gated_capability', $caps['analyst']);
    }
}
