<?php

use PHPUnit\Framework\TestCase;

/**
 * "Every legacy class name still resolves" contract test — net (c) of the
 * namespace-migration safety net (Phase 6, stage 0).
 *
 * WHY THIS EXISTS
 * ---------------
 * The migration's backward-compat promise is: every `owa_*` class name that
 * exists today keeps resolving for a full major-version deprecation window,
 * whether it is still a directly-declared class or has become a forward
 * `class_alias` pointing at its new namespaced name. Third-party plugins,
 * integrations, and serialized state reference these names; silently dropping
 * one is a breaking change.
 *
 * This test freezes the complete set of legacy class/interface/trait names
 * (captured from the untouched tree into tests/fixtures/legacy_class_names.json
 * BEFORE any rename) and asserts that after a full framework boot every one
 * still resolves. At stage 0 it is a tautology. The moment a rename lands
 * without its forward alias, this test goes red — which is the whole point:
 * net (a) reads its expectation from the (renamed) file and net (b) only covers
 * registered dotted-ids, so ONLY this frozen snapshot catches a dropped alias.
 *
 * Maintenance contract: this fixture is APPEND-mostly. A name may be added when
 * new classes ship. A name may only be REMOVED when its deprecation window has
 * elapsed and the alias is intentionally retired — never to make the test pass.
 *
 * RETIRED 2026-08-24, deliberately: the 35 report controllers that became
 * configuration (modules/Base/reports/*.json, rendered by Core\ConfiguredReport).
 * They are listed in RETIRED below and asserted to be GONE, so the removal is a
 * decision this suite records and enforces rather than a fixture edit that made
 * a test go quiet. Anything referencing owa_reportPagesController and friends
 * gets a clear "class not found" instead of an alias to a class that would
 * render an empty report.
 *
 * RETIRED 2026-08-24: attribution-history's controller, which became a report
 * definition. Its grid formatter was the last thing keeping it -- see
 * AttributionFormatter.test.js.
 *
 * RETIRED 2026-08-24: the referral crawler. OWA no longer fetches referring
 * pages, so its CLI controller and view are gone -- see RefererCrawlRemovedTest.
 *
 * RETIRED 2026-08-28: owa_exit, the entity behind the `exit` table. It was
 * registered and mapped by a setting, and nothing anywhere constructed or wrote
 * one -- there has been no write path for it in the tracker for years. The
 * TABLE is deliberately left in place: dropping it is a destructive migration
 * and a separate decision from removing dead code. (Unrelated to the exit-pages
 * REPORT, which is live.)
 *
 * RETIRED 2026-08-24, same reasoning: the 8 report VIEWS whose reports became
 * widget configuration. Seven were bespoke views that did nothing but name a
 * template; the eighth, ReportSimpleDimensional, was the generic subview they
 * and the converted reports shared. Every report now renders through
 * base.reportWidgets, so all eight and their templates are gone.
 */
final class LegacyClassNameContractTest extends TestCase
{
    /**
     * Legacy names retired when their reports became configuration.
     *
     * Asserted absent, not merely dropped from the fixture: a retirement that
     * is only an omission cannot be told apart from an accident later.
     */
    /** Size of the name set frozen from the untouched tree at stage 0. */
    private const STAGE0_COUNT = 406;

    private const RETIRED = [
        /*
         * RETIRED 2026-09-02: the three goal screens.
         *
         * Goals became goal events -- named rows in owa_goal_event rather than
         * twenty numbered slots inside a serialized array. The screens went
         * with the model: a list that showed twenty rows whether or not anyone
         * had filled them in, and an editor that edited a SLOT, so creating a
         * goal meant picking an unused number and there was no twenty-first.
         *
         * base.goalEvents and base.goalEventEdit replace them. Their validation
         * rules did NOT retire with them -- the funnel-step and group-name
         * rules moved to GoalEventSave, and the tests that earned them were
         * repointed rather than deleted.
         */
        'owa_optionsGoalsController',
        'owa_optionsGoalsView',
        'owa_optionsGoalEntryController',
        'owa_optionsGoalEntryView',
        'owa_optionsGoalEditController',

        /*
         * RETIRED 2026-09-01: base.sites, the flat roster of every tracked site.
         *
         * It was the landing page, the "Reporting" link and the redirect after
         * every save, and it carried a thumbnail, trend metrics and five
         * management links per row. Those five links belong to the hierarchy's
         * tier nav now and its navigation job belongs to the site control, so
         * it was the last screen still offering the old flat route to them.
         * Reporting lands on the last Profile viewed instead.
         *
         * Listed here rather than left aliased so anything still asking for it
         * gets "class not found" rather than a controller with no view.
         */
        'owa_sitesController',
        'owa_sitesView',
        /*
         * RETIRED 2026-09-01: the old settings menu wrapper. Every settings
         * screen -- Base's and the modules' -- renders in the hierarchy wrapper
         * now, which carries the site control and one nav covering install,
         * Organization, Property and Profile. Two settings menus was the thing
         * to remove, not a second one to maintain.
         */
        'owa_optionsView',
        /*
         * RETIRED 2026-08-31: 41 metric classes, deleted when metrics became
         * configuration.
         *
         * Thirty-five were pure declarations whose entire content is now a
         * registerMetricDefinition() entry; six more were already unreachable --
         * five legacy self-querying metrics whose only entry point was a
         * calculate() method nothing calls, and one superseded duplicate.
         *
         * A DELIBERATE backward-compatibility break, accepted rather than
         * stumbled into: the supported way to reach a metric is its registered
         * name ('bounces'), not its implementation class, and every one of these
         * disappears in v2 regardless. Code doing `new owa_bounces` breaks.
         */
        'owa_actions',
        'owa_actionsValue',
        'owa_bounceRate',
        'owa_bounces',
        'owa_clickBrowserTypes',
        'owa_domClicks',
        'owa_ecommerceConversionRate',
        'owa_feedReaders',
        'owa_feedRequests',
        'owa_feedSubscriptions',
        'owa_goalAbandonRateAll',
        'owa_goalCompletionsAll',
        'owa_goalConversionRateAll',
        'owa_goalStartsAll',
        'owa_goalValueAll',
        'owa_latestDomstreams',
        'owa_lineItemQuantity',
        'owa_lineItemQuantityFromSessionFact',
        'owa_lineItemRevenue',
        'owa_lineItemRevenueFromSessionFact',
        'owa_newVisitors',
        'owa_pagesPerVisit',
        'owa_revenuePerTransaction',
        'owa_revenuePerVisit',
        'owa_sessionBrowserTypes',
        'owa_shippingRevenue',
        'owa_shippingRevenueFromSessionFact',
        'owa_taxRevenue',
        'owa_taxRevenueFromSessionFact',
        'owa_topReferers',
        'owa_topVisitors',
        'owa_transactionRevenue',
        'owa_transactionRevenueFromSessionFact',
        'owa_transactions',
        'owa_transactionsFromSessionFact',
        'owa_uniqueActions',
        'owa_uniqueLineItems',
        'owa_uniqueLineItemsFromSessionFact',
        'owa_uniquePageViews',
        'owa_visitDuration',
        'owa_visitors',
        // RETIRED 2026-08-26: transaction-detail, removed rather than converted.
        // It was the per-transaction drill-down off the Transaction Roster --
        // one record as label/value rows plus its line items. Nothing else
        // linked to it. The REST report it read (report_transaction) is a
        // public endpoint and stays.
        'owa_reportTransactionDetailController',
        'owa_reportTransactionDetailView',
        // RETIRED 2026-08-25: the visitor-detail family. visitors became a
        // report definition; visit, visits, visitors-roster and visitor were
        // dropped -- nothing linked to them and none was in the navigation.
        'owa_reportVisitorsController',
        'owa_reportVisitorsView',
        'owa_reportVisitorController',
        'owa_reportVisitorView',
        'owa_reportVisitController',
        'owa_reportVisitView',
        'owa_reportVisitsController',
        'owa_reportVisitsView',
        'owa_reportVisitorsRosterController',
        'owa_reportVisitorsRosterView',
        // RETIRED 2026-08-25: document became a report definition. Its card was
        // dropped and the rest models as widgets.
        'owa_reportDocumentController',
        'owa_reportDocumentView',
        'owa_reportDashboardController',
        'owa_reportDashboardView',
        // The GitHub release feed. Replaced by stored notifications, which are
        // fetched on a schedule instead of during a dashboard render.
        'owa_widgetOwaNewsController',
        'owa_widgetOwaNewsView',
        'owa_reportGoalsController',
        'owa_reportGoalsView',
        'owa_reportDomClicksController',
        'owa_reportDomClicksView',
        'owa_reportCampaignsController',
        'owa_reportAttributionHistoryController',
        'owa_reportReferralDetailController',
        'owa_crawlReferralCliController',
        'owa_crawlReferralCliView',
        'owa_reportTrafficView',
        'owa_reportContentView',
        'owa_reportCommerceView',
        'owa_reportEcommerceView',
        'owa_reportFeedsView',
        'owa_reportTransactionsView',
        'owa_reportActionTrackingView',
        'owa_reportSimpleDimensionalView',
        'owa_reportActionDetailController',
        'owa_reportActionGroupController',
        'owa_reportActionGroupsController',
        'owa_reportActionTrackingController',
        'owa_reportAdDetailController',
        'owa_reportAdTypeDetailController',
        'owa_reportAdTypesController',
        'owa_reportAdsController',
        'owa_reportAnchortextController',
        'owa_reportAvgOrderValueController',
        'owa_reportBrowserDetailController',
        'owa_reportBrowsersController',
        'owa_reportCampaignDetailController',
        'owa_reportCommerceController',
        'owa_reportContentController',
        'owa_reportCountryDetailController',
        'owa_reportCreativePerformanceController',
        'owa_reportDaysToPurchaseController',
        'owa_reportEcommerceController',
        'owa_reportEcommerceConversionRateController',
        'owa_reportEntryPagesController',
        'owa_reportExitPagesController',
        'owa_reportFeedsController',
        'owa_reportGeolocationController',
        'owa_reportHostDetailController',
        'owa_reportHostsController',
        'owa_reportKeywordDetailController',
        'owa_reportKeywordsController',
        'owa_reportOsController',
        'owa_reportOsDetailController',
        'owa_reportPageTypeDetailController',
        'owa_reportPageTypesController',
        'owa_reportPagesController',
        'owa_reportProductCategoriesController',
        'owa_reportProductCategoryDetailController',
        'owa_reportProductDetailController',
        'owa_reportProductSkuDetailController',
        'owa_reportProductSkusController',
        'owa_reportProductsController',
        'owa_reportReferralLinkTextDetailController',
        'owa_reportReferringSitesController',
        'owa_reportRevenueController',
        'owa_reportSearchEngineDetailController',
        'owa_reportSearchEnginesController',
        'owa_reportSourceDetailController',
        'owa_reportSourcesController',
        'owa_reportStateDetailController',
        // The dead entity behind the `exit` table; see the header.
        'owa_exit',
        'owa_reportTrafficController',
        'owa_reportTransactionsController',
        'owa_reportVisitorsAgeController',
        'owa_reportVisitorsLoyaltyController',
        'owa_reportVisitorsRecencyController',
        'owa_reportVisitsToPurchaseController',
    ];

    public static function setUpBeforeClass(): void
    {
        // Full boot so directly-declared classes are loadable on demand and any
        // eager forward-alias file (owa_compat_aliases.php, once it exists) has
        // run.
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * The retired names are actually gone.
     *
     * The other half of removing them from the frozen set. Without this, an
     * alias could quietly come back -- or never have been removed -- and the
     * fixture would simply have stopped watching.
     */
    public function testRetiredLegacyNamesAreGone(): void
    {
        $stillThere = [];

        foreach (self::RETIRED as $name) {
            if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
                $stillThere[] = $name;
            }
        }

        $this->assertSame([], $stillThere,
            "these names were retired but still resolve:\n" . implode("\n", $stillThere));
    }

    /**
     * ...and none of them is still in the frozen set, so the two halves cannot
     * drift into disagreeing about what was retired.
     */
    public function testRetiredNamesAreNotInTheFrozenSet(): void
    {
        $overlap = array_intersect($this->legacyNames(), self::RETIRED);

        $this->assertSame([], array_values($overlap),
            'a retired name is still listed as one that must resolve');
    }

    public function testEveryLegacyClassNameStillResolves(): void
    {
        $names = $this->legacyNames();

        /*
         * Derived rather than a number that has to be nudged down after every
         * retirement -- which is how a truncation guard stops guarding. New
         * classes only ever push it up, so the floor is the stage-0 set less
         * what has been deliberately retired.
         */
        $floor = self::STAGE0_COUNT - count(self::RETIRED);

        $this->assertGreaterThanOrEqual(
            $floor,
            count($names),
            'Legacy class-name snapshot looks truncated: expected at least the '
            . "stage-0 set of " . self::STAGE0_COUNT . ' less the ' . count(self::RETIRED)
            . ' deliberately retired names.'
        );

        $missing = [];
        foreach ($names as $name) {
            // class_exists() with autoload enabled resolves both a
            // still-declared class AND a forward class_alias. That is exactly
            // the two ways a legacy name is allowed to keep working.
            if (
                !class_exists($name)
                && !interface_exists($name)
                && !trait_exists($name)
            ) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Legacy class name(s) no longer resolve — a rename dropped its "
            . "forward class_alias (backward-compat break):\n"
            . implode("\n", $missing)
        );
    }

    /** @return string[] */
    private function legacyNames(): array
    {
        $path = __DIR__ . '/fixtures/legacy_class_names.json';
        $this->assertFileExists($path, 'Legacy class-name snapshot fixture is missing.');

        $names = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($names, 'Legacy class-name snapshot is not valid JSON.');

        return $names;
    }
}
