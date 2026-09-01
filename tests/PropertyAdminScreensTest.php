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

        foreach ( array( 'base.properties', 'base.propertyEdit' ) as $action ) {

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
         * 5th arg of makeLink is $add_nonce. PropertyEdit is setNonceRequired(),
         * so without it every rename fails the nonce check -- and the screen
         * would look like it simply does not work.
         */
        $this->assertMatchesRegularExpression(
            "/base\.propertyEdit'\s*\),\s*false,\s*'',\s*false,\s*true\s*\)/",
            $template,
            'The rename form does not carry a nonce, so every rename is refused.' );
    }
}
