<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Navigation links point at reports by id, and only one of them is current.
 *
 * Every report routes through one action, so "is this the page I am on" as
 * `ref === params['do']` would not stop matching -- it would match
 * EVERYTHING, and the whole Reports menu would highlight at once.
 *
 * These pin the pair -- what a ref resolves to, and which one is current.
 */
final class ReportNavigationLinkTest extends TestCase
{
    /** Base nav entries that are not reports, and so route to their own action. */
    private const NOT_REPORTS = [ 'base.customReports' ];

    private function template(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' );
    }

    public function testAReportRefCarriesItsId(): void
    {
        $params = $this->template()->navLinkParams(
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ] ] );

        $this->assertSame(
            [ 'do' => 'base.report', 'reportId' => 'pages' ], $params );
    }

    /** A plain action ref still means what it always meant. */
    public function testAPlainRefIsStillAnAction(): void
    {
        $this->assertSame( [ 'do' => 'base.optionsGeneral' ],
            $this->template()->navLinkParams( [ 'ref' => 'base.optionsGeneral' ] ) );
    }

    public function testTheCurrentReportIsCurrent(): void
    {
        $this->assertTrue( $this->template()->navLinkIsCurrent(
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ] ],
            [ 'do' => 'base.report', 'reportId' => 'pages' ] ) );
    }

    /**
     * The failure this exists for: every report shares an action, so matching
     * on the action alone would light up the entire menu.
     */
    public function testADifferentReportIsNotCurrent(): void
    {
        $this->assertFalse( $this->template()->navLinkIsCurrent(
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'entry-pages' ] ],
            [ 'do' => 'base.report', 'reportId' => 'pages' ] ),
            'matching on the action alone makes every report link current at once' );
    }

    /** Only one entry in a realistic menu is current, not several. */
    public function testExactlyOneLinkInAMenuIsCurrent(): void
    {
        $menu = [
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ] ],
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'entry-pages' ] ],
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'exit-pages' ] ],
            [ 'ref' => 'base.optionsGeneral' ],
        ];

        $t = $this->template();
        $here = [ 'do' => 'base.report', 'reportId' => 'entry-pages' ];

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
            [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ] ],
            [ 'do' => 'base.optionsGeneral' ] ) );

        $this->assertFalse( $t->navLinkIsCurrent( [ 'ref' => '' ], [ 'do' => '' ] ),
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

        $offenders = [];

        $files = array_merge(
            (array) glob( \OWA\Core\Env::root() . '/modules/*/templates/*.php' ),
            (array) glob( \OWA\Core\Env::root() . '/modules/*/Controller/*.php' ),
            (array) glob( \OWA\Core\Env::root() . '/modules/Base/reports/*.json' ),
            // The e2e specs navigate to these URLs for real, so they are scanned too.
            (array) glob( \OWA\Core\Env::root() . '/tests/e2e/*.js' )
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

        $this->assertSame( [], $offenders,
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
        $offenders = [];
        $count = 0;

        foreach ( $this->flattenNav() as $link ) {

            $params = $template->navLinkParams( $link );
            $do     = (string) ( $params['do'] ?? '' );

            // Other modules register their own reports and are not this
            // change's business; only Base's were converted.
            if ( strpos( $do, 'base.' ) !== 0 ) {
                continue;
            }

            /*
             * Nav entries that are not REPORTS.
             *
             * The contract is that every report is reached as
             * base.report&reportId=..., so that a link says which report it
             * means rather than which controller. base.customReports is not a
             * report -- it is the roster LISTING them, the way base.sites lists
             * sites -- so it has no reportId to route through and never will.
             *
             * Named individually rather than pattern-matched: an exemption that
             * matched a prefix would quietly excuse a real report that happened
             * to be named like one of these.
             */
            if ( in_array( $do, self::NOT_REPORTS, true ) ) {
                continue;
            }

            $count++;

            if ( $do !== 'base.report' || empty( $params['reportId'] ) ) {
                $offenders[] = $do;
            }
        }

        $this->assertGreaterThan( 20, $count,
            'too few Base nav links found for this to be checking anything' );

        $this->assertSame( [], $offenders,
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

        foreach ( [ 'entry-pages', 'pages', 'browsers' ] as $id ) {

            $here    = [ 'do' => 'base.report', 'reportId' => $id ];
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

    /**
     * Every nav link in the Reports group, subgroup entries included.
     */
    private function flattenNav(): array
    {
        $flat = [];

        foreach ( (array) \OWA\Core\CoreAPI::getGroupNavigation( 'Reports' ) as $link ) {

            if ( ! is_array( $link ) || ! isset( $link['ref'] ) ) {
                continue;
            }

            $flat[] = $link;

            foreach ( (array) ( $link['subgroup'] ?? [] ) as $sub ) {

                if ( isset( $sub['ref'] ) ) {
                    $flat[] = $sub;
                }
            }
        }

        return $flat;
    }

    // ------------------------------------------------------------------
    // What the nav is actually GIVEN to compare against
    // ------------------------------------------------------------------

    /**
     * The rail is handed the whole request, not just its action.
     *
     * navLinkIsCurrent() compares every key of a link's ref, and a report link
     * is {do: base.report, reportId: pages}. Params holding only the action
     * never contain `reportId`, so NO report link would be current: nothing
     * highlighted, and the menu collapsed on every page load.
     */
    public function testTheRailIsGivenTheWholeRequestNotJustTheAction(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'building the nav loads modules and the current user' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $nav = (array) \OWA\Core\CoreAPI::getGroupNavigation( 'Reports' );

        if ( ! $nav ) {
            $this->markTestSkipped( 'no reporting navigation is registered' );
        }

        $rail = $this->template()->navRail(
            $nav, 'some-property', [ 'do' => 'base.report', 'reportId' => 'pages' ] );

        $current = array_values( array_filter( $rail, function ( $s ) { return $s['current']; } ) );

        $this->assertCount( 1, $current, 'exactly one section is current' );
        $this->assertSame( 'Content', $current[0]['label'] );

        $marked = array_values( array_filter( $current[0]['items'], function ( $i ) { return $i['current']; } ) );

        $this->assertCount( 1, $marked, 'exactly one report in the column is current' );
        $this->assertSame( 'Pages', $marked[0]['label'] );
    }

    /**
     * ...and the action ALONE marks nothing, which is the state that shipped.
     *
     * The same call with the pre-conversion argument -- just the action -- must
     * NOT find a current link. Without this the test above would pass against
     * an implementation that marked everything.
     */
    public function testTheActionAloneCannotIdentifyAReport(): void
    {
        $template = $this->template();

        $link = [ 'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ] ];

        $this->assertFalse(
            $template->navLinkIsCurrent( $link, [ 'do' => 'base.report' ] ),
            'the action alone must not make a report link current -- every report shares it' );

        $this->assertTrue(
            $template->navLinkIsCurrent( $link,
                [ 'do' => 'base.report', 'reportId' => 'pages' ] ),
            'the full request must' );

        $this->assertFalse(
            $template->navLinkIsCurrent( $link,
                [ 'do' => 'base.report', 'reportId' => 'entry-pages' ] ),
            'a different report must not' );
    }

    // ------------------------------------------------------------------
    // E-commerce reports live in the nav, not behind a links widget
    // ------------------------------------------------------------------

    /**
     * The eight e-commerce reports that have to remain reachable, written out:
     * there is no widget to read them from, and reading nothing would pass.
     */
    private const ECOMMERCE_REPORTS = [
        'avg-order-value',
        'revenue',
        'ecommerce-conversion-rate',
    ];

    /** @return array reportId => anchortext, for the Ecommerce subgroup */
    private function ecommerceNav(): array
    {
        $out = [];

        foreach ( (array) \OWA\Core\CoreAPI::getGroupNavigation( 'Reports' ) as $link ) {

            if ( ( $link['anchortext'] ?? '' ) !== 'Ecommerce' ) {
                continue;
            }

            foreach ( (array) ( $link['subgroup'] ?? [] ) as $sub ) {

                $params = $this->template()->navLinkParams( $sub );

                if ( ! empty( $params['reportId'] ) ) {
                    $out[ $params['reportId'] ] = $sub['anchortext'] ?? '';
                }
            }
        }

        return $out;
    }

    /**
     * Every e-commerce report is reachable from the nav, the thing built for
     * moving between reports.
     */
    public function testEveryEcommerceReportIsInTheNav(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'building the nav loads modules and the current user' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $nav = $this->ecommerceNav();

        $this->assertNotEmpty( $nav, 'the Ecommerce nav subgroup is empty' );

        $missing = array_diff( self::ECOMMERCE_REPORTS, array_keys( $nav ) );

        $this->assertSame( [], $missing,
            "These e-commerce reports are no longer reachable from the nav:\n  "
            . implode( "\n  ", $missing ) );
    }

    /** ...and the overview carries no report-links widget. */
    public function testTheEcommerceOverviewHasNoReportLinksWidget(): void
    {
        $definition = json_decode( (string) file_get_contents(
            \OWA\Core\Env::root() . '/modules/Base/reports/ecommerce.json' ), true );

        $this->assertIsArray( $definition );

        foreach ( (array) $definition['widgets'] as $widget ) {

            $this->assertNotSame( 'report-links', $widget['type'] ?? '',
                'the overview lists reports again; the nav is where reports belong' );
        }
    }

    /**
     * A nav entry's label matches the report it opens.
     *
     * Two of these reports carried a copy-pasted title -- product-categories
     * was called "Product SKUs" and ecommerce-conversion-rate was called "Total
     * Revenue" -- so following the menu landed on a heading naming a different
     * report. Checked for the ones being added here rather than across the
     * whole nav, where several labels are deliberately shorter than the title.
     */
    public function testTheseNavLabelsMatchTheirReportTitles(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'building the nav loads modules and the current user' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $nav = $this->ecommerceNav();

        /*
         * The one of the three still shipped. This also named product-categories
         * and products after both were retired: each read compared null with
         * null, passed, and raised a warning about the missing file.
         */
        foreach ( [ 'ecommerce-conversion-rate' ] as $id ) {

            $file = \OWA\Core\Env::root() . '/modules/Base/reports/' . $id . '.json';

            $this->assertFileExists( $file, "'$id' is listed as remaining but has no definition" );

            $definition = json_decode( (string) file_get_contents( $file ), true );

            $this->assertNotEmpty( $definition['title'] ?? null, "'$id' has no title to compare with" );
            $this->assertSame( $definition['title'], $nav[ $id ] ?? null,
                "the nav calls '$id' something other than the report does" );
        }
    }
}
