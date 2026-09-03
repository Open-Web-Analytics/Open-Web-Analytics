<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Regression guard for three latent bugs the $view migration surfaced.
 *
 * The migration to ViewScope (see ViewScopeCompatTest) rewrote OWA's own
 * templates to read view data as `$view->name`. Twenty names were left on the
 * bare-variable path because migrating them on inference would have risked a
 * 500 -- and three of those turned out to be reads of a variable that NO
 * controller, view or includer sets anywhere in the tree:
 *
 *   report_dimensionalTrend.php:57  $tag['sort']   -- a typo for $tab['sort']
 *   generic_table.php:20            $th_scope      -- rendered scope=""
 *   report_visitors_roster.php:2    $date_label    -- rendered an empty label
 *
 * PHPStan reported all three as variable.undefined. They could not simply be
 * renamed to `$view->...`: an undefined bare variable is a warning that yields
 * null, whereas `$view->neverSet` throws, so the fix had to establish where the
 * value legitimately comes from. These tests pin those answers by rendering the
 * ACTUAL template files.
 */
final class TemplateLatentVarTest extends TestCase
{
    /**
     * A Template with makeApiLink() stubbed to echo back the params it was
     * handed. report_dimensionalTrend.php builds its REST URLs through that
     * method, and the real one reaches getCurrentUser() for a nonce, which
     * needs a DB. Stubbing it keeps the test DB-free while still exercising the
     * template's own logic -- the sort value it chooses is what is under test.
     */
    private function templateSpy(): object
    {
        return new class('base') extends \OWA\Core\Template {

            /** @var array<int, array<string, mixed>> every makeApiLink() param set, in order */
            public array $apiCalls = [];

            function makeApiLink($params = array(), $add_state = false, $add_apiKey = false) {

                $this->apiCalls[] = $params;
                return 'API?sort=' . ($params['sort'] ?? '<none>');
            }
        };
    }

    /**
     * A Template for the admin add/edit forms. Stubs only the helpers that would
     * otherwise need a request/DB (nonce fields, links, the param namespace) --
     * everything the form template decides for itself is left real.
     */
    private function formSpy(): object
    {

        return new class('base') extends \OWA\Core\Template {

            function createNonceFormField($action) { return "<!--nonce:$action-->"; }

            function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) {

                return 'LINK';
            }

            // Delegates rather than hardcoding: this used to return
            // 'owa_', which stopped being what the admin forms emit when
            // the namespace split moved them onto app_ns. A double that
            // pins the old answer renders markup no user ever sees.
            function getNs() { return \OWA\Core\CoreAPI::appNs(); }
        };
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(object $t, string $file, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }
        $this->assertTrue($t->set_template($file), "could not locate template $file");
        return $t->fetch();
    }

    /**
     * The dimension grid's sort falls back to the tab's own sort when the view
     * sets no sort of its own.
     *
     * WHY THIS MATTERS: `$view->sort ?: $tag['sort']` could never fall back --
     * $tag is never set, so the fallback yielded null and the grid requested the
     * REST API with NO sort at all. All 22 shipped controllers that use
     * base.reportDimension do set 'sort', so this is latent for OWA's own
     * reports; it fires for a third-party module (or a future controller) that
     * relies on the per-tab sort instead. $tab is the foreach value at line 29
     * of the same template, and every tab array built in ReportController has a
     * 'sort' key.
     */
    public function testDimensionGridFallsBackToTheTabSortWhenTheViewSetsNone(): void
    {
        $t = $this->templateSpy();

        $out = $this->render($t, 'report_dimensionalTrend.php', [
            'tabs' => [
                'site_usage' => [
                    'tab_label'          => 'Site Usage',
                    'metrics'            => 'visits',
                    'sort'               => 'visits-',
                    'trendchartmetric'   => 'visits',
                ],
            ],
            'metrics'          => 'visits',
            'dimensions'       => 'browser',
            'sort'             => '',   // the view sets no sort -> fall back to the tab's
            'resultsPerPage'   => 25,
            'dimensionLink'    => '',
            'trendChartMetric' => 'visits',
            'trendTitle'       => '',
            'constraints'      => '',
            'gridTitle'        => '',
            'gridFormatters'   => '',
            'excludeColumns'   => '',
            'dom_id'           => 'base-reportBrowsers',
            'pie'              => false,
            'hideGrid'         => false,
        ]);

        // Two API links per tab: the trend (always sorted by date) then the grid.
        $this->assertSame(['date', 'visits-'], array_column($t->apiCalls, 'sort'));
        $this->assertStringContainsString("var dimurl = 'API?sort=visits-'", $out);
        // Against the $tag typo this read 'API?sort=<none>'.
        $this->assertStringNotContainsString('sort=<none>', $out);
    }

    /**
     * An explicit sort on the view still wins over the tab's.
     *
     * This one passes against the buggy template too, deliberately: `?:`
     * short-circuits, so $tag was never evaluated on this path. That is exactly
     * why the typo survived -- every shipped report sets a sort. The test is
     * here to keep the precedence rule from being "fixed" in the other
     * direction.
     */
    public function testAnExplicitViewSortWinsOverTheTabSort(): void
    {
        $t = $this->templateSpy();

        $out = $this->render($t, 'report_dimensionalTrend.php', [
            'tabs' => [
                'site_usage' => ['tab_label' => 'Site Usage', 'metrics' => 'visits', 'sort' => 'visits-'],
            ],
            'metrics'          => 'visits',
            'dimensions'       => 'browser',
            'sort'             => 'pageViews-',
            'resultsPerPage'   => 25,
            'dimensionLink'    => '',
            'trendChartMetric' => 'visits',
            'trendTitle'       => '',
            'constraints'      => '',
            'gridTitle'        => '',
            'gridFormatters'   => '',
            'excludeColumns'   => '',
            'dom_id'           => 'base-reportBrowsers',
            'pie'              => false,
            'hideGrid'         => false,
        ]);

        $this->assertSame(['date', 'pageViews-'], array_column($t->apiCalls, 'sort'));
        $this->assertStringContainsString("var dimurl = 'API?sort=pageViews-'", $out);
    }

    /**
     * Column headers carry scope="col".
     *
     * The <TH> elements are the single header row inside <thead>, so "col" is
     * the only correct value -- and nothing ever set $th_scope, so no caller can
     * want anything else. Previously every header rendered scope="", which is
     * invalid HTML and tells a screen reader nothing.
     */
    public function testGenericTableHeadersDeclareColumnScope(): void
    {
        $out = $this->render(new \OWA\Core\Template('base'), 'generic_table.php', [
            'rows'               => [['Firefox', '42']],
            'labels'             => ['Browser', 'Visits'],
            'table_id'           => 'owa-test-table',
            'table_class'        => 'owa_table',
            'sort_table_class'   => 'owa_sortable',
            'table_footer'       => '',
            'col_count'          => 2,
            'table_row_template' => '',
            'show_error'         => false,
        ]);

        $this->assertStringContainsString('<TH scope="col">Browser</TH>', $out);
        $this->assertStringNotContainsString('scope=""', $out);
    }

    /*
     * The visitor-roster headline test is GONE with its template.
     *
     * visitors-roster was dropped 2026-08-25: nothing linked to it and it was
     * not in the navigation, so it was unreachable in the running app. The
     * latent-variable rule it demonstrated is still enforced for every other
     * template by the sweep in this file.
     */

    /**
     * The three admin add/edit forms render on the ADD path, where the record
     * does not exist yet.
     *
     * WHY THIS IS THE TEST THAT MATTERS. Each form serves both add and edit, and
     * on the add path the record vars are empty. They used to be read as bare
     * variables behind an @, which silently yielded null. They are now
     * $view->site / $view->user / $view->goal, and $view->neverSet THROWS -- so if a
     * view ever stops setting one of these unconditionally, this test fails
     * instead of an admin getting a 500. The hoisting that makes that safe lives
     * in Core/View.php (validation_errors) and View/SitesAdd.php +
     * View/SitesProfile.php (site, config).
     *
     * @dataProvider addPathForms
     * @param array<string, mixed> $vars
     * @param array<int, string>   $expected
     */
    public function testAdminFormsRenderOnTheAddPath(string $template, array $vars, array $expected): void
    {
        // The add path renders with an EMPTY record, so every read of a record
        // field has to be guarded. An unguarded one is only a PHP warning --
        // the form still renders and the assertions below still pass -- so the
        // diagnostics are captured explicitly. Without this the template can
        // warn on every render and nothing goes red: options_goal_entry.php did
        // exactly that for $goal['goal_name'] and $goal['url'], visible only in
        // a CI log nobody reads.
        $diagnostics = [];

        set_error_handler(static function ($no, $str, $file, $line) use (&$diagnostics) {
            $diagnostics[] = sprintf('%s (%s:%d)', $str, basename((string) $file), $line);
            return true;
        });

        try {
            $out = $this->render($this->formSpy(), $template, $vars);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, "$template raised PHP diagnostics on the add path");

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $out, "$template: missing $needle");
        }
        // An empty record must not leak a literal 'null' or an array-to-string
        // conversion into a form field.
        $this->assertStringNotContainsString('value="null"', $out);
        $this->assertStringNotContainsString('Array', $out);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, string>}>
     */
    public static function addPathForms(): array
    {
        $users = [['id' => '7', 'user_id' => 'admin', 'real_name' => 'Ada L', 'role' => 'admin']];
        $siteEntity = new class { function isUserAssigned($id) { return false; } };

        return [
            'sites_addoredit (add)' => [
                'sites_addoredit.php',
                [
                    'headline' => 'Add Site', 'action' => 'base.sitesAdd', 'edit' => false,
                    'users' => $users, 'siteEntity' => $siteEntity, 'site_id' => '',
                    // what the hoisted views now always provide
                    'site' => [], 'config' => [], 'validation_errors' => [],
                ],
                /*
                 * The settings nonce moved with its form. sites_addoredit is the
                 * Profile's DETAILS now -- observation settings and access are
                 * their own screens under the hierarchy nav, so the details
                 * template carries only its own nonce.
                 */
                ['name="domain"', '<!--nonce:base.sitesAdd-->'],
            ],
            'users_addoredit (add)' => [
                'users_addoredit.php',
                [
                    'headline' => 'Add user', 'action' => 'base.usersAdd', 'edit' => false,
                    'isAdmin' => false, 'roles' => ['admin', 'viewer'], 'user' => [],
                ],
                ['name="user_id"', 'name="email_address"'],
            ],
            /*
             * A funnel is a VISUALIZATION now, built on the reporting side, so
             * this is the screen that owns its steps.
             */
            'visualization_edit (add)' => [
                'visualization_edit.php',
                [
                    /*
                     * Exactly what View\VisualizationEdit sets. The KIND is no
                     * longer a list to choose from -- it was answered in the
                     * modal on the roster and arrives here settled, so the
                     * screen is handed the one kind and its words rather than
                     * every kind.
                     */
                    'visualization' => [], 'visualizationId' => '', 'steps' => [],
                    'visualizationType' => 'funnel',
                    'visualizationTypeLabel' => 'Funnel',
                    'visualizationTypeHint' => 'How many people reached each step of a path.',
                    'visualizationTypeIcon' => 'fas fa-filter',
                    // The goal events a step may name. EMPTY on purpose: a
                    // Property with none must still render the builder, and an
                    // empty picker is the case a foreach over an unset var
                    // would not distinguish from a missing one.
                    'goalEvents' => [],
                    'siteId' => 'owa-e2e',
                    'validation_errors' => [],
                ],
                ['name="name"', 'name="stepPath[]"', 'name="visualizationType"'],
            ],
            'goal_event_edit (add)' => [
                'goal_event_edit.php',
                [
                    /*
                     * Exactly what View\GoalEventEdit sets on the ADD path --
                     * goalEvent is an empty array there, not absent, and this
                     * test exists because a template reading a var nobody set
                     * used to render blank rather than fail.
                     */
                    'headline' => 'New Goal Event', 'siteId' => 'abc123',
                    'goalEvent' => [], 'goalEventId' => '',
                    'conditionProperties' => [ [ 'name' => 'page_uri', 'label' => 'Page URL' ] ],
                    'funnelSteps' => [], 'goalGroups' => [ 1 => 'Goal Group 1' ],
                    'conditions' => [], 'validation_errors' => [],
                ],
                ['name="name"', 'name="conditionValue[]"'],
            ],
        ];
    }

    /**
     * Core/View sets validation_errors UNCONDITIONALLY, so a form template can
     * read it without knowing which controller branch it arrived through.
     *
     * It used to be set only when the key was already in $this->data, which is
     * what forced sites_addoredit.php to read it behind an @. An empty array
     * rather than null keeps if()/empty() behaving exactly as they did when the
     * key was simply absent. Asserted against the source because the alternative
     * -- standing up a full View with a body template -- would test the harness
     * more than the guarantee.
     */
    public function testValidationErrorsIsAlwaysSetOnTheBody(): void
    {
        $src = file_get_contents(__DIR__ . '/../Core/View.php');

        // Single-quoted on purpose. These were double-quoted, so "$this->data"
        // interpolated the TEST's own (undefined) $data property and collapsed
        // to the empty string: the negative assertion searched for
        // "if (array_key_exists('validation_errors', )) {" and passed no matter
        // what View.php contained, and the positive one matched only by
        // accident. The undefined-property warning was the only trace, and
        // nothing was configured to show it.
        $this->assertStringNotContainsString(
            'if (array_key_exists(\'validation_errors\', $this->data)) {',
            $src,
            'validation_errors is conditional again -- a form reading $view->validation_errors will throw'
        );
        $this->assertStringContainsString(
            '$this->data[\'validation_errors\'] ?? []',
            $src,
            'expected the unconditional set with an [] default'
        );
    }
}
