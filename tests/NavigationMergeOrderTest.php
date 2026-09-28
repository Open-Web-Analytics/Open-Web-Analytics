<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A module that only ADDS a link to another module's nav subgroup, whichever
 * order the modules load in.
 *
 * Load order is activation order, and a module activated after install can
 * sort ahead of Base. The subgroup then kept the adding module's entry -- a
 * link list with no label, link or order of its own -- and the group rendered
 * without its heading.
 */
final class NavigationMergeOrderTest extends TestCase
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

    private function navModule(array $nav_links): object
    {
        return new class($nav_links) {
            public $nav_links;
            public function __construct($nav_links) { $this->nav_links = $nav_links; }
            public function registerNavigation() {}
            public function getNavigationLinks() { return $this->nav_links; }
        };
    }

    public static function orders(): array
    {
        return ['defining module first' => [false], 'adding module first' => [true]];
    }

    /**
     * @dataProvider orders
     */
    public function testTheSubgroupKeepsItsDefinitionAndGainsTheLink(bool $adder_first): void
    {
        $definer = $this->navModule(['Reports' => ['Things' => [
            'ref' => ['do' => 'base.report', 'reportId' => 'things'], 'anchortext' => 'Things', 'order' => 4,
            'priviledge' => 'view_reports',
            'subgroup' => [['anchortext' => 'Alpha', 'order' => 1]],
        ]]]);
        $adder = $this->navModule(['Reports' => ['Things' => [
            'subgroup' => [['anchortext' => 'Beta', 'order' => 2]],
        ]]]);

        \OWA\Core\CoreAPI::serviceSingleton()->modules = $adder_first
            ? ['adder' => $adder, 'definer' => $definer]
            : ['definer' => $definer, 'adder' => $adder];

        $things = \OWA\Core\CoreAPI::getGroupNavigation('Reports')['Things'];

        $this->assertSame('Things', $things['anchortext']);
        $this->assertSame(['do' => 'base.report', 'reportId' => 'things'], $things['ref']);
        $this->assertSame(4, $things['order']);
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], array_column($things['subgroup'], 'anchortext'));
    }
}
