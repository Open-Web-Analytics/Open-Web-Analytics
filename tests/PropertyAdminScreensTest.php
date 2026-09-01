<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Property roster and its rename.
 *
 * The migration NAMES Properties for you -- from the domain, or from whichever
 * site it saw first. Without a screen those names are permanent, and they are
 * what the site selector groups by, so a bad one is visible on every report.
 * That is what this screen is for; the rest of a Property is not editable here
 * because nothing else about it was auto-generated.
 */
final class PropertyAdminScreensTest extends TestCase
{
    private function data( $controller ): array
    {
        $property = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $property->setAccessible( true );

        return (array) $property->getValue( $controller );
    }

    public function testBothActionsAreRegisteredWhereTheAdminPanelPointsThem(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $actions = (array) $service->getMap( 'actions' );

        foreach ( array( 'base.properties', 'base.propertyEdit', 'base.organizationEdit' ) as $action ) {

            $this->assertArrayHasKey(
                $action, $actions,
                "$action is linked to but not registered, so the screen 404s." );
        }
    }

    /** Renaming is capability-gated and nonce-protected, like every other mutation. */
    public function testTheRenameIsGuardedLikeOtherMutations(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyEdit( array() );

        $this->assertSame( 'edit_sites', $controller->getRequiredCapability() );

        $nonce = new \ReflectionProperty( \OWA\Core\Controller::class, 'is_nonce_required' );
        $nonce->setAccessible( true );

        $this->assertTrue(
            $nonce->getValue( $controller ),
            'A rename that any page could trigger cross-site is a mutation without a nonce.' );
    }

    /**
     * A blank name is refused. It is not cosmetic: the selector groups by this
     * name and falls back to the domain when it is empty, so a Property renamed
     * to nothing reads as though it had been deleted.
     */
    public function testABlankNameIsRefused(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyEdit(
            array( 'propertyId' => '123', 'name' => '   ' ) );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $controller );

        $this->assertNotEmpty( $validator, 'Nothing validates the rename at all.' );

        $property = new \ReflectionProperty( $validator, 'validations' );
        $property->setAccessible( true );

        $names = array();

        foreach ( (array) $property->getValue( $validator ) as $validation ) {
            $names[] = $validation['name'];
        }

        $this->assertContains(
            'name', $names, 'The name is not validated, so a Property can be renamed to nothing.' );
    }

    public function testTheRosterListsProfilesUnderTheirPropertyAndLosesNone(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $controller = new \OWA\Module\Base\Controller\Properties( array() );
        $controller->action();

        $data = $this->data( $controller );

        $this->assertArrayHasKey( 'properties', $data );
        $this->assertArrayHasKey(
            'unassigned_profiles', $data,
            'A Profile with no Property must have somewhere to appear, or the roster '
            . 'reports a tracked site as absent.' );

        $listed = count( (array) $data['unassigned_profiles'] );

        foreach ( (array) $data['properties'] as $property ) {

            $this->assertArrayHasKey( 'profiles', $property );
            $listed += count( $property['profiles'] );
        }

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'getSitesAllowedForCurrentUser' );
        $method->setAccessible( true );

        $this->assertSame(
            count( (array) $method->invoke( $controller ) ), $listed,
            'Every Profile the user may see appears exactly once on the roster.' );
    }

    /**
     * Pinned by reading the template: nothing in PHP references these names, so
     * a rename of either side breaks the screen silently.
     */
    public function testTheTemplateRendersWhatTheControllerSets(): void
    {
        $template = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/properties.php' );

        foreach ( array( 'properties', 'unassigned_profiles', 'profiles' ) as $key ) {

            $this->assertStringContainsString(
                $key, $template, "The roster no longer renders $key." );
        }

        $this->assertStringContainsString(
            'base.propertyEdit', $template, 'The roster offers no way to rename.' );

        /*
         * PropertyEdit is setNonceRequired(), so without a nonce every rename
         * fails the check and the screen looks simply broken.
         *
         * createNonceFormField() is how every other admin form does this --
         * sites_addoredit, users_addoredit, options_general, custom_report_edit.
         * Asserted by name so this form cannot drift onto a different mechanism
         * than the rest of the admin screens.
         */
        $this->assertStringContainsString(
            "createNonceFormField( 'base.propertyEdit' )",
            $template,
            'The rename form does not carry a nonce, so every rename is refused.' );
    }
    /**
     * The Organization is the one tier a user could not name.
     *
     * There is exactly one, created as "My Organization" by the installer or by
     * Update021 -- a name nobody chose -- and it heads the roster, so leaving it
     * uneditable meant the tier describing the user was the only one they could
     * not touch.
     */
    public function testTheOrganizationCanBeRenamedAndIsGuardedTheSameWay(): void
    {
        $controller = new \OWA\Module\Base\Controller\OrganizationEdit( array() );

        $this->assertSame( 'edit_settings', $controller->getRequiredCapability() );

        $nonce = new \ReflectionProperty( \OWA\Core\Controller::class, 'is_nonce_required' );
        $nonce->setAccessible( true );

        $this->assertTrue( $nonce->getValue( $controller ) );

        $controller->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );

        $this->assertNotEmpty(
            $v->getValue( $controller ),
            'A blank Organization name would leave the roster headed by nothing.' );
    }

    public function testTheRosterOffersTheOrganizationRename(): void
    {
        $template = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/properties.php' );

        $this->assertStringContainsString( 'base.organizationEdit', $template );
        $this->assertStringContainsString(
            "createNonceFormField( 'base.organizationEdit' )", $template,
            'Without a nonce the rename is refused and the form looks broken.' );
        $this->assertStringContainsString( 'organization_name', $template );
    }

    /**
     * The selector is enhanced with chosen, as the dimension pickers are.
     *
     * A native select renders an OPTGROUP heading as a greyed, unselectable
     * line, which on a long list is easy to miss and impossible to search --
     * so the grouping is there but does not read as grouping.
     */
    public function testTheSiteSelectorIsEnhanced(): void
    {
        $js = (string) file_get_contents(
            OWA_DIR . 'modules/Base/src/reporting/v1/owa.report.js' );

        $this->assertStringContainsString( '.chosen(', $js );

        $this->assertStringContainsString(
            "width: '100%'", $js,
            'chosen-js 1.x measures the select at enhancement time and reads 0 inside a '
            . 'hidden parent, collapsing the control to a sliver.' );

        $this->assertStringContainsString(
            "$select.change(", $js,
            'The change handler must stay on the SELECT -- chosen fires a native change on '
            . 'it, so binding there works enhanced or not.' );
    }
}
