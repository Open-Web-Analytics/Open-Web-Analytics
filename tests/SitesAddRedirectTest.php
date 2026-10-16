<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A new Observation Profile lands on its Tracking Tag.
 *
 * success() read the created row through $this->get(), which reads the
 * request, so it always found nothing and sent every new Profile to the
 * dashboard of the first site.
 */
final class SitesAddRedirectTest extends TestCase
{
    /** Rows a test created, removed afterwards. */
    private $site = array();

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; this creates a Profile.' );
        }
    }

    protected function tearDown(): void
    {
        if ( empty( $this->site['id'] ) ) {
            return;
        }

        \OWA\Core\CoreAPI::entityFactory( 'base.site' )->delete( $this->site['id'], 'id' );
        \OWA\Core\CoreAPI::entityFactory( 'base.property' )->delete( $this->site['property_id'], 'id' );
    }

    private function data( $controller ): array
    {
        $property = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $property->setAccessible( true );

        return (array) $property->getValue( $controller );
    }

    public function testANewProfileLandsOnItsTrackingTag(): void
    {
        $controller = new \OWA\Module\Base\Controller\SitesAdd( array(
            'domain'     => 'sites-add-redirect.example.test',
            'name'       => 'Sites add redirect ' . uniqid(),
            'streamType' => 'web',
        ) );
        $controller->action();

        $this->site = (array) ( $this->data( $controller )['site'] ?? array() );
        $this->assertNotEmpty( $this->site['site_id'] ?? '', 'The Profile was not created.' );

        $controller->success();
        $data = $this->data( $controller );

        $this->assertSame( 'base.sitesInvocation', $data['do'] ?? null );
        $this->assertSame( 'redirect', $data['view_method'] ?? null );
        $this->assertSame( $this->site['site_id'], $data['siteId'] ?? null,
            'The redirect is not scoped to the new Profile.' );
        $this->assertSame( 3202, $data['status_code'] ?? null );
    }

    /** Nothing created: back to the form with an error, not a success message. */
    public function testAFailedCreateReturnsToTheForm(): void
    {
        $controller = new \OWA\Module\Base\Controller\SitesAdd( array() );
        $controller->set( 'site', array() );
        $controller->success();

        $data = $this->data( $controller );

        $this->assertSame( 'base.sitesProfile', $data['do'] ?? null );
        $this->assertSame( 3203, $data['error_code'] ?? null );
        $this->assertArrayNotHasKey( 'status_code', $data );
    }
}
