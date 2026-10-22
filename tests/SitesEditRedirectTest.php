<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A saved Profile returns to its own Profile Details.
 *
 * SitesEdit redirected to base.sitesProfile with the siteId but no 'edit', and
 * that screen draws the add form unless 'edit' is set -- so renaming a Profile
 * landed on "New Observation Profile".
 */
final class SitesEditRedirectTest extends TestCase
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

    public function testARenameReturnsToTheEditForm(): void
    {
        $add = new \OWA\Module\Base\Controller\SitesAdd( array(
            'domain'     => 'sites-edit-redirect.example.test',
            'name'       => 'Sites edit redirect ' . uniqid(),
            'streamType' => 'web',
        ) );
        $add->action();

        $this->site = (array) ( $this->data( $add )['site'] ?? array() );
        $this->assertNotEmpty( $this->site['site_id'] ?? '', 'The Profile was not created.' );

        $edit = new \OWA\Module\Base\Controller\SitesEdit( array(
            'siteId' => $this->site['site_id'],
            'name'   => 'Renamed ' . uniqid(),
        ) );
        $edit->action();

        $data = $this->data( $edit );

        $this->assertSame( 'base.sitesProfile', $data['do'] ?? null );
        $this->assertSame( 'redirect', $data['view_method'] ?? null );
        $this->assertSame( $this->site['site_id'], $data['siteId'] ?? null );
        $this->assertTrue( $data['edit'] ?? null,
            'Without edit, base.sitesProfile draws the New Observation Profile form.' );
        $this->assertSame( 3201, $data['status_code'] ?? null );
    }
}
