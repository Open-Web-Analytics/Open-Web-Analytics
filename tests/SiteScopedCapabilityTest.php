<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A capability that requires site access is granted only for a site the user
 * is assigned (ServiceUser::isCapable() through isSiteAccessible()).
 *
 * Nothing tested this answer: the capability tests covered the role lists and
 * the site-access list, and they all passed with isSiteAccessible() deleted
 * outright -- which a dead-code pass then nearly did.
 */
final class SiteScopedCapabilityTest extends TestCase
{
    private function viewerAssignedTo(array $siteIds): \OWA\Module\Base\Classes\ServiceUser
    {
        $user = new \OWA\Module\Base\Classes\ServiceUser();
        $user->setRole('viewer');

        $assigned = new ReflectionProperty($user, 'assignedSites');
        $assigned->setAccessible(true);
        $assigned->setValue($user, array_fill_keys($siteIds, true));

        $loaded = new ReflectionProperty($user, 'isAssignedSitesListLoaded');
        $loaded->setAccessible(true);
        $loaded->setValue($user, true);

        return $user;
    }

    public function testASiteGatedCapabilityHoldsOnlyForAnAssignedSite(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('roles are read from the settings store');
        }

        $this->assertContains('view_reports', (array) \OWA\Core\CoreAPI::getSetting('base', 'capabilitiesThatRequireSiteAccess'),
            'precondition: view_reports is gated by site access');

        $user = $this->viewerAssignedTo(['alice-site']);
        $this->assertContains('view_reports', (array) $user->capabilities, 'precondition: a viewer may view reports');

        $this->assertTrue($user->isCapable('view_reports', 'alice-site'), 'an assigned site');
        $this->assertFalse($user->isCapable('view_reports', 'bob-site'), 'a site the user is not assigned');
    }

    public function testAnAdminNeedsNoAssignment(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('roles are read from the settings store');
        }

        $admin = $this->viewerAssignedTo([]);
        $admin->setRole('admin');

        $this->assertTrue($admin->isCapable('view_reports', 'bob-site'));
    }

    public function testNoSiteIdIsRefusedRatherThanGuessed(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('roles are read from the settings store');
        }

        $this->expectException(\InvalidArgumentException::class);

        $this->viewerAssignedTo(['alice-site'])->isCapable('view_reports');
    }
}
