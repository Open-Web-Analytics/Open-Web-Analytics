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

    /**
     * A repeated domain is a SECOND Observation Profile of the same website,
     * not an error.
     *
     * This returned 422. The rule behind it dated to 2009, when a site's
     * identity was md5( domain ) and two sites for one domain were literally
     * the same row -- so the domain had to be unique. Identity is minted now,
     * and the uniqueness check had outlived its reason into forbidding exactly
     * what Properties were introduced to allow: nextProfileName() numbers
     * Profiles "Observation Profile 1", "2", "3", and that counter could never
     * reach 2 while this rejected the request.
     *
     * It also did not exclude ARCHIVED Profiles, so archiving one made its
     * domain permanently unusable -- the row kept to make deletion recoverable
     * was what blocked starting over.
     *
     * Deliberate contract change, and a safe one to make: the endpoint has no
     * known consumer. The WordPress plugin only LISTS sites -- its own settings
     * text says new websites are added through the OWA admin interface.
     */
    public function testPostSitesAcceptsARepeatedDomainAsAnotherProfile(): void
    {
        $site = $this->makeSite('dup');
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_addSiteRestController',
            'addSiteRestController.php',
            ['protocol' => '', 'domain' => $site['domain'], 'name' => 'second profile']
        );

        $this->assertSame(201, $resp['status'],
            'A second Observation Profile for a website was refused, which is the case '
            . 'the Property tier exists to support.');

        $this->assertNotSame(
            $site['site_id'], $resp['data']['site_id'] ?? $site['site_id'],
            'The second Profile reused the first one\'s identifier.');
    }

    // ------------------------------------------------------------------

    private function siteDomainExists(string $domain): bool
    {
        $s = owa_coreAPI::entityFactory('base.site');
        $s->load($domain, 'domain');
        return !empty($s->get('id'));
    }
}
