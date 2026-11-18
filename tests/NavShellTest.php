<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The navigation: the rail of sections, the column beside it, and a
 * Profile's tabs (Template::navRail(), reportColumn(), settingsColumn(),
 * profileTabs(); nav_shell.php draws them).
 *
 * Signed in as an admin, who has every capability, so these are about what is
 * listed and what is current rather than about access.
 */
final class NavShellTest extends TestCase
{
    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    private function template(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' );
    }

    private function nav(): array
    {
        return [
            'Dashboard' => [
                'ref' => [ 'do' => 'base.report', 'reportId' => 'dashboard' ],
                'anchortext' => 'Dashboard', 'priviledge' => 'view_reports', 'icon_class' => 'fa fa-tachometer-alt',
            ],
            'Content' => [
                'ref' => [ 'do' => 'base.report', 'reportId' => 'content' ],
                'anchortext' => 'Content', 'priviledge' => 'view_reports', 'icon_class' => 'fa fa-newspaper',
                'subgroup' => [
                    [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ],
                           'anchortext' => 'Pages', 'priviledge' => 'view_reports' ],
                    [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'entry-pages' ],
                           'anchortext' => 'Entry Pages', 'priviledge' => 'view_reports' ],
                ],
            ],
            'Custom Reports' => [
                'ref' => [ 'do' => 'base.customReports' ],
                'anchortext' => 'Custom Reports', 'priviledge' => 'view_site_list', 'icon_class' => 'fa fa-th-list',
                'overview_label' => 'All reports',
                'keeps_column' => true,
                'current_for' => [
                    [ 'do' => 'base.report', 'reportId' => 'custom-*' ],
                    [ 'do' => 'base.customReportEdit' ],
                ],
                'subgroup' => [
                    [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'custom-7' ],
                           'anchortext' => 'Blog launch week', 'priviledge' => 'view_reports',
                           'icon_class' => 'fas fa-star', 'heading' => 'Favorites' ],
                    [ 'ref' => [ 'do' => 'base.customReports' ],
                           'anchortext' => 'All reports again', 'priviledge' => 'view_site_list' ],
                ],
            ],
        ];
    }

    private function labels( array $list ): array
    {
        return array_column( $list, 'label' );
    }

    public function testTheRailIsTheSectionsThenSettingsAtTheFoot(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.report', 'reportId' => 'pages' ] );

        $this->assertSame( [ 'Dashboard', 'Content', 'Custom Reports', 'Settings' ], $this->labels( $rail ) );
        $this->assertSame( [ false, false, false, true ], array_column( $rail, 'foot' ) );
        $this->assertSame( 'fas fa-cog', $rail[3]['icon'] );
    }

    public function testASectionListsItsOwnReportFirstAsOverview(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.report', 'reportId' => 'pages' ] );

        $this->assertSame( [ 'Overview', 'Pages', 'Entry Pages' ], $this->labels( $rail[1]['items'] ) );
    }

    /** An item going where the section does is not listed twice. */
    public function testASectionNamesItsOwnEntryAndDropsADuplicate(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.customReports' ] );

        $this->assertSame( [ 'All reports', 'Blog launch week' ], $this->labels( $rail[2]['items'] ) );
        $this->assertTrue( $rail[2]['items'][0]['current'] );
    }

    /** Items under a heading are their own group of the column, with their icons. */
    public function testTheColumnGroupsItemsByHeading(): void
    {
        $rail   = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.customReports' ] );
        $column = \OWA\Core\Template::reportColumn( $rail );

        $this->assertSame( [ '', 'Favorites' ], array_column( $column['groups'], 'heading' ) );
        $this->assertSame( [ 'All reports' ], $this->labels( $column['groups'][0]['items'] ) );
        $this->assertSame( [ 'Blog launch week' ], $this->labels( $column['groups'][1]['items'] ) );
        $this->assertSame( 'fas fa-star', $column['groups'][1]['items'][0]['icon'] );
    }

    /** keeps_column: Custom Reports has its column with no favorites under it. */
    public function testASectionThatKeepsItsColumnHasOneWithNothingUnderIt(): void
    {
        $nav = $this->nav();
        $nav['Custom Reports']['subgroup'] = [];

        $rail   = $this->template()->navRail( $nav, 'p1', [ 'do' => 'base.customReports' ] );
        $column = \OWA\Core\Template::reportColumn( $rail );

        $this->assertSame( [ 'All reports' ], $this->labels( $column['groups'][0]['items'] ) );
        $this->assertCount( 1, $column['groups'] );
    }

    /**
     * current_for: Custom Reports is current on any custom report, favorite or
     * not, and on its builders; a value ending in * matches by prefix.
     */
    public function testASectionIsCurrentOnTheScreensItAnswersFor(): void
    {
        $current = function ( array $params ) {
            $rail = $this->template()->navRail( $this->nav(), 'p1', $params );
            return $rail[2]['current'];
        };

        $this->assertTrue( $current( [ 'do' => 'base.report', 'reportId' => 'custom-99' ] ) );
        $this->assertTrue( $current( [ 'do' => 'base.customReportEdit', 'customReportId' => '4' ] ) );
        $this->assertFalse( $current( [ 'do' => 'base.report', 'reportId' => 'pages' ] ) );
        $this->assertFalse( $current( [ 'do' => 'base.report', 'reportId' => 'xcustom-1' ] ) );
        $this->assertFalse( $current( [ 'do' => 'base.report' ] ) );
    }

    public function testASectionWithNoReportsUnderItHasNoItems(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.report', 'reportId' => 'dashboard' ] );

        $this->assertTrue( $rail[0]['current'] );
        $this->assertSame( [], $rail[0]['items'] );
        $this->assertNull( \OWA\Core\Template::reportColumn( $rail ), 'Dashboard opens no column' );
    }

    public function testTheColumnIsTheCurrentSectionsReports(): void
    {
        $rail   = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.report', 'reportId' => 'entry-pages' ] );
        $column = \OWA\Core\Template::reportColumn( $rail );

        $this->assertSame( [ false, true, false, false ], array_column( $rail, 'current' ) );
        $this->assertSame( 'Content', $column['title'] );
        $this->assertSame( [ 'Overview', 'Pages', 'Entry Pages' ], $this->labels( $column['groups'][0]['items'] ) );
        $this->assertSame( [ false, false, true ], array_column( $column['groups'][0]['items'], 'current' ) );
    }

    /** Report links are of the Property in context, which a settings screen does not carry as link state. */
    public function testReportLinksNameTheProperty(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'prop-42', [ 'do' => 'base.myProfile' ], '', true );

        $this->assertStringContainsString( 'prop-42', $rail[1]['href'] );
        $this->assertStringContainsString( 'prop-42', $rail[1]['items'][1]['href'] );
    }

    /**
     * Switching the Property and then opening Settings showed the first
     * Property instead. The gear takes the Property and the period from the
     * link state, so coming back from Settings keeps the period, and nothing
     * else of it.
     */
    public function testTheSettingsGearNamesTheProperty(): void
    {
        $t = $this->template();
        $t->caller_params['link_state'] = [ 'propertyId' => 'prop-42', 'period' => 'last_seven_days' ];

        $rail = $t->navRail( $this->nav(), 'prop-42', [ 'do' => 'base.report', 'reportId' => 'pages' ] );

        $this->assertSame( 'settings', $rail[3]['id'] );
        $this->assertStringContainsString( 'propertyId=prop-42', $rail[3]['href'] );
        $this->assertStringContainsString( 'period=last_seven_days', $rail[3]['href'] );

        $rail = $this->template()->navRail( $this->nav(), '', [ 'do' => 'base.report' ] );

        $this->assertStringNotContainsString( 'propertyId', $rail[3]['href'] );
    }

    /** add_state takes the keys of the link state to add, as well as true for all of it. */
    public function testMakeLinkAddsOnlyTheStateKeysAskedFor(): void
    {
        $t = $this->template();
        $t->caller_params['link_state'] = [ 'propertyId' => 'p1', 'period' => 'today' ];

        $link = $t->makeLink( [ 'do' => 'base.users' ], [ 'propertyId' ] );

        $this->assertStringContainsString( 'propertyId=p1', $link );
        $this->assertStringNotContainsString( 'period', $link );

        $this->assertStringContainsString( 'period=today', $t->makeLink( [ 'do' => 'base.users' ], true ) );
        $this->assertStringNotContainsString( 'propertyId', $t->makeLink( [ 'do' => 'base.users' ] ) );
        $this->assertStringContainsString( 'propertyId=p9',
            $t->makeLink( [ 'do' => 'base.users', 'propertyId' => 'p9' ], [ 'propertyId' ] ),
            'the link\'s own params win over the state' );
    }

    /**
     * On a screen that keeps state in its links (View::keepStateInLinks()), a
     * link asking for none gets those keys, and one asking for its own keeps
     * to them. A settings screen's links and form actions are made this way.
     */
    public function testAScreenThatKeepsStateGivesItToEveryLink(): void
    {
        $t = $this->template();
        $t->caller_params['link_state'] = [ 'propertyId' => 'p1', 'period' => 'today', 'siteId' => 's1' ];
        $t->caller_params['keep_state'] = [ 'propertyId', 'period' ];

        $link = $t->makeLink( [ 'do' => 'base.propertyEdit' ] );

        $this->assertStringContainsString( 'propertyId=p1', $link );
        $this->assertStringContainsString( 'period=today', $link );
        $this->assertStringNotContainsString( 'siteId', $link, 'only the keys kept' );

        $this->assertStringNotContainsString( 'period',
            $t->makeLink( [ 'do' => 'base.users' ], [ 'propertyId' ] ), 'a link asking for its own keys' );
        $this->assertStringContainsString( 'siteId=s1',
            $t->makeLink( [ 'do' => 'base.users' ], true ), 'and one asking for all of it' );
    }

    public function testOnASettingsScreenOnlySettingsIsCurrent(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', [ 'do' => 'base.customReports' ], '', true );

        $this->assertSame( [ false, false, false, true ], array_column( $rail, 'current' ) );
    }

    private function hierarchyNav(): array
    {
        return [
            'My Preferences' => [
                [ 'do' => 'base.myProfile', 'label' => 'User profile', 'params' => [], 'capability' => 'view_site_list' ],
            ],
            'Instance' => [
                [ 'do' => 'base.optionsGeneral', 'label' => 'General', 'params' => [], 'capability' => 'edit_settings' ],
            ],
            'Property' => [
                [ 'do' => 'base.propertyProfile', 'label' => 'Details', 'params' => [ 'propertyId' => 'p1' ], 'capability' => 'edit_sites' ],
            ],
            'Profiles' => [
                [ 'do' => 'base.sitesInvocation', 'label' => 'Main profile', 'params' => [ 'siteId' => 's1' ],
                       'capability' => 'edit_sites',
                       'current_for' => [ 'base.sitesInvocation', 'base.profileSettings', 'base.sitesProfile', 'test_fixture.profileTab' ] ],
                [ 'do' => 'base.sitesProfile', 'label' => 'Add profile', 'params' => [ 'propertyId' => 'p1' ], 'capability' => 'edit_sites' ],
            ],
            'Observation Profile' => [
                [ 'do' => 'base.sitesInvocation', 'label' => 'Tracking tag', 'params' => [ 'siteId' => 's1' ], 'capability' => 'edit_sites' ],
                [ 'do' => 'base.profileSettings', 'label' => 'Observation settings', 'params' => [ 'siteId' => 's1' ], 'capability' => 'edit_sites' ],
                [ 'do' => 'base.sitesProfile', 'label' => 'Details', 'params' => [ 'siteId' => 's1', 'edit' => true ], 'capability' => 'edit_sites' ],
                // As a module registers one: no params of its own.
                [ 'do' => 'test_fixture.profileTab', 'label' => 'Fixture tab', 'params' => [], 'capability' => 'edit_sites' ],
            ],
        ];
    }

    public function testTheSettingsColumnIsHeadedByLevelWithoutTheProfilesPages(): void
    {
        $groups = $this->template()->settingsColumn( $this->hierarchyNav(), [ 'do' => 'base.myProfile' ] );

        $this->assertSame( [ 'My preferences', 'Server configuration', 'Property', 'Profiles' ],
            array_column( $groups, 'heading' ) );
        $this->assertSame( [ 'Main profile', 'Add profile' ], $this->labels( $groups[3]['items'] ) );
        $this->assertTrue( $groups[0]['items'][0]['current'] );
    }

    /**
     * The column's entries carry the Property and the period in the link
     * state; one naming its own Property keeps it. They carried the Property
     * alone, so the period was gone one click past the gear.
     */
    public function testSettingsEntriesCarryThePropertyAndPeriodInTheLinkState(): void
    {
        $t = $this->template();
        $t->caller_params['link_state'] = [ 'propertyId' => 'p7', 'period' => 'last_seven_days', 'siteId' => 's-state' ];

        $groups = $t->settingsColumn( $this->hierarchyNav(), [ 'do' => 'base.myProfile' ] );
        $hrefs  = array_column( array_merge( ...array_column( $groups, 'items' ) ), 'href', 'label' );

        $this->assertStringContainsString( 'propertyId=p7', $hrefs['User profile'] );
        $this->assertStringContainsString( 'propertyId=p7', $hrefs['General'] );
        $this->assertStringContainsString( 'propertyId=p1', $hrefs['Details'] );

        foreach ( $hrefs as $label => $href ) {

            $this->assertStringContainsString( 'period=last_seven_days', $href, $label );
            $this->assertStringNotContainsString( 's-state', $href, $label . ': not the Profile' );
        }
        $this->assertTrue( $groups[0]['items'][0]['current'], 'the extra param does not change which entry is current' );

        $groups = $this->template()->settingsColumn( $this->hierarchyNav(), [ 'do' => 'base.myProfile' ] );

        $this->assertStringNotContainsString( 'propertyId', $groups[0]['items'][0]['href'] );
    }

    /** A Profile's entry is current on each of its tabs, a module's included. */
    public function testAProfilesEntryIsCurrentOnEveryTab(): void
    {
        foreach ( [ 'base.sitesInvocation', 'base.profileSettings', 'test_fixture.profileTab' ] as $do ) {

            $groups = $this->template()->settingsColumn( $this->hierarchyNav(), [ 'do' => $do, 'siteId' => 's1' ] );

            $this->assertSame( [ true, false ], array_column( $groups[3]['items'], 'current' ), $do );
        }
    }

    /** Add profile and a Profile's Details are both base.sitesProfile; only one is marked. */
    public function testOneEntryIsCurrentWhenTwoClaimTheScreen(): void
    {
        $details = $this->template()->settingsColumn( $this->hierarchyNav(),
            [ 'do' => 'base.sitesProfile', 'siteId' => 's1', 'edit' => '1', 'propertyId' => 'p1' ] );

        $this->assertSame( [ true, false ], array_column( $details[3]['items'], 'current' ) );

        $add = $this->template()->settingsColumn( $this->hierarchyNav(),
            [ 'do' => 'base.sitesProfile', 'propertyId' => 'p1' ] );

        $this->assertSame( [ false, true ], array_column( $add[3]['items'], 'current' ) );
    }

    public function testAProfilesPagesAreTabsInOrder(): void
    {
        $tabs = $this->template()->profileTabs( $this->hierarchyNav(), [ 'do' => 'base.profileSettings', 'siteId' => 's1' ] );

        $this->assertSame( [ 'Tracking tag', 'Observation settings', 'Details', 'Fixture tab' ], $this->labels( $tabs ) );
        $this->assertSame( [ false, true, false, false ], array_column( $tabs, 'current' ) );
        $this->assertStringContainsString( 's1', $tabs[3]['href'], "a module's tab is of this Profile" );
    }

    public function testTabsAreOnlyOnAProfilesPage(): void
    {
        $this->assertSame( [], $this->template()->profileTabs( $this->hierarchyNav(),
            [ 'do' => 'base.propertyProfile', 'propertyId' => 'p1' ] ) );
    }

    public function testEditIsNotPartOfWhichScreenItIs(): void
    {
        $this->assertTrue( \OWA\Core\Template::settingsEntryIsCurrent(
            [ 'do' => 'base.sitesProfile', 'params' => [ 'siteId' => 's1', 'edit' => true ] ],
            [ 'do' => 'base.sitesProfile', 'siteId' => 's1' ] ) );
    }

    public function testASettingsScreensPropertyIsTheOneItsPropertyPagesAreOf(): void
    {
        $this->assertSame( 'p1', \OWA\Module\Base\View\OptionsHierarchy::propertyInContext(
            $this->hierarchyNav(), [ 'do' => 'base.optionsGeneral' ] ) );

        $this->assertSame( 'p9', \OWA\Module\Base\View\OptionsHierarchy::propertyInContext(
            $this->hierarchyNav(), [ 'propertyId' => 'p9' ] ), 'the one the screen names wins' );

        $this->assertSame( '', \OWA\Module\Base\View\OptionsHierarchy::propertyInContext( [], [] ) );
    }

    public function testTheRailIsCheckedAgainstAProfileOfTheProperty(): void
    {
        $hierarchy = [ 'properties' => [
            [ 'id' => 'p0', 'profiles' => [ [ 'site_id' => 'other' ] ] ],
            [ 'id' => 'p1', 'profiles' => [ [ 'site_id' => 's1' ], [ 'site_id' => 's2' ] ] ],
        ] ];

        $this->assertSame( 's1', \OWA\Module\Base\View\OptionsHierarchy::accessSiteFor( $hierarchy, 'p1', '' ) );
        $this->assertSame( 's2', \OWA\Module\Base\View\OptionsHierarchy::accessSiteFor( $hierarchy, 'p1', 's2' ),
            "the screen's own Profile" );
        $this->assertSame( '', \OWA\Module\Base\View\OptionsHierarchy::accessSiteFor( $hierarchy, 'p3', '' ) );
    }

    /** The Events report is in the navigation, under Visitors, last. */
    public function testEventsIsUnderVisitors(): void
    {
        $nav    = (array) \OWA\Core\ReportController::reportNavigation( '' );
        $labels = array_map( static function ( $link ) {
            return $link['anchortext'] ?? '';
        }, array_values( (array) ( $nav['Visitors']['subgroup'] ?? [] ) ) );

        $this->assertSame( 'Events', end( $labels ) );
    }

    /**
     * Every fixed flex basis the rail and column are given comes with the
     * same width. The nav sits in a table cell sized to its content, and
     * Safari and Firefox size that cell from widths, not flex bases: with a
     * basis alone the cell was narrower than the column and the settings page
     * drew over it. CI's Chromium lays it out either way, so this is checked
     * on the stylesheet.
     */
    public function testTheRailAndColumnHaveTheWidthOfTheirFlexBasis(): void
    {
        $css = (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/css/owa.shell.css' );
        $css = preg_replace( '#/\*.*?\*/#s', '', $css );

        preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

        $checked = 0;
        $missing = [];

        foreach ( $rules as $rule ) {

            $selector = trim( $rule[1] );

            if ( ! preg_match( '/\.owa_(rail|navColumn)(?![A-Za-z])/', $selector ) ) {
                continue;
            }

            if ( ! preg_match( '/flex(?:-basis)?:\s*(?:0 0 )?(\d+px)\s*;/', $rule[2], $basis ) ) {
                continue;
            }

            $checked++;

            if ( ! preg_match( '/(?<![-\w])width:\s*' . preg_quote( $basis[1], '/' ) . '\s*;/', $rule[2] ) ) {
                $missing[] = $selector . ' (' . $basis[1] . ')';
            }
        }

        // The rail, the column, the hidden column, and the hidden column on a narrow screen.
        $this->assertSame( 4, $checked, 'the rules this reads have moved' );
        $this->assertSame( [], $missing, 'a flex basis with no width: ' . implode( ', ', $missing ) );
    }

}
