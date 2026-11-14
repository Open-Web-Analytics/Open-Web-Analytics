<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a phone needs from the admin markup: fields typed so the right
 * keyboard comes up and nothing is capitalised or corrected that should not
 * be, rosters that become cards (a column name on each cell, the actions in a
 * cell of their own), the access-denied page with its menu and its detail on
 * a line of its own, and the data-ui hooks the e2e specs find parts by.
 */
final class MobileMarkupTest extends TestCase
{
    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    /** A Template with the helpers that need a request stubbed. */
    private function spy(): object
    {
        return new class('base') extends \OWA\Core\Template {

            function createNonceFormField($action) { return "<!--nonce:$action-->"; }

            function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) {

                return 'LINK';
            }
        };
    }

    /** @param array<string, mixed> $vars */
    private function render( string $file, array $vars ): DOMXPath
    {
        $t = $this->spy();

        foreach ( $vars as $k => $v ) {
            $t->set( $k, $v );
        }

        $this->assertTrue( $t->set_template( $file ), "could not locate template $file" );

        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8"><body>' . $t->fetch() . '</body>' );
        libxml_clear_errors();

        return new DOMXPath( $doc );
    }

    /** @return array<string, string> attribute => value, of the one element the query finds */
    private function attrs( DOMXPath $xp, string $query, array $names ): array
    {
        $found = $xp->query( $query );
        $this->assertSame( 1, $found->length, "one element for $query" );

        $out = array();

        foreach ( $names as $name ) {
            $out[ $name ] = $found->item( 0 )->getAttribute( $name );
        }

        return $out;
    }

    public function testAddingAUserTypesTheEmailAndLeavesTheUserNameAsTyped(): void
    {
        $xp = $this->render( 'users_addoredit.php', array(
            'headline' => 'Add user', 'action' => 'base.usersAdd', 'edit' => false,
            'isAdmin' => false, 'roles' => array( 'admin', 'viewer' ), 'user' => array(),
        ) );

        $this->assertSame(
            array( 'type' => 'text', 'autocomplete' => 'off', 'autocapitalize' => 'none', 'autocorrect' => 'off', 'spellcheck' => 'false' ),
            $this->attrs( $xp, '//input[@name="user_id"]', array( 'type', 'autocomplete', 'autocapitalize', 'autocorrect', 'spellcheck' ) ) );

        $this->assertSame(
            array( 'type' => 'email', 'autocomplete' => 'email', 'autocapitalize' => 'none' ),
            $this->attrs( $xp, '//input[@name="email_address"]', array( 'type', 'autocomplete', 'autocapitalize' ) ) );

        // Someone else's name: the browser does not offer the admin's own.
        $this->assertSame( array( 'autocomplete' => 'off' ),
            $this->attrs( $xp, '//input[@name="real_name"]', array( 'autocomplete' ) ) );
    }

    public function testTheLoginFormNamesWhatAPasswordManagerFills(): void
    {
        $xp = $this->render( 'login_form.php', array( 'user_id' => '', 'go' => '' ) );

        $this->assertSame(
            array( 'autocomplete' => 'username', 'autocapitalize' => 'none', 'autocorrect' => 'off' ),
            $this->attrs( $xp, '//input[@name="user_id"]', array( 'autocomplete', 'autocapitalize', 'autocorrect' ) ) );

        $this->assertSame( array( 'type' => 'password', 'autocomplete' => 'current-password' ),
            $this->attrs( $xp, '//input[@name="password"]', array( 'type', 'autocomplete' ) ) );
    }

    public function testTheInstallersAccountIsANewLoginWithAnEmail(): void
    {
        $xp = $this->render( 'install_defaults_entry.php', array(
            'defaults' => array(), 'config' => array(), 'db_server' => 'localhost', 'db_name' => 'owa', 'create' => false,
        ) );

        $this->assertSame( array( 'autocomplete' => 'username', 'autocapitalize' => 'none' ),
            $this->attrs( $xp, '//input[@name="user_id"]', array( 'autocomplete', 'autocapitalize' ) ) );
        $this->assertSame( array( 'type' => 'email', 'autocomplete' => 'email' ),
            $this->attrs( $xp, '//input[@name="email_address"]', array( 'type', 'autocomplete' ) ) );
        $this->assertSame( array( 'autocomplete' => 'new-password' ),
            $this->attrs( $xp, '//input[@name="password"]', array( 'autocomplete' ) ) );
    }

    public function testMyProfileEmailIsAnEmailField(): void
    {
        $xp = $this->render( 'my_profile.php', array(
            'user_id' => 'u', 'real_name' => '', 'email_address' => 'u@example.test', 'role' => 'viewer',
            'may_edit_email' => true, 'my_profile_error' => '', 'my_profile_saved' => false,
            'siteId' => '', 'min_password_length' => 6,
        ) );

        $this->assertSame( array( 'type' => 'email', 'autocomplete' => 'email' ),
            $this->attrs( $xp, '//input[@name="email_address"]', array( 'type', 'autocomplete' ) ) );
    }

    /**
     * Each roster row is a card on a phone: every data cell names its column
     * (data-label, the heading's own words) and the actions are in a cell of
     * their own. The first user cannot be deleted; its row still closes its
     * actions cell.
     */
    public function testTheUserRosterIsReadyToBeCards(): void
    {
        $xp = $this->render( 'users.php', array(
            'headline' => 'Users',
            'users' => array(
                array( 'id' => 1, 'user_id' => 'owner@example.test', 'real_name' => 'Owner', 'role' => 'admin' ),
                array( 'id' => 2, 'user_id' => 'analyst@example.test', 'real_name' => 'Analyst', 'role' => 'analyst' ),
            ),
        ) );

        $this->assertSame( 1, $xp->query( '//table[contains(@class, "owa_cardTable") and @data-ui="users-table"]' )->length );

        $headings = array_map( 'trim', array_map( function ( $th ) { return $th->textContent; },
            iterator_to_array( $xp->query( '//thead//th' ) ) ) );

        $rows = $xp->query( '//tbody/tr[@data-ui="user-row"]' );
        $this->assertSame( 2, $rows->length );

        foreach ( $rows as $i => $row ) {

            $cells = $xp->query( './td', $row );
            $this->assertSame( 4, $cells->length, "row $i has a cell per column" );

            $this->assertSame( array_slice( $headings, 0, 3 ), array(
                $cells->item( 0 )->getAttribute( 'data-label' ),
                $cells->item( 1 )->getAttribute( 'data-label' ),
                $cells->item( 2 )->getAttribute( 'data-label' ),
            ) );

            $this->assertStringContainsString( 'owa_rowActions', $cells->item( 3 )->getAttribute( 'class' ) );
        }

        $this->assertSame( 0, $xp->query( '(//tbody/tr)[1]//a[@data-owa-confirm]' )->length, 'the first user has no Delete' );
        $this->assertSame( 1, $xp->query( '(//tbody/tr)[2]//td[contains(@class, "owa_rowActions")]//a[@data-owa-confirm]' )->length );
    }

    public function testTheGoalEventRosterIsReadyToBeCards(): void
    {
        $xp = $this->render( 'goal_event_definitions.php', array(
            'headline' => 'Goal events', 'propertyId' => 'p1',
            'goalEventDefinitions' => array( array(
                'id' => 7, 'name' => 'Signup', 'trigger_event_type' => 'signup', 'conditions' => array(),
                'condition_match' => 'all', 'count_mode' => '', 'value' => 0, 'is_active' => 1,
            ) ),
        ) );

        $row = $xp->query( '//tbody/tr[@data-ui="goal-event-row"]' );
        $this->assertSame( 1, $row->length );

        $labels = array_map( function ( $td ) { return $td->getAttribute( 'data-label' ); },
            iterator_to_array( $xp->query( './td', $row->item( 0 ) ) ) );

        $this->assertSame( array( 'Name', 'Counts', 'How often', 'Value', 'Status', '' ), $labels );
        $this->assertSame( 1, $xp->query( '//tbody/tr/td[last()][contains(@class, "owa_rowActions")]' )->length );
    }

    /** What was refused is a line of its own under the message, not run on after it. */
    public function testTheBannerShowsTheDetailOnItsOwnLine(): void
    {
        $msg = \OWA\Module\Base\View\Error::bannerMessage( array(
            'headline' => 'Error', 'message' => 'Your account does not have access to this page.',
            'detail' => 'Profile: 123',
        ) );

        $this->assertSame( 'Profile: 123', $msg['detail'] );

        $xp = $this->render( 'msgs.php', array( 'error_msg' => $msg ) );

        $this->assertSame( 'Profile: 123', trim( $xp->query( '//div[@class="error"]/span[@class="owa_errorDetail"]' )->item( 0 )->textContent ) );
        $this->assertStringNotContainsString( 'page.Profile', $xp->query( '//div[@class="error"]' )->item( 0 )->textContent );
    }

    public function testABannerWithNoDetailDrawsNone(): void
    {
        $xp = $this->render( 'msgs.php', array( 'error_msg' => \OWA\Module\Base\View\Error::bannerMessage( 'Gone.' ) ) );

        $this->assertSame( 0, $xp->query( '//*[@class="owa_errorDetail"]' )->length );
    }

    /**
     * Refused a page, a signed-in user keeps the menu: the slide-out the
     * header's menu button opens, with the sections they may use, each
     * carrying its data-ui hook.
     */
    public function testTheAccessDeniedPageHasTheMenu(): void
    {
        $nav = array(
            'Dashboard' => array(
                'ref' => array( 'do' => 'base.report', 'reportId' => 'dashboard' ),
                'anchortext' => 'Dashboard', 'priviledge' => 'view_reports', 'icon_class' => 'fa fa-tachometer-alt',
            ),
        );

        $xp = $this->render( 'generic_error.php', array(
            'report_nav' => $nav, 'nav_property_id' => 'p1', 'nav_access_site' => '', 'back_url' => '',
        ) );

        $this->assertSame( 1, $xp->query( '//*[@id="owa_reportNavRail"]//*[@data-ui="nav:Dashboard"]' )->length );
        $this->assertSame( 1, $xp->query( '//main[@id="owa_main"]' )->length );
    }

    public function testAnErrorWithNoMenuIsTheWayBackAlone(): void
    {
        $xp = $this->render( 'generic_error.php', array( 'back_url' => 'https://owa.test/back' ) );

        $this->assertSame( 0, $xp->query( '//*[@id="owa_reportNavRail"]' )->length );
        $this->assertSame( 1, $xp->query( '//p[@class="owa_errorBack"]/a' )->length );
    }

    /** System Health's counts line up by digit. */
    public function testSystemHealthTablesAreNumeric(): void
    {
        $source = (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/templates/system_health.php' );

        $this->assertSame( 0, preg_match( '/<table class="management">/', $source ) );
        $this->assertGreaterThan( 0, preg_match_all( '/<table class="management owa_numericTable">/', $source ) );
    }
}
