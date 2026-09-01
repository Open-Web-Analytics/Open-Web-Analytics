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
    /**
     * The settings nav is for install-wide configuration only.
     *
     * Everything else hangs off the hierarchy and is reached from the site
     * control. A "Goal Settings" entry in a global menu could not say WHICH
     * site's goals it meant, and "User Management" put the people who belong to
     * an Organization somewhere that never mentioned one.
     *
     * Timezone is the one that must NOT move down: yyyymmdd and nine other date
     * parts are baked into every fact row at collection in the configured zone,
     * so it is not retroactive, and two Profiles disagreeing would put rows of
     * different meanings in one table with nothing recording which.
     */
    public function testTheSettingsNavHoldsOnlyInstallWideOptions(): void
    {
        $panels = (array) \OWA\Core\CoreAPI::singleton()->getAdminPanels();

        $actions = array();

        foreach ( $panels as $items ) {
            foreach ( (array) $items as $item ) {
                $actions[] = $item['do'];
            }
        }

        foreach ( array( 'base.users'        => 'the Organization',
                         'base.sites'        => 'the site control',
                         'base.optionsGoals' => 'a Profile' ) as $gone => $where ) {

            $this->assertNotContains(
                $gone, $actions,
                "$gone is still in the settings nav -- it belongs to $where now." );
        }

        $this->assertContains(
            'base.optionsModules', $actions,
            'Modules is install-wide and has nowhere else to go.' );
    }

    /** Each tier is reachable from the control, which is the only way in now. */
    public function testEveryTierIsReachableFromTheControl(): void
    {
        $template = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/site_control.php' );

        foreach ( array( 'base.organizationProfile' => 'the Organization',
                         'base.propertyProfile'     => 'a Property',
                         'base.sitesProfile'        => 'a Profile',
                         'base.optionsGoals'        => "a Profile's goals" ) as $action => $what ) {

            $this->assertStringContainsString(
                $action, $template,
                "The control offers no way to reach $what, and the settings nav no longer does." );
        }
    }

    /**
     * The hierarchy screens carry the control, not the settings nav.
     *
     * They are reached FROM the control and describe a tier of the tree;
     * showing the install's settings menu beside them would offer a way out
     * that has nothing to do with where you are.
     */
    public function testTheHierarchyScreensUseTheControlNotTheSettingsNav(): void
    {
        $wrapper = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/options_hierarchy.php' );

        $this->assertStringContainsString( "include('site_control.php')", $wrapper );
        $this->assertStringNotContainsString(
            'view->panels', $wrapper,
            'The hierarchy wrapper renders the settings panels.' );

        foreach ( array( 'OrganizationProfile', 'PropertyProfile', 'SitesProfile' ) as $screen ) {

            $src = (string) file_get_contents(
                OWA_DIR . "modules/Base/Controller/{$screen}.php" );

            $this->assertStringContainsString(
                "base.optionsHierarchy", $src,
                "$screen still renders inside the install settings nav." );
        }
    }
    /**
     * The fan-out is closed until asked for.
     *
     * It shipped open on every page load: a class rule setting display:flex
     * outranks the UA stylesheet's [hidden]{display:none}, so the markup said
     * hidden and the panel rendered anyway. The attribute is for assistive
     * technology; the class is what actually shows it, and both have to agree.
     */
    public function testTheFanOutIsHiddenUntilOpened(): void
    {
        $css = (string) file_get_contents( OWA_DIR . 'modules/Base/css/owa.report.css' );

        /*
         * Anchored to the start of a line. Unanchored, this matched
         * '.owa_siteControl.is-open .owa_siteControlPanel {' -- which contains
         * the same substring and sets display:flex -- so the assertion below
         * was reading the wrong rule entirely.
         */
        $panel = substr( $css, strpos( $css, "\n.owa_siteControlPanel {" ) );
        /*
         * Terminated on a brace at the START of a line. The first bare '}' is
         * inside this rule's own comment -- it quotes [hidden]{display:none} --
         * so cutting there truncated the block before the declaration being
         * asserted on.
         */
        $panel = substr( $panel, 0, strpos( $panel, "\n}" ) );

        $this->assertStringContainsString(
            'display: none', $panel,
            'The panel does not hide itself, so it renders open on every page load -- the '
            . 'hidden attribute alone loses to a class rule setting display.' );

        $this->assertStringContainsString(
            '.owa_siteControl.is-open .owa_siteControlPanel', $css,
            'Nothing shows the panel when the tile is clicked.' );

        $js = (string) file_get_contents(
            OWA_DIR . 'modules/Base/src/reporting/v1/owa.report.js' );

        /*
         * SINGLE quoted. $panel is a real variable in this test, so in double
         * quotes the needle became the CSS text plus ".on('click', 'a'" -- and
         * unlike an undefined variable, that interpolates silently.
         */
        $this->assertStringContainsString(
            '$panel.on(\'click\', \'a\'', $js,
            'A link inside the panel leaves it open under a pending navigation, which reads '
            . 'as though the click missed.' );
    }
}
