<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A nav group renders by its entries' `order`, and each entry's links by theirs.
 *
 * The number was stored and never read, so the report nav came out in module
 * registration order and a new entry went wherever it was added.
 */
final class NavigationSortTest extends TestCase
{
    private array $modules;

    protected function setUp(): void
    {
        $this->modules = \OWA\Core\CoreAPI::serviceSingleton()->modules;
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules = $this->modules;
    }

    private function useNav(array $nav_links): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules = ['probe' => new class($nav_links) {
            public $nav_links;
            public function __construct($nav_links) { $this->nav_links = $nav_links; }
            public function registerNavigation() {}
            public function getNavigationLinks() { return $this->nav_links; }
        }];
    }

    private static function entry(?int $order, array $subgroup = []): array
    {
        return ['ref' => ['do' => 'base.report'], 'anchortext' => 'x', 'order' => $order,
            'priviledge' => 'view_reports'] + ($subgroup ? ['subgroup' => $subgroup] : []);
    }

    public function testEntriesAreByOrderAndEqualOrdersKeepRegistrationOrder(): void
    {
        $this->useNav(['Reports' => [
            'Late'   => self::entry(9),
            'Second' => self::entry(2),
            'First'  => self::entry(1),
            'Tie A'  => self::entry(5),
            'Tie B'  => self::entry(5),
        ]]);

        $this->assertSame(['First', 'Second', 'Tie A', 'Tie B', 'Late'],
            array_keys(\OWA\Core\CoreAPI::getGroupNavigation('Reports')));
    }

    /** An entry that never said where it goes does not jump to the top. */
    public function testAnEntryWithNoOrderFollowsTheOrderedOnes(): void
    {
        $this->useNav(['Reports' => [
            'Unordered A' => self::entry(null),
            'Ordered'     => self::entry(3),
            'Unordered B' => self::entry(null),
            'Zero'        => self::entry(0),
        ]]);

        $this->assertSame(['Zero', 'Ordered', 'Unordered A', 'Unordered B'],
            array_keys(\OWA\Core\CoreAPI::getGroupNavigation('Reports')));
    }

    public function testAnEntrysLinksAreSortedToo(): void
    {
        $this->useNav(['Reports' => ['Things' => self::entry(1, [
            ['anchortext' => 'Third', 'order' => 3],
            ['anchortext' => 'First', 'order' => 1],
            ['anchortext' => 'Last'],
            ['anchortext' => 'Second', 'order' => 2],
        ])]]);

        $this->assertSame(['First', 'Second', 'Third', 'Last'],
            array_column(\OWA\Core\CoreAPI::getGroupNavigation('Reports')['Things']['subgroup'], 'anchortext'));
    }

    /** Acts only while a test switches it on; filters cannot be unregistered. */
    public static bool $reverse = false;

    public static function reverseWhenOn($nav)
    {
        return self::$reverse && is_array($nav) ? array_reverse($nav, true) : $nav;
    }

    /** Sorted before nav_reports runs, so a filter that reorders has the last word. */
    public function testAFilterStillReordersAfterTheSort(): void
    {
        $this->useNav(['Reports' => ['A' => self::entry(1), 'B' => self::entry(2)]]);

        owa_coreAPI::registerFilter('nav_reports', self::class . '::reverseWhenOn', 99);
        self::$reverse = true;

        try {
            $this->assertSame(['B', 'A'], array_keys(\OWA\Core\CoreAPI::getGroupNavigation('Reports')));
        } finally {
            self::$reverse = false;
        }
    }

    /** Base's report groups, in the order they declare. */
    public function testBasesGroupsAreInTheirDeclaredOrder(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules = $this->modules;

        $groups = array_keys((array) \OWA\Core\CoreAPI::getGroupNavigation('Reports'));
        $base   = array_values(array_intersect($groups,
            ['Dashboard', 'Traffic', 'Visitors', 'Content', 'Ecommerce', 'Goals', 'Custom Reports']));

        $this->assertSame(['Dashboard', 'Traffic', 'Visitors', 'Content', 'Ecommerce', 'Goals', 'Custom Reports'], $base);
    }
}
