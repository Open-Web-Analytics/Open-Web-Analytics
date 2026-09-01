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

        foreach ( array( 'base.propertyProfile', 'base.propertyEdit',
                         'base.organizationProfile', 'base.organizationEdit' ) as $action ) {

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


    /**
     * The selector is enhanced with chosen, as the dimension pickers are.
     *
     * A native select renders an OPTGROUP heading as a greyed, unselectable
     * line, which on a long list is easy to miss and impossible to search --
     * so the grouping is there but does not read as grouping.
     */
    public function testTheSiteControlReplacesTheOldSelect(): void
    {
        $js = (string) file_get_contents(
            OWA_DIR . 'modules/Base/src/reporting/v1/owa.report.js' );

        $this->assertStringContainsString( '#owa_siteControl', $js );

        /*
         * The CALL, not the name. The name still appears in a comment
         * explaining why the lookup went -- asserting on the bare name made
         * this test trip over its own documentation.
         */
        $this->assertStringNotContainsString(
            'jQuery("#owa_reportSiteFilterSelect', $js,
            'reload() still reads the select the site control replaced. That lookup returns '
            . 'undefined and the guard around it skips silently, so the report would keep '
            . 'loading while ignoring the site.' );

        $template = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/site_control.php' );

        foreach ( array( 'owa_siteControlOrgs', 'owa_siteControlProperties',
                         'owa_siteControlProfiles' ) as $column ) {

            $this->assertStringContainsString(
                $column, $template, "The control is missing its $column column." );
        }

        /*
         * The tracker id is shown beside each Profile because it is what
         * someone opens this control to find -- it is the value that goes in a
         * tag, and having to open an edit screen to read it was the gap.
         */
        $this->assertStringContainsString(
            'owa_siteControlId', $template,
            'A Profile is listed without its tracking id.' );

        $this->assertStringContainsString(
            "'do' => 'base.propertyProfile'", $template,
            'The Properties column offers no way to edit a Property.' );

        $this->assertStringContainsString(
            "'do' => 'base.sitesProfile'", $template,
            'The Profiles column offers no way to edit a Profile.' );
    }
}
