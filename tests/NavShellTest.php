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
        return array(
            'Dashboard' => array(
                'ref' => array( 'do' => 'base.report', 'reportId' => 'dashboard' ),
                'anchortext' => 'Dashboard', 'priviledge' => 'view_reports', 'icon_class' => 'fa fa-tachometer-alt',
            ),
            'Content' => array(
                'ref' => array( 'do' => 'base.report', 'reportId' => 'content' ),
                'anchortext' => 'Content', 'priviledge' => 'view_reports', 'icon_class' => 'fa fa-newspaper',
                'subgroup' => array(
                    array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'pages' ),
                           'anchortext' => 'Pages', 'priviledge' => 'view_reports' ),
                    array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'entry-pages' ),
                           'anchortext' => 'Entry Pages', 'priviledge' => 'view_reports' ),
                ),
            ),
            'Custom Reports' => array(
                'ref' => array( 'do' => 'base.customReports' ),
                'anchortext' => 'Custom Reports', 'priviledge' => 'view_site_list', 'icon_class' => 'fa fa-th-list',
                'overview_label' => 'All reports',
                'keeps_column' => true,
                'current_for' => array(
                    array( 'do' => 'base.report', 'reportId' => 'custom-*' ),
                    array( 'do' => 'base.customReportEdit' ),
                ),
                'subgroup' => array(
                    array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'custom-7' ),
                           'anchortext' => 'Blog launch week', 'priviledge' => 'view_reports',
                           'icon_class' => 'fas fa-star', 'heading' => 'Favorites' ),
                    array( 'ref' => array( 'do' => 'base.customReports' ),
                           'anchortext' => 'All reports again', 'priviledge' => 'view_site_list' ),
                ),
            ),
        );
    }

    private function labels( array $list ): array
    {
        return array_column( $list, 'label' );
    }

    public function testTheRailIsTheSectionsThenSettingsAtTheFoot(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.report', 'reportId' => 'pages' ) );

        $this->assertSame( array( 'Dashboard', 'Content', 'Custom Reports', 'Settings' ), $this->labels( $rail ) );
        $this->assertSame( array( false, false, false, true ), array_column( $rail, 'foot' ) );
        $this->assertSame( 'fas fa-cog', $rail[3]['icon'] );
    }

    public function testASectionListsItsOwnReportFirstAsOverview(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.report', 'reportId' => 'pages' ) );

        $this->assertSame( array( 'Overview', 'Pages', 'Entry Pages' ), $this->labels( $rail[1]['items'] ) );
    }

    /** An item going where the section does is not listed twice. */
    public function testASectionNamesItsOwnEntryAndDropsADuplicate(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.customReports' ) );

        $this->assertSame( array( 'All reports', 'Blog launch week' ), $this->labels( $rail[2]['items'] ) );
        $this->assertTrue( $rail[2]['items'][0]['current'] );
    }

    /** Items under a heading are their own group of the column, with their icons. */
    public function testTheColumnGroupsItemsByHeading(): void
    {
        $rail   = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.customReports' ) );
        $column = \OWA\Core\Template::reportColumn( $rail );

        $this->assertSame( array( '', 'Favorites' ), array_column( $column['groups'], 'heading' ) );
        $this->assertSame( array( 'All reports' ), $this->labels( $column['groups'][0]['items'] ) );
        $this->assertSame( array( 'Blog launch week' ), $this->labels( $column['groups'][1]['items'] ) );
        $this->assertSame( 'fas fa-star', $column['groups'][1]['items'][0]['icon'] );
    }

    /** keeps_column: Custom Reports has its column with no favorites under it. */
    public function testASectionThatKeepsItsColumnHasOneWithNothingUnderIt(): void
    {
        $nav = $this->nav();
        $nav['Custom Reports']['subgroup'] = array();

        $rail   = $this->template()->navRail( $nav, 'p1', array( 'do' => 'base.customReports' ) );
        $column = \OWA\Core\Template::reportColumn( $rail );

        $this->assertSame( array( 'All reports' ), $this->labels( $column['groups'][0]['items'] ) );
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

        $this->assertTrue( $current( array( 'do' => 'base.report', 'reportId' => 'custom-99' ) ) );
        $this->assertTrue( $current( array( 'do' => 'base.customReportEdit', 'customReportId' => '4' ) ) );
        $this->assertFalse( $current( array( 'do' => 'base.report', 'reportId' => 'pages' ) ) );
        $this->assertFalse( $current( array( 'do' => 'base.report', 'reportId' => 'xcustom-1' ) ) );
        $this->assertFalse( $current( array( 'do' => 'base.report' ) ) );
    }

    public function testASectionWithNoReportsUnderItHasNoItems(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.report', 'reportId' => 'dashboard' ) );

        $this->assertTrue( $rail[0]['current'] );
        $this->assertSame( array(), $rail[0]['items'] );
        $this->assertNull( \OWA\Core\Template::reportColumn( $rail ), 'Dashboard opens no column' );
    }

    public function testTheColumnIsTheCurrentSectionsReports(): void
    {
        $rail   = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.report', 'reportId' => 'entry-pages' ) );
        $column = \OWA\Core\Template::reportColumn( $rail );

        $this->assertSame( array( false, true, false, false ), array_column( $rail, 'current' ) );
        $this->assertSame( 'Content', $column['title'] );
        $this->assertSame( array( 'Overview', 'Pages', 'Entry Pages' ), $this->labels( $column['groups'][0]['items'] ) );
        $this->assertSame( array( false, false, true ), array_column( $column['groups'][0]['items'], 'current' ) );
    }

    /** Report links are of the Property in context, which a settings screen does not carry as link state. */
    public function testReportLinksNameTheProperty(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'prop-42', array( 'do' => 'base.myProfile' ), '', true );

        $this->assertStringContainsString( 'prop-42', $rail[1]['href'] );
        $this->assertStringContainsString( 'prop-42', $rail[1]['items'][1]['href'] );
    }

    public function testOnASettingsScreenOnlySettingsIsCurrent(): void
    {
        $rail = $this->template()->navRail( $this->nav(), 'p1', array( 'do' => 'base.customReports' ), '', true );

        $this->assertSame( array( false, false, false, true ), array_column( $rail, 'current' ) );
    }

    private function hierarchyNav(): array
    {
        return array(
            'My Preferences' => array(
                array( 'do' => 'base.myProfile', 'label' => 'User profile', 'params' => array(), 'capability' => 'view_site_list' ),
            ),
            'Instance' => array(
                array( 'do' => 'base.optionsGeneral', 'label' => 'General', 'params' => array(), 'capability' => 'edit_settings' ),
            ),
            'Property' => array(
                array( 'do' => 'base.propertyProfile', 'label' => 'Details', 'params' => array( 'propertyId' => 'p1' ), 'capability' => 'edit_sites' ),
            ),
            'Profiles' => array(
                array( 'do' => 'base.sitesInvocation', 'label' => 'Main profile', 'params' => array( 'siteId' => 's1' ),
                       'capability' => 'edit_sites',
                       'current_for' => array( 'base.sitesInvocation', 'base.profileSettings', 'base.sitesProfile', 'hello.profileTab' ) ),
                array( 'do' => 'base.sitesProfile', 'label' => 'Add profile', 'params' => array( 'propertyId' => 'p1' ), 'capability' => 'edit_sites' ),
            ),
            'Observation Profile' => array(
                array( 'do' => 'base.sitesInvocation', 'label' => 'Tracking tag', 'params' => array( 'siteId' => 's1' ), 'capability' => 'edit_sites' ),
                array( 'do' => 'base.profileSettings', 'label' => 'Observation settings', 'params' => array( 'siteId' => 's1' ), 'capability' => 'edit_sites' ),
                array( 'do' => 'base.sitesProfile', 'label' => 'Details', 'params' => array( 'siteId' => 's1', 'edit' => true ), 'capability' => 'edit_sites' ),
                // As a module registers one: no params of its own.
                array( 'do' => 'hello.profileTab', 'label' => 'Hello', 'params' => array(), 'capability' => 'edit_sites' ),
            ),
        );
    }

    public function testTheSettingsColumnIsHeadedByLevelWithoutTheProfilesPages(): void
    {
        $groups = $this->template()->settingsColumn( $this->hierarchyNav(), array( 'do' => 'base.myProfile' ) );

        $this->assertSame( array( 'My preferences', 'Server configuration', 'Property', 'Profiles' ),
            array_column( $groups, 'heading' ) );
        $this->assertSame( array( 'Main profile', 'Add profile' ), $this->labels( $groups[3]['items'] ) );
        $this->assertTrue( $groups[0]['items'][0]['current'] );
    }

    /** A Profile's entry is current on each of its tabs, a module's included. */
    public function testAProfilesEntryIsCurrentOnEveryTab(): void
    {
        foreach ( array( 'base.sitesInvocation', 'base.profileSettings', 'hello.profileTab' ) as $do ) {

            $groups = $this->template()->settingsColumn( $this->hierarchyNav(), array( 'do' => $do, 'siteId' => 's1' ) );

            $this->assertSame( array( true, false ), array_column( $groups[3]['items'], 'current' ), $do );
        }
    }

    /** Add profile and a Profile's Details are both base.sitesProfile; only one is marked. */
    public function testOneEntryIsCurrentWhenTwoClaimTheScreen(): void
    {
        $details = $this->template()->settingsColumn( $this->hierarchyNav(),
            array( 'do' => 'base.sitesProfile', 'siteId' => 's1', 'edit' => '1', 'propertyId' => 'p1' ) );

        $this->assertSame( array( true, false ), array_column( $details[3]['items'], 'current' ) );

        $add = $this->template()->settingsColumn( $this->hierarchyNav(),
            array( 'do' => 'base.sitesProfile', 'propertyId' => 'p1' ) );

        $this->assertSame( array( false, true ), array_column( $add[3]['items'], 'current' ) );
    }

    public function testAProfilesPagesAreTabsInOrder(): void
    {
        $tabs = $this->template()->profileTabs( $this->hierarchyNav(), array( 'do' => 'base.profileSettings', 'siteId' => 's1' ) );

        $this->assertSame( array( 'Tracking tag', 'Observation settings', 'Details', 'Hello' ), $this->labels( $tabs ) );
        $this->assertSame( array( false, true, false, false ), array_column( $tabs, 'current' ) );
        $this->assertStringContainsString( 's1', $tabs[3]['href'], "a module's tab is of this Profile" );
    }

    public function testTabsAreOnlyOnAProfilesPage(): void
    {
        $this->assertSame( array(), $this->template()->profileTabs( $this->hierarchyNav(),
            array( 'do' => 'base.propertyProfile', 'propertyId' => 'p1' ) ) );
    }

    public function testEditIsNotPartOfWhichScreenItIs(): void
    {
        $this->assertTrue( \OWA\Core\Template::settingsEntryIsCurrent(
            array( 'do' => 'base.sitesProfile', 'params' => array( 'siteId' => 's1', 'edit' => true ) ),
            array( 'do' => 'base.sitesProfile', 'siteId' => 's1' ) ) );
    }

    public function testASettingsScreensPropertyIsTheOneItsPropertyPagesAreOf(): void
    {
        $this->assertSame( 'p1', \OWA\Module\Base\View\OptionsHierarchy::propertyInContext(
            $this->hierarchyNav(), array( 'do' => 'base.optionsGeneral' ) ) );

        $this->assertSame( 'p9', \OWA\Module\Base\View\OptionsHierarchy::propertyInContext(
            $this->hierarchyNav(), array( 'propertyId' => 'p9' ) ), 'the one the screen names wins' );

        $this->assertSame( '', \OWA\Module\Base\View\OptionsHierarchy::propertyInContext( array(), array() ) );
    }

    public function testTheRailIsCheckedAgainstAProfileOfTheProperty(): void
    {
        $hierarchy = array( 'properties' => array(
            array( 'id' => 'p0', 'profiles' => array( array( 'site_id' => 'other' ) ) ),
            array( 'id' => 'p1', 'profiles' => array( array( 'site_id' => 's1' ), array( 'site_id' => 's2' ) ) ),
        ) );

        $this->assertSame( 's1', \OWA\Module\Base\View\OptionsHierarchy::accessSiteFor( $hierarchy, 'p1', '' ) );
        $this->assertSame( 's2', \OWA\Module\Base\View\OptionsHierarchy::accessSiteFor( $hierarchy, 'p1', 's2' ),
            "the screen's own Profile" );
        $this->assertSame( '', \OWA\Module\Base\View\OptionsHierarchy::accessSiteFor( $hierarchy, 'p3', '' ) );
    }
}
