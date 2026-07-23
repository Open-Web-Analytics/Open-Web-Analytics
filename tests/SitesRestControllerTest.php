<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the tracked-sites REST endpoints:
 *
 *   GET  /owa/api/base/v1/sites   -> owa_sitesRestController   (view_site_list)
 *   POST /owa/api/base/v1/sites   -> owa_addSiteRestController  (edit_sites + nonce)
 *
 * GET returns the roster the current user is allowed to see; the roster is
 * unscoped for admins/anon-would-be-rejected and scoped to assigned sites for
 * non-admin roles. POST creates a site and returns its properties.
 */
final class SitesRestControllerTest extends RestControllerTestCase
{
    // ------------------------------------------------------------------
    // GET /sites
    // ------------------------------------------------------------------

    public function testGetSitesRejectsUnauthenticated(): void
    {
        $this->makeSite();

        $resp = $this->callEndpoint(
            'owa_sitesRestController',
            'sitesRestController.php',
            []
        );

        $this->assertNotAuthenticated($resp, 'GET /sites');
    }

    public function testGetSitesReturnsRosterForAdmin(): void
    {
        $site = $this->makeSite();
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_sitesRestController',
            'sitesRestController.php',
            []
        );

        $this->assertSame(200, $resp['status'], 'GET /sites should return 200 for an admin.');
        $this->assertSame('base.sitesRest', $resp['view']);
        $this->assertIsArray($resp['data'], 'The roster payload should be an array.');

        // The freshly created site must appear in the admin roster, keyed by site_id.
        $this->assertArrayHasKey($site['site_id'], $resp['data'],
            'The created site should be present in the admin roster.');
    }

    public function testGetSitesIsScopedForNonAdmin(): void
    {
        // A site the viewer is NOT assigned to.
        $unassigned = $this->makeSite('unassigned');
        $this->authenticateAs('viewer');

        $resp = $this->callEndpoint(
            'owa_sitesRestController',
            'sitesRestController.php',
            []
        );

        $this->assertSame(200, $resp['status'],
            'A viewer has view_site_list, so GET /sites should still return 200.');
        $this->assertIsArray($resp['data']);

        // A viewer with no assigned sites sees an empty roster -- and in
        // particular must NOT see the site they were never granted.
        $this->assertArrayNotHasKey($unassigned['site_id'], $resp['data'],
            'A non-admin must not see sites they have not been assigned.');
    }

    // ------------------------------------------------------------------
    // POST /sites
    // ------------------------------------------------------------------

    public function testPostSitesRejectsUnauthenticated(): void
    {
        $domain = 'https://owatest-post-anon-' . $this->tok . '.example.com';

        $resp = $this->callEndpoint(
            'owa_addSiteRestController',
            'addSiteRestController.php',
            ['protocol' => '', 'domain' => $domain, 'name' => 'anon site']
        );

        $this->assertNotAuthenticated($resp, 'POST /sites');
        $this->assertFalse($this->siteDomainExists($domain),
            'An unauthenticated POST must not create a site.');
    }

    public function testPostSitesCreatesSite(): void
    {
        $this->authenticateAs('admin');
        $domain = 'https://owatest-post-' . $this->tok . '.example.com';

        // Register cleanup by domain up front (the site is created by the
        // controller, not makeSite()) so a mid-request throw can't orphan it.
        $this->trackForCleanup('base.site', $domain, 'domain');

        $resp = $this->callEndpoint(
            'owa_addSiteRestController',
            'addSiteRestController.php',
            ['protocol' => '', 'domain' => $domain, 'name' => 'OWA Test POST ' . $this->tok]
        );

        $this->assertSame(201, $resp['status'], 'A valid POST /sites should return 201.');
        $this->assertIsArray($resp['data']);
        $this->assertSame($domain, $resp['data']['domain'] ?? null,
            'The response should echo the created site domain.');

        $this->assertTrue($this->siteDomainExists($domain), 'The site should have been persisted.');
    }

    public function testPostSitesRejectsDuplicateDomain(): void
    {
        $site = $this->makeSite('dup');
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_addSiteRestController',
            'addSiteRestController.php',
            ['protocol' => '', 'domain' => $site['domain'], 'name' => 'dup attempt']
        );

        $this->assertSame(422, $resp['status'],
            'A duplicate domain should fail entityDoesNotExist validation with 422.');
        $this->assertNotEquals(201, $resp['status']);
    }

    // ------------------------------------------------------------------

    private function siteDomainExists(string $domain): bool
    {
        $s = owa_coreAPI::entityFactory('base.site');
        $s->load($domain, 'domain');
        return !empty($s->get('id'));
    }
}
