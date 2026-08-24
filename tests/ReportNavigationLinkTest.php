<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Navigation links point at reports by id, and only one of them is current.
 *
 * A nav `ref` used to be an action name, and "is this the page I am on" was
 * `ref === params['do']`. Routing every report through one action breaks that
 * test in the worst available way: it does not stop matching, it starts
 * matching EVERYTHING, so the whole Reports menu highlights at once.
 *
 * These pin the pair -- what a ref resolves to, and which one is current.
 */
final class ReportNavigationLinkTest extends TestCase
{
    private function template(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' );
    }

    public function testAReportRefCarriesItsId(): void
    {
        $params = $this->template()->navLinkParams(
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'pages' ) ) );

        $this->assertSame(
            array( 'do' => 'base.report', 'reportId' => 'pages' ), $params );
    }

    /** A plain action ref still means what it always meant. */
    public function testAPlainRefIsStillAnAction(): void
    {
        $this->assertSame( array( 'do' => 'base.optionsGeneral' ),
            $this->template()->navLinkParams( array( 'ref' => 'base.optionsGeneral' ) ) );
    }

    public function testTheCurrentReportIsCurrent(): void
    {
        $this->assertTrue( $this->template()->navLinkIsCurrent(
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'pages' ) ),
            array( 'do' => 'base.report', 'reportId' => 'pages' ) ) );
    }

    /**
     * The failure this exists for: every report shares an action, so matching
     * on the action alone would light up the entire menu.
     */
    public function testADifferentReportIsNotCurrent(): void
    {
        $this->assertFalse( $this->template()->navLinkIsCurrent(
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'entry-pages' ) ),
            array( 'do' => 'base.report', 'reportId' => 'pages' ) ),
            'matching on the action alone makes every report link current at once' );
    }

    /** Only one entry in a realistic menu is current, not several. */
    public function testExactlyOneLinkInAMenuIsCurrent(): void
    {
        $menu = array(
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'pages' ) ),
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'entry-pages' ) ),
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'exit-pages' ) ),
            array( 'ref' => 'base.optionsGeneral' ),
        );

        $t = $this->template();
        $here = array( 'do' => 'base.report', 'reportId' => 'entry-pages' );

        $current = array_filter( $menu, function ( $l ) use ( $t, $here ) {
            return $t->navLinkIsCurrent( $l, $here );
        } );

        $this->assertCount( 1, $current );
        $this->assertSame( 'entry-pages', reset( $current )['ref']['reportId'] );
    }

    /** A report link is not current when looking at something else entirely. */
    public function testNoLinkIsCurrentOnAnUnrelatedPage(): void
    {
        $t = $this->template();

        $this->assertFalse( $t->navLinkIsCurrent(
            array( 'ref' => array( 'do' => 'base.report', 'reportId' => 'pages' ) ),
            array( 'do' => 'base.optionsGeneral' ) ) );

        $this->assertFalse( $t->navLinkIsCurrent( array( 'ref' => '' ), array( 'do' => '' ) ),
            'an empty ref must not match an empty request' );
    }

    /**
     * No link anywhere still names a report controller action directly.
     *
     * Those actions are the thing being retired; one left behind is a link that
     * keeps working right up until the controller is deleted, and then 404s.
     *
     * Single-quoted needles throughout. In a double-quoted PHP string a `$this->`
     * inside the needle would interpolate away and the search would pass
     * unconditionally.
     */
    public function testNoLinkNamesAReportControllerAction(): void
    {
        $registered = array_keys( (array) \OWA\Core\CoreAPI::getReportRegistry() );

        $this->assertNotEmpty( $registered, 'no reports registered, so this proves nothing' );

        $offenders = array();

        $files = array_merge(
            (array) glob( OWA_DIR . 'modules/*/templates/*.php' ),
            (array) glob( OWA_DIR . 'modules/*/Controller/*.php' ),
            (array) glob( OWA_DIR . 'modules/Base/reports/*.json' ),
            // The e2e specs navigate to these URLs for real. Leaving them out
            // is how a swept tree still broke six browser tests: the scan said
            // no link named a controller action while tests/e2e/ held eleven.
            (array) glob( OWA_DIR . 'tests/e2e/*.js' )
        );

        foreach ( $files as $file ) {

            $body = (string) file_get_contents( $file );

            // A link names its target with a 'do'. Subview names -- which look
            // similar and are not links -- are set with setSubview(), so this
            // does not see them.
            if ( preg_match_all( '/[\'"]do[\'"]\s*(?:=>|:)\s*[\'"](base\.report[A-Z][A-Za-z]*)[\'"]/', $body, $m ) ) {

                foreach ( $m[1] as $action ) {
                    $offenders[] = basename( $file ) . ' -> ' . $action;
                }
            }

            /*
             * ...and the query-string form the browser tests use. Same link,
             * written as a URL rather than as a parameter map, and invisible to
             * the pattern above. A comment mentioning an action is not a link,
             * so this matches only where it is actually being navigated to.
             */
            if ( preg_match_all( '/owa_do=(base\.report[A-Z][A-Za-z]*)/', $body, $m2 ) ) {

                foreach ( $m2[1] as $action ) {
                    $offenders[] = basename( $file ) . ' -> owa_do=' . $action;
                }
            }
        }

        $this->assertSame( array(), $offenders,
            "these links still point at a report controller action instead of a report id:\n"
            . implode( "\n", $offenders ) );
    }

    /**
     * Positive control for the scan above.
     *
     * Without it, the assertion would pass just as happily if the pattern were
     * wrong and matched nothing anywhere.
     */
    public function testTheScanCanActuallyFindSuchALink(): void
    {
        $sample = "\$view->makeLink( array( 'do' => 'base.reportPages' ), true );";

        $this->assertMatchesRegularExpression(
            '/[\'"]do[\'"]\s*(?:=>|:)\s*[\'"](base\.report[A-Z][A-Za-z]*)[\'"]/',
            $sample,
            'the pattern used by the scan must be able to match the thing it looks for' );

        $urlForm = "await page.goto('?owa_do=base.reportPages&owa_siteId=1');";

        $this->assertMatchesRegularExpression( '/owa_do=(base\.report[A-Z][A-Za-z]*)/', $urlForm,
            'the query-string pattern must match the form the browser tests navigate with' );

        // ...and must NOT match a subview, which is not a link.
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"]do[\'"]\s*(?:=>|:)\s*[\'"](base\.report[A-Z][A-Za-z]*)[\'"]/',
            "\$this->setSubview( 'base.reportDimension' );" );
    }

    /**
     * The nav Base actually builds, not a hand-made one.
     *
     * Every assertion above operates on a link struct written in this file, so
     * all of them passed while eight real registrations still pointed at
     * controller actions -- seven through addNavigationSubGroup(), which the
     * first sweep did not match, and one registered by the Domstream module
     * against a Base report. A unit test over invented data cannot see either.
     */
    public function testEveryBaseReportNavLinkRoutesThroughAReportId(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'building the nav loads modules and the current user' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $template = $this->template();
        $offenders = array();
        $count = 0;

        foreach ( $this->flattenNav() as $link ) {

            $params = $template->navLinkParams( $link );
            $do     = (string) ( $params['do'] ?? '' );

            // Other modules register their own reports and are not this
            // change's business; only Base's were converted.
            if ( strpos( $do, 'base.' ) !== 0 ) {
                continue;
            }

            $count++;

            if ( $do !== 'base.report' || empty( $params['reportId'] ) ) {
                $offenders[] = $do;
            }
        }

        $this->assertGreaterThan( 20, $count,
            'too few Base nav links found for this to be checking anything' );

        $this->assertSame( array(), $offenders,
            "these nav links still name a report controller action:\n" . implode( "\n", $offenders ) );
    }

    /**
     * Exactly one entry of the real menu is current, not none and not all.
     */
    public function testExactlyOneRealNavLinkIsCurrent(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'building the nav loads modules and the current user' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $template = $this->template();

        foreach ( array( 'entry-pages', 'pages', 'browsers' ) as $id ) {

            $here    = array( 'do' => 'base.report', 'reportId' => $id );
            $current = 0;

            foreach ( $this->flattenNav() as $link ) {

                if ( $template->navLinkIsCurrent( $link, $here ) ) {
                    $current++;
                }
            }

            $this->assertSame( 1, $current,
                "looking at report '$id', $current nav links claim to be current" );
        }
    }

    /** Every nav link in the Reports group, subgroup entries included. */
    private function flattenNav(): array
    {
        $flat = array();

        foreach ( (array) \OWA\Core\CoreAPI::getGroupNavigation( 'Reports' ) as $link ) {

            if ( ! is_array( $link ) || ! isset( $link['ref'] ) ) {
                continue;
            }

            $flat[] = $link;

            foreach ( (array) ( $link['subgroup'] ?? array() ) as $sub ) {

                if ( isset( $sub['ref'] ) ) {
                    $flat[] = $sub;
                }
            }
        }

        return $flat;
    }
}
