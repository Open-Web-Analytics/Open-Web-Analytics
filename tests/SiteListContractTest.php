<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What GET /v1/sites emits, and who it emits it to.
 *
 * Pinned before the Organization / Property / Observation Profile hierarchy
 * splits owa_site in two, because this endpoint is consumed by the WordPress
 * plugin: it lists sites so an author can pick one, and puts the chosen
 * site_id into the tracking tag.
 *
 * Two consequences follow from that, and both are easy to break without
 * noticing:
 *
 *   - The list must stay a FLAT list of profiles. A Property has no tracker id,
 *     so listing properties instead would leave the plugin with nothing to put
 *     in the tag.
 *
 *   - domain and name must keep appearing on each entry, even though both
 *     conceptually belong to the Property once the hierarchy exists. If the
 *     profile stops carrying them the view has to enrich from the parent -- the
 *     fields cannot simply move.
 *
 *     CORRECTED: an earlier version of this note said both are "what the
 *     plugin's picker labels its options with". Only domain is. The plugin
 *     builds its label from site_id and domain and never reads name:
 *
 *         sprintf('%s (%s)', $site['properties']['site_id']['value'],
 *                            $site['properties']['domain']['value'])
 *
 *     That line also shows the plugin reading the PRE-#977 nested payload, so
 *     its picker has been broken since 2026-08-02 independently of any of
 *     this. Recorded here because this file is where someone will look.
 *
 * The endpoint returns entities directly (SitesRest sets tracked_sites as the
 * response data), so the emitted field names ARE the entity's columns. That
 * makes this contract unusually easy to change by accident: adding a column to
 * base.site adds a field to a public API, and removing one removes it.
 */
final class SiteListContractTest extends TestCase
{
    /** What a /v1/sites entry carries; the plugin depends on the first three. */
    private const PAYLOAD_FIELDS = array(
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
    );

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

        $fields = array_keys( (array) $site->_getProperties() );

        sort( $fields );

        $expected = self::PAYLOAD_FIELDS;
        sort( $expected );

        $this->assertSame(
            $expected, $fields,
            'The GET /v1/sites payload changed. If a field moved to the Property it must still be '
            . 'enriched onto the entry, not dropped; if a field was added, approve it here.' );
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
         * The reporting UI's site filter and the WordPress plugin's picker are
         * the same problem twice: both label an option with `name` and submit
         * `site_id` as its value, and both get their list from
         * getSitesAllowedForCurrentUser().
         *
         * That shared source is what makes the hierarchy tractable. Once a
         * Property can hold several profiles, "Observation Profile 1" is an
         * ambiguous label in both places -- and composing it as
         * "Property — Profile" AT THAT ONE METHOD fixes our UI and every
         * already-deployed plugin without either consumer changing. Composing
         * it in the templates instead would fix only ours.
         *
         * CORRECTED: this used to pin that both labelled with 'name'. The
         * plugin labels with site_id and domain, and our control no longer
         * labels with a single field at all -- it shows Property, Profile and
         * the tracking id. What still matters is that it navigates by site_id.
         *
         * Pinned by reading the template, because the coupling is invisible
         * from PHP: nothing references the control's field names.
         */
        $template = file_get_contents( OWA_DIR . 'modules/Base/templates/site_control.php' );

        $this->assertStringContainsString(
            '\'siteId\' => $owa_prof[\'site_id\']', $template,
            'The site control no longer navigates by site_id, so choosing a Profile cannot '
            . 'change what the report is of.' );

        $this->assertStringContainsString(
            'owa_siteControlId', $template,
            'The control no longer shows the tracking id -- the value someone opens it to find, '
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
        $method->setAccessible( true );

        $emitted = $method->invoke( $view, array( 'contract-probe' => $site ) );

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
}
