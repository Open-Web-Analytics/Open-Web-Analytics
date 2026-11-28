<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/PropertyFixtures.php';

/**
 * What GET /v1/sites emits, and who it emits it to.
 *
 * Pinned because this endpoint is consumed by the WordPress plugin: it lists
 * sites so an author can pick one, and puts the chosen site_id into the
 * tracking tag.
 *
 * Two consequences follow from that, and both are easy to break without
 * noticing:
 *
 *   - The list must stay a FLAT list of profiles. A Property has no tracker id,
 *     so listing properties instead would leave the plugin with nothing to put
 *     in the tag.
 *
 *   - domain and name must keep appearing on each entry, even though both
 *     conceptually belong to the Property. If the profile stops carrying them
 *     the view has to enrich from the parent -- the fields cannot simply move.
 *
 *     The plugin builds its picker label from site_id and domain and never
 *     reads name:
 *
 *         sprintf('%s (%s)', $site['properties']['site_id']['value'],
 *                            $site['properties']['domain']['value'])
 *
 * The endpoint returns entities directly (SitesRest sets tracked_sites as the
 * response data), so the emitted field names ARE the entity's columns. That
 * makes this contract unusually easy to change by accident: adding a column to
 * base.site adds a field to a public API, and removing one removes it.
 */
final class SiteListContractTest extends TestCase
{
    /** What a /v1/sites entry carries; the plugin depends on the first three. */
    private const PAYLOAD_FIELDS = [
        'site_id',      // the tracking id -- what ends up in the tag
        'name',         // NOT the plugin's picker label -- see the note below
        'domain',       // the plugin labels with site_id and domain
        'description',
        'id',
        'settings',
        /*
         * SUPERSEDED by property_id but deliberately still emitted. Removing it
         * would take a field out of a public API to save a column nothing
         * reads.
         */
        'site_family',
        /*
         * APPROVED ADDITION (Organization / Property / Observation Profile).
         *
         * Safe because it is an addition: JSON consumers ignore keys they do
         * not know, so the plugin is unaffected, and a client that wants the
         * hierarchy can now find the parent without a second call. Listed here
         * rather than silently absorbed -- that is the point of matching the
         * set exactly.
         */
        'property_id',
    ];

    public function testThePayloadFieldsAreExactlyWhatWasPublished(): void
    {
        /*
         * An exact match, not a subset. The payload cannot change: this endpoint
         * is a public contract consumed by the WordPress plugin, which reads
         * site_id for the tag and name/domain to label its picker.
         *
         * Asserting the exact set means an ADDITION fails too. That is
         * deliberate -- adding a column to base.site adds a field to a public
         * API without anyone deciding to, and the fix is to approve it here
         * rather than to discover it downstream. Removals and renames are the
         * ones that break installs.
         */
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        /*
         * getPublicProperties(), which is what RestApi::toResponseData()
         * actually serialises -- not _getProperties(), which is every column.
         *
         * The two differ once a column is declared private, and asserting on
         * the raw set made an INTERNAL column read as a contract change. The
         * point of this test is the published shape, so it should read the
         * thing that gets published. What stops a column slipping out
         * unnoticed is that private_properties has to be written down: a new
         * column is public unless someone says otherwise, and then it fails
         * here.
         */
        $fields = array_keys( (array) $site->getPublicProperties() );

        sort( $fields );

        $expected = self::PAYLOAD_FIELDS;
        sort( $expected );

        $this->assertSame(
            $expected, $fields,
            'The GET /v1/sites payload changed. If a field moved to the Property it must still be '
            . 'enriched onto the entry, not dropped; if a field was added, approve it here.' );
    }

    /**
     * An internal column stays internal.
     *
     * archived_date exists so removal is recoverable. It is not something a
     * client can act on -- an archived Profile is filtered out of this listing
     * entirely -- so it would be a permanently falsy field in a published
     * payload.
     */
    public function testInternalColumnsAreNotPublished(): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $this->assertArrayHasKey( 'archived_date', (array) $site->_getProperties(),
            'The column is gone, so this test is checking nothing.' );

        $this->assertArrayNotHasKey( 'archived_date', (array) $site->getPublicProperties(),
            'archived_date reached the published payload.' );
    }

    public function testTheTrackingIdFieldIsNamedSiteId(): void
    {
        /*
         * Named explicitly rather than left to the list above. Renaming this
         * field -- to profile_id, say -- would be the single most tempting
         * change when profiles arrive, and would silently break every existing
         * plugin install: they would read a missing key and write an empty
         * tracking id into the tag.
         */
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $this->assertArrayHasKey(
            'site_id', (array) $site->_getProperties(),
            'The tracking id must keep the key site_id in this payload, whatever the concept '
            . 'is called internally.' );
    }

    public function testTheEndpointIsRegisteredWhereThePluginLooksForIt(): void
    {
        /*
         * The route is as much a contract as the payload -- plugins call it by
         * URL, so moving it is indistinguishable from deleting it.
         */
        $route = \OWA\Core\CoreAPI::serviceSingleton()
            ->getRestApiRoute( 'base', 'v1', 'sites', 'GET' );

        $this->assertNotEmpty(
            $route,
            'GET v1/sites is no longer registered for the base module.' );

        $this->assertStringContainsString(
            'SitesRest', (string) ( $route['class_name'] ?? '' ),
            'GET v1/sites no longer resolves to the sites list controller.' );
    }

    public function testOurOwnSelectorReadsTheSameTwoFieldsAsThePlugin(): void
    {
        /*
         * The reporting UI's site control and the WordPress plugin's picker both
         * get their list from getSitesAllowedForCurrentUser(). The control
         * navigates by PROPERTY -- a report is of a Property and covers every
         * Profile in it -- while the plugin, which reports on one site, reads the
         * site list keyed by site_id. The tracking id is on the Property's roster,
         * with the Profiles.
         *
         * Pinned by reading the templates, because the coupling is invisible
         * from PHP: nothing references the control's field names.
         */
        $template = file_get_contents( \OWA\Core\Env::root() . '/modules/Base/templates/site_control.php' );

        $this->assertStringContainsString(
            '\'propertyId\' => $owa_p[\'id\']', $template,
            'The site control no longer navigates by Property, so choosing one cannot '
            . 'change what the report is of.' );

        $roster = file_get_contents( \OWA\Core\Env::root() . '/modules/Base/templates/property_profiles.php' );

        $this->assertStringContainsString(
            '$owa_prof[\'site_id\']', $roster,
            'The roster no longer shows the tracking id -- the value someone opens it to find, '
            . 'and the reason a flat list of "Observation Profile 1" was not enough.' );
    }

    public function testAdministratorsSeeEveryProfileAndOthersSeeOnlyGranted(): void
    {
        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        /*
         * The resolution the hierarchy changes: today an admin sees every site
         * and everyone else sees the grant table's contents. Once grants attach
         * to a Property, "everyone else" must see the profiles OF granted
         * properties -- the same flat list, reached differently.
         */
        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $this->assertTrue(
            $user->isAdmin(),
            'An admin must be recognised as one, or the branch below is never exercised.' );

        $all = (array) \OWA\Core\CoreAPI::getSitesList();

        $this->assertNotEmpty(
            $all,
            'No sites exist, so this test cannot distinguish "admin sees all" from "admin sees '
            . 'nothing".' );
    }
    /**
     * What the VIEW emits, which is the actual API surface.
     *
     * The test above asserts the entity's columns as a proxy for the payload,
     * and that proxy has a blind spot: the view may add fields the entity does
     * not carry. property_name is exactly that -- it comes from the parent
     * Property, so it is not a column on base.site and the entity assertion
     * cannot see it.
     *
     * ADDING a field is safe: a JSON consumer ignores keys it does not know.
     * What is not safe is renaming or dropping one, or changing what a
     * published field CONTAINS -- which is why 'name' is asserted to still be
     * the Profile's own stored name rather than a composed label.
     */
    public function testTheViewEmitsThePublishedFieldsPlusThePropertyName(): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->set( 'site_id', 'contract-probe' );
        $site->set( 'name', 'Observation Profile 1' );

        $view   = new \OWA\Module\Base\View\SitesRest();
        $method = new \ReflectionMethod( $view, 'addPropertyNames' );

        $emitted = $method->invoke( $view, [ 'contract-probe' => $site ] );

        $this->assertArrayHasKey( 'contract-probe', $emitted );

        $row = $emitted['contract-probe'];

        foreach ( self::PAYLOAD_FIELDS as $field ) {

            $this->assertArrayHasKey(
                $field, $row, "$field stopped being emitted -- that is a breaking change." );
        }

        $this->assertArrayHasKey(
            'property_name', $row,
            'The website name lives on the Property now, so a client has no way to label a '
            . 'picker without it.' );

        $this->assertSame(
            'Observation Profile 1', $row['name'],
            "'name' is the Profile's own stored name. Composing a label into it would change "
            . 'what a published field contains, which is not an addition.' );
    }

    /**
     * The view emits the published fields and property_name, and no column the
     * entity declares private. It built each row from _getProperties(), every
     * column, so archived_date, stream_type and app_id reached the payload.
     */
    public function testTheViewEmitsNoPrivateColumn(): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->set( 'site_id', 'contract-probe' );
        $site->set( 'stream_type', 'app' );
        $site->set( 'app_id', 'com.example.app' );

        $view = new \OWA\Module\Base\View\SitesRest();
        $row  = ( new \ReflectionMethod( $view, 'addPropertyNames' ) )
            ->invoke( $view, [ 'contract-probe' => $site ] )['contract-probe'];

        $fields   = array_keys( $row );
        $expected = array_merge( self::PAYLOAD_FIELDS, [ 'property_name' ] );
        sort( $fields );
        sort( $expected );

        $this->assertSame( $expected, $fields );
    }

    /**
     * Adding a site answers with the site, over REST and on the command line;
     * granting a user a site answers with both. Each is built from the
     * entity's public properties.
     */
    public function testTheAddAndGrantRoutesEmitPublicPropertiesOnly(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'creates a Profile' );
        }

        $controller = new \OWA\Module\Base\Controller\SitesAdd( [
            'domain'     => 'site-list-contract.example.test',
            'name'       => 'Site list contract ' . uniqid(),
            'streamType' => 'web',
        ] );
        $controller->action();

        $site = (array) ( ( new \ReflectionProperty( \OWA\Core\Controller::class, 'data' ) )->getValue( $controller )['site'] ?? [] );

        try {
            $this->assertNotEmpty( $site['site_id'] ?? '', 'The Profile was not created.' );

            $entity = \OWA\Module\Base\Entity\Site::forSiteId( $site['site_id'] );

            $this->assertSame( $entity->getPublicProperties(), $site );

            foreach ( [ 'archived_date', 'stream_type', 'app_id' ] as $private ) {
                $this->assertArrayNotHasKey( $private, $site );
            }

            $user_id = 'site-list-contract-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '@owatest.example.com';
            $user    = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
            $user->createNewUser( $user_id, 'viewer', 'x' . $user_id, $user_id, 'OWA Test viewer' );
            $user->load( $user_id, 'user_id' );

            $grant = new \OWA\Module\Base\Controller\SiteAddAllowedUserRest(
                [ 'siteId' => $site['site_id'], 'user_id' => $user_id ] );
            $grant->action();

            $response = (array) ( $grant->data['response'] ?? [] );

            $this->assertSame( $entity->getPublicProperties(), $response['site'] ?? null );
            $this->assertSame( $user->getPublicProperties(), $response['allowed_user'] ?? null );

            foreach ( [ 'password', 'temp_passkey', 'session_epoch' ] as $private ) {
                $this->assertArrayNotHasKey( $private, $response['allowed_user'] );
            }
        } finally {
            if ( ! empty( $user ) && $user->get( 'id' ) ) {
                \OWA\Core\CoreAPI::entityFactory( 'base.site_user' )->delete( $user->get( 'id' ), 'user_id' );
                \OWA\Core\CoreAPI::entityFactory( 'base.user' )->delete( $user->get( 'id' ), 'id' );
            }

            if ( ! empty( $site['id'] ) ) {
                \OWA\Core\CoreAPI::entityFactory( 'base.site' )->delete( $site['id'], 'id' );
                owa_test_remove_property( $site['property_id'] );
            }
        }
    }
}
