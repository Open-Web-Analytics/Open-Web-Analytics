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
    /**
     * The tiers get a nested nav, not one page carrying several forms.
     *
     * A Property's name and a Profile's goals are different screens under
     * different headings. Stacking them on one page would mean a page that
     * saves in pieces and a heading structure that says nothing about which
     * tier each section belongs to.
     */
    public function testTheHierarchyHasItsOwnNestedNav(): void
    {
        $controller = new \OWA\Module\Base\Controller\OrganizationProfile( array() );

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'getHierarchyNav' );
        $method->setAccessible( true );

        /* With no context at all, only the Organization can be offered: there
           is no Property or Profile to name. */
        $bare = (array) $method->invoke( $controller, '', '' );

        $this->assertSame(
            array( 'Organization' ), array_keys( $bare ),
            'A screen with no site in context still offered Property or Profile links, '
            . 'which would point at nothing.' );

        $labels = array_column( $bare['Organization'], 'label' );

        $this->assertContains(
            'Users', $labels,
            'User accounts live in the Organization, so Users belongs under it.' );

        /* Given a Property, its group appears -- still without a Profile. */
        $withProperty = (array) $method->invoke( $controller, '', 'some-property-id' );

        $this->assertSame(
            array( 'Organization', 'Property' ), array_keys( $withProperty ) );
    }

    /** Every item declares the capability that gates it. */
    public function testEveryNavItemIsGated(): void
    {
        $controller = new \OWA\Module\Base\Controller\OrganizationProfile( array() );

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'getHierarchyNav' );
        $method->setAccessible( true );

        foreach ( (array) $method->invoke( $controller, '', 'p' ) as $group => $items ) {

            foreach ( $items as $item ) {

                $this->assertNotEmpty(
                    $item['capability'],
                    "$group / {$item['label']} names no capability, so it is offered to "
                    . 'anyone who can see the page.' );
            }
        }
    }

    public function testTheNavIsRenderedUnderTheControl(): void
    {
        $wrapper = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/options_hierarchy.php' );

        $this->assertStringContainsString( "include('site_control.php')", $wrapper );
        $this->assertStringContainsString( "include('hierarchy_nav.php')", $wrapper );

        $this->assertLessThan(
            strpos( $wrapper, 'hierarchy_nav.php' ),
            strpos( $wrapper, 'site_control.php' ),
            'The nav is above the tile; the tile is what says which thing the nav is about.' );
    }
    /**
     * Each tier's screens are separate pages, not sections of one.
     *
     * The site page used to stack three forms -- details, observation settings
     * and allowed users -- on one screen that saved in pieces, with nothing
     * saying which tier each belonged to. The access one is not even a Profile
     * concern: access is granted to a WEBSITE.
     */
    public function testTheProfileFormsAreSeparateScreens(): void
    {
        $controller = new \OWA\Module\Base\Controller\OrganizationProfile( array() );

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'getHierarchyNav' );
        $method->setAccessible( true );

        $nav = (array) $method->invoke( $controller, 'a-site', 'a-property' );

        $profile = array_column( $nav['Observation Profile'], 'label', 'do' );

        $this->assertSame( 'Details', $profile['base.sitesProfile'] ?? null );
        $this->assertSame( 'Observation Settings', $profile['base.profileSettings'] ?? null );
        $this->assertSame( 'Tracking Tag', $profile['base.sitesInvocation'] ?? null );
        $this->assertSame( 'Goals', $profile['base.optionsGoals'] ?? null );

        $property = array_column( $nav['Property'], 'label', 'do' );

        $this->assertSame( 'Details', $property['base.propertyProfile'] ?? null );
        $this->assertSame(
            'Property Access Management', $property['base.propertyAccess'] ?? null,
            'Access is granted to a website, so it belongs under the Property.' );

        $this->assertSame(
            'Details', array_column( $nav['Organization'], 'label', 'do' )['base.organizationProfile'] ?? null );
    }

    /**
     * The tile shows all three tiers even on a screen about a higher one.
     *
     * Editing the Organization carries no siteId, so the tile drew the
     * Organization over two blank lines -- which reads as nothing selected
     * rather than as a screen about a higher tier.
     */
    public function testAHigherTierScreenStillHasACurrentProfile(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $controller = new \OWA\Module\Base\Controller\OrganizationProfile( array() );

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'resolveCurrentSiteId' );
        $method->setAccessible( true );

        $this->assertNotEmpty(
            $method->invoke( $controller, '' ),
            'No Profile is resolved, so the tile renders two of its three tiers blank.' );

        $this->assertSame(
            'given-one', $method->invoke( $controller, 'given-one' ),
            'A siteId already in context must win over the fallback.' );
    }
    /**
     * Arriving from the control's edit link on another Property must move the
     * whole nav, not just the Property group.
     *
     * That link carries a propertyId and no siteId, so resolving "the current
     * Profile" fell through to the first site the user could see -- and the
     * tile and Profile group then described a different Property from the one
     * whose screen was open.
     */
    public function testAPropertyInContextPicksAProfileOfThatProperty(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $controller = new \OWA\Module\Base\Controller\PropertyProfile( array() );

        $method = new \ReflectionMethod( \OWA\Core\Controller::class, 'resolveCurrentSiteId' );
        $method->setAccessible( true );

        $allowed = new \ReflectionMethod( \OWA\Core\Controller::class, 'getSitesAllowedForCurrentUser' );
        $allowed->setAccessible( true );

        /* Every Profile of each Property -- the requirement is MEMBERSHIP, not
           a particular one, so recording only one per Property would assert
           something stricter than the code promises. */
        $byProperty = array();

        foreach ( (array) $allowed->invoke( $controller ) as $site ) {

            $parent = $site->get( 'property_id' );

            if ( $parent ) {
                $byProperty[ $parent ][] = $site->get( 'site_id' );
            }
        }

        if ( count( $byProperty ) < 2 ) {
            $this->markTestSkipped( 'Needs at least two parented Properties.' );
        }

        foreach ( $byProperty as $propertyId => $profiles ) {

            /* (string) because a numeric array key comes back as an int -- the
               same coercion that made the resolver's strict comparison miss. */
            $propertyId = (string) $propertyId;

            $this->assertContains(
                $method->invoke( $controller, '', $propertyId ), $profiles,
                'The Profile resolved does not belong to the Property in context, so the '
                . 'tile and the nav would describe a different website.' );
        }

        /* A siteId already in hand still wins -- the Property is only a
           fallback for arriving without one. */
        $this->assertSame(
            'explicit', $method->invoke( $controller, 'explicit', array_key_first( $byProperty ) ) );
    }

    /** Each screen says what it is for, in the hierarchy's words. */
    public function testEveryHierarchyScreenExplainsItself(): void
    {
        $screens = array(
            'organization_profile.php' => 'Organization',
            'property_profile.php'     => 'Property',
            'property_access.php'      => 'Property',
            'profile_settings.php'     => 'Observation Profile',
            'sites_addoredit.php'      => 'Observation Profile',
            'options_goals.php'        => 'Observation Profile',
        );

        foreach ( $screens as $template => $term ) {

            $body = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/' . $template );

            $this->assertStringContainsString(
                'owa_panelIntro', $body, "$template does not say what the screen is for." );

            $this->assertStringContainsString(
                $term, $body,
                "$template does not name the tier it belongs to, so its wording predates "
                . 'the hierarchy.' );

            /*
             * One headline. profile_settings carried the panel headline AND the
             * old "Site Settings" legend, so the screen showed two titles.
             */
            $this->assertLessThanOrEqual(
                1, substr_count( $body, '<legend>' ),
                "$template still has a legend competing with its headline." );
        }
    }
    /**
     * The screens speak the hierarchy's language, not the flat one.
     *
     * These pages describe Organizations, Properties and Observation Profiles.
     * Saying "website" or "site" in the prose leaves the old model showing
     * through in the one place it is most confusing -- the screens that exist
     * to teach the new one. "Access is granted to the website" was the example
     * that prompted this: it named a thing the UI no longer has.
     *
     * Only visible prose. Identifiers -- siteId, base.site, owa_siteControl --
     * are the wire and the code, and renaming those is a different change.
     */
    public function testTheScreensDoNotSpeakOfSitesOrWebsites(): void
    {
        $templates = array( 'organization_profile.php', 'property_profile.php',
                            'property_access.php', 'profile_settings.php',
                            'sites_addoredit.php' );

        foreach ( $templates as $template ) {

            $body = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/' . $template );

            /* Visible text only: strip PHP, HTML tags and comments. */
            $prose = preg_replace( '/<\?php.*?\?>/s', ' ', $body );
            $prose = preg_replace( '/<!--.*?-->/s', ' ', $prose );
            $prose = strip_tags( $prose );

            /*
             * One approved exception: naming the real-world thing a DOMAIN
             * points at. The rule is about not using "site" as a stand-in for a
             * Property or a Profile -- "access is granted to the website" named
             * a thing the UI does not have. "the domain of the website or
             * application being observed" names what a domain is, which is not
             * the same mistake and is what a person actually calls it.
             */
            $prose = str_replace( 'website or application being observed', '', $prose );

            $this->assertDoesNotMatchRegularExpression(
                '/\bweb ?sites?\b/i', $prose,
                "$template still says site or website in text a user reads. These screens "
                . 'describe Properties and Observation Profiles.' );
        }
    }
    /**
     * Nothing sits after a screen's save button.
     *
     * The Organization screen carried a "Manage users" link below its submit,
     * left over from before Users had a place in the nav. Anything after the
     * button reads as part of the form you just chose not to fill in.
     */
    public function testNothingFollowsTheSaveButton(): void
    {
        $templates = array( 'organization_profile.php', 'property_profile.php' );

        foreach ( $templates as $template ) {

            $body = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/' . $template );

            $after = substr( $body, strrpos( $body, 'type="submit"' ) );
            $after = preg_replace( '/<\?php.*?\?>/s', ' ', $after );

            $this->assertStringNotContainsString(
                '<a ', $after,
                "$template has a link after its save button. Navigation belongs in the "
                . 'hierarchy nav, not trailing the form.' );
        }
    }

    /**
     * One measure per screen.
     *
     * #panel is a plain div in a table cell, so with no width it collapsed to
     * whatever the widest field happened to be, and the intro wrapped at a
     * column that changed from screen to screen. Inner width:550px wrappers
     * then made the form narrower than the paragraph above it.
     */
    public function testThePaneSetsTheMeasureNotTheContent(): void
    {
        $css = (string) file_get_contents( OWA_DIR . 'modules/Base/css/owa.report.css' );

        $panel = substr( $css, strpos( $css, "\n.owa_hierarchyContent #panel {" ) );
        $panel = substr( $panel, 0, strpos( $panel, "\n}" ) );

        $this->assertStringContainsString(
            'max-width', $panel,
            'The pane shrink-wraps its content, so every screen wraps at a different column.' );

        foreach ( array( 'organization_profile.php', 'property_profile.php' ) as $template ) {

            $body = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/' . $template );

            $this->assertStringNotContainsString(
                'width:550px', $body,
                "$template sets its own width inside the pane, making the form narrower "
                . 'than the text above it.' );
        }
    }
    /**
     * Every settings screen states its full context on one line.
     *
     * The tile says where you are, but it is in the other column, and these
     * forms change one Property or one Profile -- the tracking id is the only
     * thing that tells two similarly named Profiles apart. Repeating it above
     * the heading puts the answer in the same line of sight as the fields.
     *
     * It renders only when there is a Profile to name: with just an
     * Organization there is no path to draw, and a one-item breadcrumb is
     * noise.
     */
    public function testEachScreenStatesItsFullContext(): void
    {
        $crumb = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/hierarchy_breadcrumb.php' );

        foreach ( array( 'organization', 'properties', 'profiles' ) as $tier ) {

            $this->assertStringContainsString(
                $tier, $crumb, "The context line does not walk the $tier tier." );
        }

        /*
         * Renders whenever there is anything to name. A single crumb is right
         * at tier 1: on Organization Details the Organization IS the whole
         * context, and suppressing it would leave that screen the only one
         * without a context line.
         */
        $this->assertStringContainsString(
            'if ( $owa_crumbs )', $crumb,
            'The context line should render whenever there is a tier to name.' );

        $wrapper = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/options_hierarchy.php' );

        $this->assertLessThan(
            strpos( $wrapper, 'view->subview' ),
            strpos( $wrapper, 'hierarchy_breadcrumb.php' ),
            'The context line belongs ABOVE the page heading, not under the form.' );

        /*
         * Most screens set only 'siteId', not 'params'. Requiring each
         * controller to remember both is how one screen ends up without its
         * context line, so the wrapper falls back.
         */
        $view = (string) file_get_contents( OWA_DIR . 'modules/Base/View/OptionsHierarchy.php' );

        $this->assertStringContainsString(
            "\$params['siteId'] = \$this->get( 'siteId' )", $view,
            'A screen that sets only siteId would render no context line.' );
    }

    /** One description per screen. */
    public function testNoScreenDescribesItselfTwice(): void
    {
        foreach ( array( 'organization_profile.php', 'property_profile.php',
                         'property_access.php', 'profile_settings.php' ) as $template ) {

            $body = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/' . $template );

            $this->assertSame(
                1, substr_count( $body, 'owa_panelIntro' ),
                "$template has more than one intro." );

            /*
             * Counted only ABOVE the first form. A .description lower down can
             * be a field's help text or an alternative branch, neither of which
             * competes with the intro -- Property Access had exactly that in an
             * else, and counting the whole file called it a duplicate.
             */
            $head = substr( $body, 0, strpos( $body, '<form' ) ?: strlen( $body ) );

            $this->assertSame(
                0, substr_count( $head, 'class="description"' ),
                "$template describes itself twice before its first form -- Property Access "
                . 'carried both the intro and the old inline description.' );
        }
    }
    /**
     * The context line stops at the tier its screen is about.
     *
     * Organization Details edits an Organization, so naming a Property and a
     * Profile under it describes a scope the form does not touch. Property
     * Access is worse: it edits grants covering EVERY Profile, so trailing one
     * Profile's id says the opposite of what the screen does.
     */
    public function testTheContextLineStopsAtTheScreensTier(): void
    {
        $expected = array(
            'OrganizationProfile' => 1, 'Users'           => 1,
            'PropertyProfile'     => 2, 'PropertyAccess'  => 2,
            'SitesProfile'        => 3, 'ProfileSettings' => 3,
            'SitesInvocation'     => 3, 'OptionsGoals'    => 3,
        );

        foreach ( $expected as $controller => $tier ) {

            $src = (string) file_get_contents(
                OWA_DIR . "modules/Base/Controller/{$controller}.php" );

            $this->assertStringContainsString(
                "\$this->set( 'hierarchy_tier', {$tier} )", $src,
                "$controller does not declare its tier, so its context line would claim a "
                . 'scope its form does not edit.' );
        }

        $crumb = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/hierarchy_breadcrumb.php' );

        $this->assertStringContainsString( '$owa_tier >= 2', $crumb );
        $this->assertStringContainsString( '$owa_tier >= 3', $crumb );

        /*
         * Defaulted rather than required. A screen that forgets to declare one
         * should show the most specific line, not none -- an absent context
         * line is harder to notice than an over-long one.
         */
        $view = (string) file_get_contents( OWA_DIR . 'modules/Base/View/OptionsHierarchy.php' );

        $this->assertStringContainsString( "hierarchy_tier' ) ?: 3", $view );
    }
    /**
     * A grid inside the pane must not out-weigh the page it sits on.
     *
     * owa.admin.css gives .management a 1px #9f9f9f box, 14px cells and
     * UNSIZED headers, so the column labels rendered larger than the heading
     * above them and the grid read as heavier than its own screen.
     */
    public function testGridsAreQuieterThanTheHeadingAboveThem(): void
    {
        $css = (string) file_get_contents( OWA_DIR . 'modules/Base/css/owa.report.css' );

        $this->assertStringContainsString(
            '.owa_hierarchyContent #panel table.management th', $css,
            'Grid headers are unsized inside the pane, so they inherit larger than the '
            . 'page heading.' );

        $headline = substr( $css, strpos( $css, '.owa_hierarchyContent .panel_headline {' ) );
        $headline = substr( $headline, 0, strpos( $headline, "\n}" ) );

        preg_match( '/font-size:\s*(\d+)px/', $headline, $h );

        $th = substr( $css, strpos( $css, '.owa_hierarchyContent #panel table.management th' ) );
        $th = substr( $th, 0, strpos( $th, "\n}" ) );

        preg_match( '/font-size:\s*(\d+)px/', $th, $t );

        $this->assertNotEmpty( $h, 'the heading has no size to compare against' );
        $this->assertNotEmpty( $t, 'the grid header has no size of its own' );

        $this->assertLessThan(
            (int) $h[1], (int) $t[1],
            'A column label is larger than the page heading it sits under.' );
    }

    /** Goals renders in the pane like every other hierarchy screen. */
    public function testGoalsUsesThePaneConvention(): void
    {
        $body = (string) file_get_contents( OWA_DIR . 'modules/Base/templates/options_goals.php' );

        $this->assertStringContainsString(
            '<div id="panel">', $body,
            'Goals uses subview_content, so none of the pane styling reaches it and it '
            . 'looks like a different application.' );

        $this->assertStringNotContainsString( 'subview_content', $body );
    }
    /**
     * The fan-out is where you add a tier, not just choose one.
     *
     * It replaced the Tracked Sites roster, which had an "Add New" link. With
     * the roster gone and no link here, adding a Profile was reachable only by
     * typing the action into the URL -- and adding a Property was not possible
     * at all: PropertyProfile always loaded an existing row.
     */
    public function testTheFanOutCanAddAPropertyAndAProfile(): void
    {
        $template = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/site_control.php' );

        $this->assertSame(
            2, substr_count( $template, 'owa_siteControlAdd' ),
            'Both the Properties and the Profiles column need a way to add one.' );

        /* Add is a different route from edit: no id. */
        $this->assertStringContainsString(
            "array( 'do' => 'base.propertyProfile' )", $template,
            'The Properties column has no add link, only per-row edit links.' );

        $this->assertStringContainsString(
            "array( 'do' => 'base.sitesProfile' )", $template,
            'The Profiles column has no add link.' );
    }

    /**
     * One form serves add and edit, so the two cannot drift.
     *
     * PropertyEdit must not require the row to exist when creating -- an
     * entityExists check on an absent id makes adding impossible, which is the
     * shape the screen shipped in.
     */
    public function testAPropertyCanBeAddedNotOnlyEdited(): void
    {
        $controller = new \OWA\Module\Base\Controller\PropertyProfile( array() );
        $controller->action();

        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $data->setAccessible( true );

        $this->assertEmpty(
            ( (array) $data->getValue( $controller ) )['propertyId'] ?? null,
            'The add form arrived carrying an id, so it would edit something.' );

        $src = (string) file_get_contents( OWA_DIR . 'modules/Base/Controller/PropertyEdit.php' );

        $this->assertStringContainsString(
            "if ( \$this->getParam( 'propertyId' ) ) {", $src,
            'entityExists runs unconditionally, so a create can never validate.' );

        $this->assertStringContainsString(
            "ensureOrganization()", $src,
            'A new Property with no Organization would sit outside the hierarchy.' );
    }
}
