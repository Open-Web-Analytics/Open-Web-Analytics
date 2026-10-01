<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every navigation list in the admin UI goes through a named filter, so a
 * module can add, remove or reorder entries without Base knowing it exists.
 *
 *   nav_header, nav_user_menu, nav_help_menu, nav_site_control
 *       lists templates build, finished by Core\Navigation::links()
 *   nav_reports, nav_settings, nav_view, nav_hierarchy
 *       lists the registries assemble, filtered after every module contributed
 *   report_links
 *       each report-links widget's links, in ConfiguredReport
 *
 * The callbacks stay registered (the dispatcher has no removal), so each acts
 * only while self::$on names its filter.
 */
final class NavigationFiltersTest extends TestCase
{
    /** @var string the filter the callbacks act on, '' for none */
    public static $on = '';

    /** @var array context the last callback received */
    public static $context = [];

    protected function setUp(): void
    {
        self::$on = '';
        self::$context = [];

        foreach (['nav_header', 'nav_site_control', 'nav_reports', 'nav_settings',
                  'nav_view', 'nav_hierarchy', 'report_links'] as $filter) {
            \OWA\Core\CoreAPI::registerFilter($filter, self::class . '::on_' . $filter, 99);
        }
    }

    protected function tearDown(): void
    {
        self::$on = '';
    }

    /** One callback name per filter (on_<filter>), so only the one switched on acts. */
    public static function __callStatic($name, $args)
    {
        $filter = substr($name, 3);
        $items  = array_shift($args);

        if (self::$on !== $filter) {
            return $items;
        }

        return self::edit($items, ...$args);
    }

    /**
     * Adds an entry and removes the one with id `remove-me`: entries are what
     * the filter is given.
     */
    private static function edit($items, ...$context)
    {
        self::$context = $context;

        if (!is_array($items)) {
            return $items;
        }

        $items = array_filter($items, function ($item) {
            return !is_array($item) || ($item['id'] ?? '') !== 'remove-me';
        });

        if (array_is_list($items)) {
            $items = array_values($items);
        }

        $items[] = ['id' => 'added', 'label' => 'Added by a module', 'href' => 'https://example.test/added'];

        return $items;
    }

    /* ---------------- template-built lists ---------------- */

    public function testALinkListIsFilteredThenGated(): void
    {
        self::$on = 'nav_header';

        $links = \OWA\Core\Navigation::links('nav_header', [
            ['id' => 'keep', 'label' => 'Keep', 'href' => 'https://example.test/keep'],
            ['id' => 'remove-me', 'label' => 'Gone', 'href' => 'https://example.test/gone'],
            ['id' => 'nobody', 'label' => 'Nobody', 'href' => 'https://example.test/x',
             'capability' => 'no_such_capability_anyone_has'],
            ['id' => 'no-href', 'label' => 'No href'],
        ], 'context-a');

        // The gate keeps an entry exactly when the current user has its
        // capability -- an admin has every one -- so the expectation follows
        // whoever this process is signed in as.
        $capable = \OWA\Core\CoreAPI::getCurrentUser()->isCapable('no_such_capability_anyone_has');

        $this->assertSame($capable ? ['keep', 'nobody', 'added'] : ['keep', 'added'],
            array_column($links, 'id'),
            'the filter added and removed; the capability gate and the href check ran after it');
        $this->assertSame(['context-a'], self::$context);
    }

    /**
     * The templates build their lists through Core\Navigation with these names,
     * so none of them is hard-coded again without anyone noticing.
     */
    public function testTheTemplatesBuildTheirListsThroughTheFilters(): void
    {
        $header = (string) file_get_contents(OWA_DIR . 'modules/Base/templates/header.php');

        foreach (['nav_header', 'nav_user_menu', 'nav_help_menu'] as $filter) {
            $this->assertStringContainsString("Navigation::links( '$filter'", $header);
        }

        $siteControl = (string) file_get_contents(OWA_DIR . 'modules/Base/templates/site_control.php');

        $this->assertSame(5, substr_count($siteControl, "Navigation::links( 'nav_site_control'"),
            'every row and column action in the site control goes through the filter');
        $this->assertStringNotContainsString("isCapable('edit_sites') ):?>\n                    <a", $siteControl);
    }

    /* ---------------- registry-built lists ---------------- */

    public function testTheReportNavIsFilteredAfterTheMerge(): void
    {
        self::$on = 'nav_reports';

        $nav = (array) \OWA\Core\CoreAPI::getGroupNavigation('Reports');

        $this->assertSame('Added by a module', end($nav)['label'] ?? null);
        $this->assertSame(['Reports'], self::$context);
    }

    public function testTheSettingsPanelsAreFiltered(): void
    {
        self::$on = 'nav_settings';

        $panels = \OWA\Core\CoreAPI::getAdminPanels();

        $this->assertSame('added', end($panels)['id'] ?? null);
    }

    public function testAViewNavIsFilteredBeforeSorting(): void
    {
        self::$on = 'nav_view';

        $nav = (array) \OWA\Core\CoreAPI::getNavigation('some.view', 'some_nav');

        $this->assertContains('added', array_column($nav, 'id'),
            'a filter can put an entry on a nav nobody registered anything for');
        $this->assertSame(['some.view', 'some_nav'], self::$context);
    }

    public function testTheHierarchyNavIsFiltered(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the hierarchy nav reads the registered admin panels');
        }

        self::$on = 'nav_hierarchy';

        $controller = new class([]) extends \OWA\Core\Controller {
            public function nav() { return $this->getHierarchyNav('', ''); }
        };

        $nav = $controller->nav();

        $this->assertArrayHasKey('My Preferences', $nav);
        $this->assertSame('added', end($nav)['id'] ?? null);
        $this->assertSame(['', ''], self::$context);
    }

    /* ---------------- report definitions ---------------- */

    public function testReportLinksAreFilteredPerReportAndWidget(): void
    {
        self::$on = 'report_links';

        $widgets = \OWA\Core\ConfiguredReport::filterReportLinks([
            ['type' => 'trend', 'id' => 'trend'],
            ['type' => 'report-links', 'id' => 'more', 'links' => [
                ['id' => 'remove-me', 'reportId' => 'x', 'label' => 'X'],
            ]],
        ], 'document');

        $this->assertSame('trend', $widgets[0]['id'], 'a widget that is not links passes through');
        $this->assertSame(['added'], array_column($widgets[1]['links'], 'id'));
        $this->assertSame(['document', 'more'], self::$context);
    }

    public function testALinksWidgetLeftEmptyIsDropped(): void
    {
        $widgets = \OWA\Core\ConfiguredReport::filterReportLinks([
            ['type' => 'report-links', 'id' => 'empty', 'links' => []],
            ['type' => 'trend', 'id' => 'trend'],
        ], 'content');

        $this->assertSame(['trend'], array_column($widgets, 'id'));
    }
}
