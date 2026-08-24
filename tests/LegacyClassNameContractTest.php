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
 */
final class LegacyClassNameContractTest extends TestCase
{
    /**
     * Legacy names retired when their reports became configuration.
     *
     * Asserted absent, not merely dropped from the fixture: a retirement that
     * is only an omission cannot be told apart from an accident later.
     */
    private const RETIRED = [
        'owa_reportActionGroupsController',
        'owa_reportActionTrackingController',
        'owa_reportAdTypesController',
        'owa_reportAdsController',
        'owa_reportAnchortextController',
        'owa_reportAvgOrderValueController',
        'owa_reportBrowsersController',
        'owa_reportCommerceController',
        'owa_reportContentController',
        'owa_reportCreativePerformanceController',
        'owa_reportDaysToPurchaseController',
        'owa_reportEcommerceController',
        'owa_reportEcommerceConversionRateController',
        'owa_reportEntryPagesController',
        'owa_reportExitPagesController',
        'owa_reportFeedsController',
        'owa_reportGeolocationController',
        'owa_reportHostsController',
        'owa_reportKeywordsController',
        'owa_reportOsController',
        'owa_reportPageTypesController',
        'owa_reportPagesController',
        'owa_reportProductCategoriesController',
        'owa_reportProductSkusController',
        'owa_reportProductsController',
        'owa_reportReferringSitesController',
        'owa_reportRevenueController',
        'owa_reportSearchEnginesController',
        'owa_reportSourcesController',
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

        $this->assertGreaterThan(
            360,
            count($names),
            'Legacy class-name snapshot looks truncated; expected the stage-0 set '
            . 'of ~406 less the ' . count(self::RETIRED) . ' names retired with the '
            . 'report-to-configuration conversion.'
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
