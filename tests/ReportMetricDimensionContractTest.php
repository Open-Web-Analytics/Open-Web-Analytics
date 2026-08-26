<?php

use PHPUnit\Framework\TestCase;

/**
 * Every metric and dimension a report names must exist, and a report's sort must
 * be something that report actually queries.
 *
 * WHY THIS EXISTS
 * ---------------
 * Three bugs of this shape were found by hand in one sitting, all silent:
 *
 *   ReportProducts          sort='actions'              -> grid returned no rows
 *   report_ecommerce.php    sort='transactionsRevenue-' -> jqGrid threw on null
 *                           (template since deleted; the report is JSON now)
 *   ReportCampaigns         read the wrong setting      -> columns missing
 *
 * None of them produced a non-200, an exception, or a log line. A report with an
 * unresolvable sort renders perfectly and shows nothing, which is
 * indistinguishable from a site with no data -- and on an install where the
 * feature is off, nobody is looking anyway. The only reason they surfaced was a
 * test that asserted on report CONTENT.
 *
 * A name being registered is not enough on its own: 'actions' IS a real metric,
 * it is simply not one ReportProducts queries. So sort is checked against the
 * report's own metric/dimension list, which is what makes it resolvable.
 *
 * WHAT THIS CANNOT SEE
 * --------------------
 * Only literal strings. Reports that build their metric list at runtime, and
 * tabbed reports that inherit metrics from the tab definitions in
 * ReportController::pre(), are counted and reported but not verified -- the sort
 * check is skipped wherever the metric list is not a literal, because otherwise
 * every tabbed report false-positives. testCoverageIsReported keeps that gap
 * visible instead of letting a shrinking denominator look like success.
 */
final class ReportMetricDimensionContractTest extends TestCase
{
    /** @var string[] */
    private static $metrics = [];
    /** @var string[] */
    private static $dimensions = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        $svc = owa_coreAPI::serviceSingleton();

        self::$metrics = array_keys($svc->metrics);

        // Denormalized dimensions live in a separate registry keyed by name then
        // entity -- getDimension() alone does not see them, which is why
        // productName looks unregistered if you only check the flat map.
        self::$dimensions = array_values(array_unique(array_merge(
            array_keys($svc->dimensions),
            array_keys($svc->denormalizedDimensions)
        )));
    }

    /**
     * A report definition: one controller, or one makeApiLink(array(...)) block
     * in a template. Returns [label, metrics|null, dimensions|null, sort|null,
     * trendChartMetric|null] with null meaning "not a literal here".
     *
     * @return array<int, array{0:string,1:?string,2:?string,3:?string,4:?string}>
     */
    private function scopes(): array
    {
        $root  = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/modules/*/Controller/Report*.php') ?: [],
            glob($root . '/modules/*/templates/report_*.php') ?: [],
            // Converted reports declare their metrics in JSON now. Without
            // this, converting a report silently removes it from this contract
            // -- which is what the count guard below caught when 35 of them
            // moved at once.
            glob($root . '/modules/*/reports/*.json') ?: []
        );

        $out = [];

        foreach ($files as $file) {
            $src   = file_get_contents($file);
            $label = str_replace($root . '/', '', $file);

            $blocks = [$src];
            if (strpos($label, '/templates/') !== false) {
                $blocks = preg_match_all('/makeApiLink\(\s*array\((.*?)\)\s*,/s', $src, $m)
                    ? $m[1]
                    : [];
            }

            foreach ($blocks as $block) {
                $grab = static function (string $key) use ($block): ?string {
                    // ':' as well as ',' and '=>', so a JSON definition's
                    // "metrics": "pageViews,visits" is read the same way the
                    // controller's set('metrics', 'pageViews,visits') was.
                    $re = '/[\'"]' . $key . '[\'"]\s*(?:,|=>|:)\s*[\'"]([^\'"]*)[\'"]/';
                    return preg_match($re, $block, $mm) ? $mm[1] : null;
                };

                $out[] = [
                    $label,
                    $grab('metrics'),
                    $grab('dimensions'),
                    $grab('sort'),
                    $grab('trendChartMetric'),
                ];
            }
        }

        return $out;
    }

    /** @return string[] */
    private function names(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
    }

    public function testEveryNamedMetricIsRegistered(): void
    {
        $bad = [];

        foreach ($this->scopes() as [$label, $metrics, , , $trend]) {
            foreach ($this->names($metrics) as $name) {
                if (!in_array($name, self::$metrics, true)) {
                    $bad[] = "$label: metric '$name'";
                }
            }
            if ($trend !== null && $trend !== '' && !in_array($trend, self::$metrics, true)) {
                $bad[] = "$label: trendChartMetric '$trend'";
            }
        }

        $this->assertSame([], $bad,
            "Reports naming a metric that is not registered:\n" . implode("\n", $bad));
    }

    public function testEveryNamedDimensionIsRegistered(): void
    {
        $bad = [];

        foreach ($this->scopes() as [$label, , $dimensions, ,]) {
            foreach ($this->names($dimensions) as $name) {
                if (!in_array($name, self::$dimensions, true)) {
                    $bad[] = "$label: dimension '$name'";
                }
            }
        }

        $this->assertSame([], $bad,
            "Reports naming a dimension that is not registered:\n" . implode("\n", $bad));
    }

    /**
     * The sort must name something that exists at all. This is the weaker of the
     * two sort checks and catches outright typos --
     * 'transactionsRevenue' for 'transactionRevenue' was one character and cost
     * a thrown exception on every render of that report.
     */
    public function testEverySortNamesSomethingRegistered(): void
    {
        $bad = [];

        foreach ($this->scopes() as [$label, , , $sort,]) {
            if ($sort === null || $sort === '') {
                continue;
            }
            $base = rtrim($sort, '-+');
            if (!in_array($base, self::$metrics, true) && !in_array($base, self::$dimensions, true)) {
                $bad[] = "$label: sort '$base'";
            }
        }

        $this->assertSame([], $bad,
            "Reports sorting by a name that is not a registered metric or dimension:\n" . implode("\n", $bad));
    }

    /**
     * And it must be something THIS report queries. A globally-registered name is
     * not enough: 'actions' is a real metric, but ReportProducts does not query
     * it, so the sort could not resolve and the dimensional query returned no
     * rows -- a page reading "0 products sold" on a site with sales.
     *
     * Only checked where the metric list is a literal. Tabbed reports inherit
     * their metrics from ReportController::pre(), so their sort legitimately
     * names something this file never mentions.
     */
    public function testEverySortIsAmongTheReportsOwnMetricsAndDimensions(): void
    {
        $bad = [];

        foreach ($this->scopes() as [$label, $metrics, $dimensions, $sort,]) {
            if ($sort === null || $sort === '' || $metrics === null) {
                continue;
            }
            $own = array_merge($this->names($metrics), $this->names($dimensions));
            if (!$own) {
                continue;
            }
            $base = rtrim($sort, '-+');
            if (!in_array($base, $own, true)) {
                $bad[] = sprintf("%s: sort '%s' but queries %s", $label, $base, implode(',', $own));
            }
        }

        $this->assertSame([], $bad,
            "Reports sorting by something they do not query:\n" . implode("\n", $bad));
    }

    /**
     * Coverage, stated rather than implied.
     *
     * These checks only see literal strings. If that number quietly fell to a
     * handful the suite would still be green while checking almost nothing, so
     * the floor is asserted -- and the unverifiable count is printed so the gap
     * is a known quantity rather than an unknown one.
     */
    public function testCoverageIsReported(): void
    {
        $scopes  = $this->scopes();
        $checked = 0;
        $dynamic = 0;

        foreach ($scopes as [, $metrics, $dimensions, $sort, $trend]) {
            $checked += count($this->names($metrics))
                      + count($this->names($dimensions))
                      + ($sort  !== null && $sort  !== '' ? 1 : 0)
                      + ($trend !== null && $trend !== '' ? 1 : 0);
            if ($metrics === null) {
                $dynamic++;
            }
        }

        /*
         * Derived from the corpus on disk, not a remembered number.
         *
         * The failure this guards is the parser quietly matching nothing: the
         * suite stays green while checking almost none of what it claims to.
         * Tying the floor to how many reports EXIST catches that -- a broken
         * parser collapses `checked` while the files are all still there --
         * and lets a deliberate deletion lower it without anyone editing a
         * constant. A hardcoded floor cannot tell those two apart, and the
         * only way to keep it passing is to nudge it down, which is exactly
         * the edit that would hide the first case.
         *
         * Two names per report is the conservative claim: every report names
         * at least one metric and one dimension.
         */
        $reports = glob(OWA_DIR . 'modules/*/reports/*.json');

        $this->assertGreaterThanOrEqual(count($reports), count($scopes),
            'fewer scopes than report definitions -- scope discovery has stopped '
            . 'seeing reports it used to');

        $this->assertGreaterThan(2 * count($reports), $checked,
            'literal metric/dimension references checked dropped sharply -- has the '
            . 'parser stopped matching, or have reports moved to runtime metric lists? '
            . "checked=$checked across " . count($scopes) . ' scopes, for '
            . count($reports) . ' report definitions');

        $this->addToAssertionCount(1);
        fwrite(STDERR, sprintf(
            "\n  [report contract] %d literal names checked across %d scopes; "
            . "%d scopes build metrics at runtime and are not sort-checked\n",
            $checked, count($scopes), $dynamic
        ));
    }
}
