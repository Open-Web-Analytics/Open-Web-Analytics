<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The site selector groups Observation Profiles under their Property.
 *
 * Two Profiles of the same website legitimately share a domain and differ only
 * by an auto-assigned name, which the migration produces directly:
 *
 *     Observation Profile 1 | example.com
 *     Observation Profile 2 | example.com
 *
 * Flat, that says nothing about which site either belongs to. The Property name
 * is the missing context, and grouping supplies it the same way the dimension
 * picker groups dimensions by family.
 *
 * Grouping is PRESENTATION. The Profile's own name is not rewritten, because
 * that name is a published API field -- /v1/sites emits it and the WordPress
 * plugin labels its picker with it. SiteListContractTest holds that end.
 */
final class SiteSelectorGroupingTest extends TestCase
{
    /** @param array $sites site_id => entity */
    private function group( array $sites ): array
    {
        $controller = new \OWA\Module\Base\Controller\Sites( array() );

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'groupSitesByProperty' );
        $method->setAccessible( true );

        return (array) $method->invoke( $controller, $sites );
    }

    private function site( string $site_id, string $name, $property_id, string $domain = '' )
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->set( 'site_id', $site_id );
        $site->set( 'name', $name );
        $site->set( 'domain', $domain );

        if ( $property_id ) {
            $site->set( 'property_id', $property_id );
        }

        return $site;
    }

    public function testAnEmptyListGroupsToNothing(): void
    {
        $this->assertSame( array(), $this->group( array() ) );
    }

    /**
     * A Profile with no Property must still appear. It can exist -- created
     * before the migration, or by a path that assigns none -- and dropping it
     * would silently remove a site from the selector, which is worse than
     * showing it under a plain heading.
     */
    public function testAProfileWithNoPropertyIsStillListed(): void
    {
        $grouped = $this->group( array(
            'aaa' => $this->site( 'aaa', 'Orphan', null, 'orphan.example.com' ),
        ) );

        $this->assertCount( 1, $grouped );

        $sites = array_values( $grouped )[0];

        $this->assertCount( 1, $sites );
        $this->assertSame( 'aaa', array_values( $sites )[0]->get( 'site_id' ) );
    }

    /** It falls back to the domain, which is more use than a generic heading. */
    public function testAnUnassignedProfileIsHeadedByItsDomain(): void
    {
        $grouped = $this->group( array(
            'aaa' => $this->site( 'aaa', 'Orphan', null, 'orphan.example.com' ),
        ) );

        $this->assertArrayHasKey( 'orphan.example.com', $grouped );
    }

    /** ...and only when there is no domain either does it get a generic one. */
    public function testAProfileWithNeitherPropertyNorDomainIsNotLost(): void
    {
        $grouped = $this->group( array(
            'aaa' => $this->site( 'aaa', 'Nameless', null, '' ),
        ) );

        $this->assertArrayHasKey( 'Unassigned', $grouped );
        $this->assertCount( 1, $grouped['Unassigned'] );
    }

    /**
     * The keys must survive, because the caller hands back a site_id-keyed map
     * and the selector marks the current one by comparing that id.
     */
    public function testTheSiteIdKeysArePreserved(): void
    {
        $grouped = $this->group( array(
            'aaa' => $this->site( 'aaa', 'One', null, 'a.example.com' ),
            'bbb' => $this->site( 'bbb', 'Two', null, 'b.example.com' ),
        ) );

        $keys = array();

        foreach ( $grouped as $sites ) {
            $keys = array_merge( $keys, array_keys( $sites ) );
        }

        sort( $keys );

        $this->assertSame( array( 'aaa', 'bbb' ), $keys );
    }

    /**
     * The case the hierarchy exists for: two Profiles of ONE website, sharing a
     * domain, land under one heading rather than reading as two unrelated
     * sites.
     */
    public function testProfilesSharingAPropertyShareAHeading(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->set( 'id', $property->generateId( 'grouping-test-' . uniqid() ) );
        $property->set( 'name', 'Grouping Test Property' );
        $property->create();

        $id = $property->get( 'id' );

        try {

            $grouped = $this->group( array(
                'aaa' => $this->site( 'aaa', 'Observation Profile 1', $id, 'shared.example.com' ),
                'bbb' => $this->site( 'bbb', 'Observation Profile 2', $id, 'shared.example.com' ),
            ) );

            $this->assertArrayHasKey(
                'Grouping Test Property', $grouped,
                'Profiles are headed by their Property name, not their own.' );

            $this->assertCount(
                2, $grouped['Grouping Test Property'],
                'Two Profiles of one website must group together, not split.' );

            $this->assertCount(
                1, $grouped, 'They produced more than one heading.' );

        } finally {

            $property->delete( $id );
        }
    }
    /**
     * Pinned by reading the template, because nothing in PHP references these
     * names -- the same reason SiteListContractTest reads it for site_id/name.
     */
    public function testTheSelectorActuallyRendersTheGroups(): void
    {
        $template = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/filter_site.php' );

        $this->assertStringContainsString(
            'sites_by_property', $template,
            'The selector no longer reads the grouped list, so grouping it achieves nothing.' );

        $this->assertStringContainsString(
            'OPTGROUP', $template,
            'The Property heading is what carries the context a Profile name lacks.' );

        /*
         * The fallback matters as much as the grouping: a controller that has
         * not been taught to group must still render a usable selector rather
         * than an empty one.
         */
        $this->assertStringContainsString(
            '$view->sites', $template,
            'The flat list is the fallback when no grouped list was set.' );
    }
}
