<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Adding a Property goes on to adding its first Profile.
 *
 * A Property collects nothing until it has an Observation Profile, and the
 * add-a-Profile form used to open on "a new Property" with nothing to carry
 * the one just created. The save now redirects there with the Property
 * chosen, and the form starts from what that Property implies.
 */
final class NewPropertyFirstProfileTest extends TestCase
{
    const WEB_PROPERTY      = 7775000000000095;
    const APP_PROPERTY      = 7775000000000096;
    const ARCHIVED_PROPERTY = 7775000000000097;

    /** Ids PropertyEdit minted during a test, removed afterwards. */
    private $created = array();

    /** Whether a pending publish job was already queued before the test. */
    private $publishWasPending = false;

    public static function setUpBeforeClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        self::dropFixture();

        foreach ( array(
            self::WEB_PROPERTY      => array( 'web', 'first-profile.example.test', null ),
            self::APP_PROPERTY      => array( 'app', '', null ),
            self::ARCHIVED_PROPERTY => array( 'web', 'archived-first-profile.example.test', time() ),
        ) as $id => list( $type, $domain, $archived ) ) {

            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
            $property->setProperties( array(
                'id'            => $id,
                'name'          => 'First Profile fixture ' . $id,
                'domain'        => $domain,
                'property_type' => $type,
                'creation_date' => time(),
                'archived_date' => $archived,
            ) );

            if ( ! $property->create() ) {
                throw new \RuntimeException( 'seeding owa_property failed' );
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        self::dropFixture();
    }

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; this saves a Property.' );
        }

        $this->publishWasPending = $this->publishPending();
    }

    protected function tearDown(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        foreach ( $this->created as $id ) {

            $this->deleteProperty( $id );
        }

        // The save queues a full publish; leave the queue as it was found.
        if ( ! $this->publishWasPending ) {

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->query( sprintf( "DELETE FROM %s WHERE dedupe_key = 'publish-trackers:all' AND status = 'pending'",
                \OWA\Module\Base\Classes\JobQueue::table() ) );
        }
    }

    private static function dropFixture(): void
    {
        foreach ( array( self::WEB_PROPERTY, self::APP_PROPERTY, self::ARCHIVED_PROPERTY ) as $id ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.property' )->delete( $id, 'id' );
        }
    }

    private function deleteProperty( $id ): void
    {
        if ( ! $id ) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName() );
        $db->where( 'scope_type', 'property' );
        $db->where( 'scope_id', (string) $id );
        $db->executeQuery();

        \OWA\Core\CoreAPI::entityFactory( 'base.property' )->delete( $id, 'id' );
    }

    private function publishPending(): bool
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->tableExists( \OWA\Module\Base\Classes\JobQueue::table() ) ) {
            return true;
        }

        $db->selectFrom( \OWA\Module\Base\Classes\JobQueue::table() );
        $db->selectColumn( 'id' );
        $db->where( 'dedupe_key', 'publish-trackers:all' );
        $db->where( 'status', 'pending' );

        return (bool) $db->getAllRows();
    }

    private function data( $controller ): array
    {
        $property = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $property->setAccessible( true );

        return (array) $property->getValue( $controller );
    }

    private function profileForm( array $params ): array
    {
        $controller = new \OWA\Module\Base\Controller\SitesProfile( $params );
        $controller->action();

        return $this->data( $controller );
    }

    public function testAddingAPropertyRedirectsToItsFirstProfile(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyEdit( array(
            'name'         => 'First Profile redirect ' . uniqid(),
            'domain'       => 'first-profile-redirect.example.test',
            'propertyType' => 'web',
        ) );
        $controller->action();

        $data = $this->data( $controller );
        $this->created[] = $data['propertyId'] ?? '';

        $this->assertNotEmpty( $data['propertyId'] ?? '', 'The new Property id is not carried to the next screen.' );
        $this->assertSame( 'base.sitesProfile', $data['do'] ?? null );
        $this->assertSame( 'redirect', $data['view_method'] ?? null );
        $this->assertSame( 3212, $data['status_code'] ?? null );

        // ...and the screen it lands on has that Property chosen.
        $form = $this->profileForm( array( 'propertyId' => $data['propertyId'] ) );

        $this->assertSame( (string) $data['propertyId'], $form['propertyId'] );
        $this->assertSame( (string) $data['propertyId'], $form['params']['propertyId'] ?? null );
    }

    public function testEditingAPropertyStaysOnItsDetails(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyEdit( array(
            'propertyId' => (string) self::WEB_PROPERTY,
            'name'       => 'First Profile fixture ' . self::WEB_PROPERTY,
            'domain'     => 'first-profile.example.test',
        ) );
        $controller->action();

        $data = $this->data( $controller );

        $this->assertSame( 'base.propertyProfile', $data['do'] ?? null );
        $this->assertSame( 3201, $data['status_code'] ?? null );
    }

    public function testAWebPropertysProfileStartsOnItsDomain(): void
    {
        $form = $this->profileForm( array( 'propertyId' => (string) self::WEB_PROPERTY ) );

        $this->assertSame( (string) self::WEB_PROPERTY, $form['propertyId'] );
        $this->assertSame( 'web', $form['site']['stream_type'] ?? null );
        $this->assertSame( 'first-profile.example.test', $form['site']['domain'] ?? null );
    }

    public function testAnAppPropertysProfileObservesAnApp(): void
    {
        $form = $this->profileForm( array( 'propertyId' => (string) self::APP_PROPERTY ) );

        $this->assertSame( (string) self::APP_PROPERTY, $form['propertyId'] );
        $this->assertSame( 'app', $form['site']['stream_type'] ?? null );
        $this->assertArrayNotHasKey( 'domain', $form['site'] );
    }

    /** An archived or unknown Property is not offered, so the form starts blank. */
    public function testAnArchivedOrUnknownPropertyIsNotChosen(): void
    {
        foreach ( array( (string) self::ARCHIVED_PROPERTY, '7775000000000098' ) as $id ) {

            $form = $this->profileForm( array( 'propertyId' => $id ) );

            $this->assertSame( '', $form['propertyId'], $id );
            $this->assertSame( array(), $form['site'], $id );
        }
    }

    public function testTheAddFormWithNoPropertyIsUnchanged(): void
    {
        $form = $this->profileForm( array() );

        $this->assertSame( '', $form['propertyId'] );
        $this->assertSame( array(), $form['site'] );
    }
}
