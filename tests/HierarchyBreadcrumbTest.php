<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The context line above a settings screen links each tier to its own screen.
 *
 * Rendered, not grepped: what matters is which crumb gets which href, and
 * that a crumb never links to a screen the reader's nav does not offer.
 */
final class HierarchyBreadcrumbTest extends TestCase
{
    const SITE     = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    const PROPERTY = '7773000000000001';

    protected function setUp(): void
    {
        $this->actAs( 'everyone' );
    }

    protected function tearDown(): void
    {
        $this->actAs( 'everyone' );
    }

    public function testEachTierLinksToTheFirstScreenOfItsNavGroup(): void
    {
        $html = $this->render( 3, 'base.sitesInvocation' );

        $this->assertSame( 'base.organizationProfile', $this->linkFor( $html, 'Acme Org' ) );
        $this->assertSame( 'base.propertyProfile', $this->linkFor( $html, 'Acme Website' ) );
        $this->assertSame( 'base.sitesProfile', $this->linkFor( $html, 'Acme Web Profile' ) );

        $this->assertStringContainsString( 'propertyId=' . self::PROPERTY, $this->hrefFor( $html, 'Acme Website' ) );
        $this->assertStringContainsString( 'siteId=' . self::SITE, $this->hrefFor( $html, 'Acme Web Profile' ) );
    }

    public function testTheCrumbForTheScreenBeingShownIsNotALink(): void
    {
        $html = $this->render( 2, 'base.propertyProfile' );

        $this->assertNull( $this->linkFor( $html, 'Acme Website' ) );
        $this->assertStringContainsString( 'Acme Website', $html );
        $this->assertSame( 'base.organizationProfile', $this->linkFor( $html, 'Acme Org' ) );
    }

    public function testTheRootCrumbLinksToTheGroupItNames(): void
    {
        $html = $this->render( 0, 'base.myAppPasswords', 'My Preferences' );

        $this->assertSame( 'base.myProfile', $this->linkFor( $html, 'My Preferences' ) );
    }

    /** The nav hides a screen the reader may not open; so does the crumb. */
    public function testACrumbSkipsAScreenTheReaderCannotOpen(): void
    {
        $nav = $this->nav();
        array_unshift( $nav['Property'], array(
            'do' => 'base.propertyAccess', 'label' => 'Access', 'params' => array(),
            'capability' => 'edit_sites' ) );

        $html = $this->render( 2, 'base.goalEvents', 'Instance', $nav );

        $this->assertSame( 'base.propertyProfile', $this->linkFor( $html, 'Acme Website' ),
            'an anonymous reader was linked to a screen gated on edit_sites' );

        $this->actAs( 'admin' );

        $this->assertSame( 'base.propertyAccess',
            $this->linkFor( $this->render( 2, 'base.goalEvents', 'Instance', $nav ), 'Acme Website' ) );
    }

    public function testAScreenWithoutANavStillDrawsItsCrumbs(): void
    {
        $html = $this->render( 3, 'base.sitesProfile', 'Instance', array() );

        $this->assertStringNotContainsString( '<a ', $html );
        $this->assertStringContainsString( 'Acme Web Profile', $html );
    }

    private function nav(): array
    {
        $item = function ( string $do, array $params = array() ): array {
            return array( 'do' => $do, 'label' => $do, 'params' => $params, 'capability' => '' );
        };

        return array(
            'My Preferences' => array( $item( 'base.myProfile' ), $item( 'base.myAppPasswords' ) ),
            'Instance'       => array( $item( 'base.optionsGeneral' ) ),
            'Organization'   => array( $item( 'base.organizationProfile' ), $item( 'base.users' ) ),
            'Property'       => array(
                $item( 'base.propertyProfile', array( 'propertyId' => self::PROPERTY ) ),
                $item( 'base.goalEvents', array( 'propertyId' => self::PROPERTY ) ) ),
            'Observation Profile' => array(
                $item( 'base.sitesProfile', array( 'siteId' => self::SITE, 'edit' => true ) ),
                $item( 'base.sitesInvocation', array( 'siteId' => self::SITE ) ) ),
        );
    }

    private function render( int $tier, string $do, string $root = 'Instance', ?array $nav = null ): string
    {
        $t = new \OWA\Core\Template( 'base' );

        $t->set( 'site_hierarchy', array(
            'organization' => array( 'name' => 'Acme Org' ),
            'properties'   => array( array(
                'id' => self::PROPERTY, 'name' => 'Acme Website', 'domain' => 'acme.example.com',
                'profiles' => array( array( 'site_id' => self::SITE, 'name' => 'Acme Web Profile' ) ),
            ) ),
        ) );
        $t->set( 'params', array( 'do' => $do, 'siteId' => self::SITE, 'propertyId' => self::PROPERTY ) );
        $t->set( 'hierarchy_tier', $tier );
        $t->set( 'hierarchy_root_label', $root );
        $t->set( 'hierarchy_nav', $nav ?? $this->nav() );

        $this->assertTrue( $t->set_template( 'hierarchy_breadcrumb.php' ) );

        return $t->fetch();
    }

    /** The action a crumb links to, or null when it is plain text. */
    private function linkFor( string $html, string $label ): ?string
    {
        $href = $this->hrefFor( $html, $label );

        if ( $href === null ) {
            return null;
        }

        parse_str( (string) parse_url( html_entity_decode( $href ), PHP_URL_QUERY ), $q );

        return $q['do'] ?? $q['owa_do'] ?? null;
    }

    private function hrefFor( string $html, string $label ): ?string
    {
        if ( ! preg_match( '#<a href="([^"]*)">' . preg_quote( $label, '#' ) . '</a>#', $html, $m ) ) {
            return null;
        }

        return html_entity_decode( $m[1] );
    }

    private function actAs( string $role ): void
    {
        $u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $u->set( 'user_id', '' );
        $u->set( 'role', $role );

        \OWA\Core\CoreAPI::getCurrentUser()->loadNewUserByObject( $u );
    }
}
