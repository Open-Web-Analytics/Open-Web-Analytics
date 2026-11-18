<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** A module with no registrations of its own. */
class NavigationSubGroupProbeModule extends \OWA\Core\Module
{
}

/**
 * Module::addNavigationSubGroup() keeps the links already added to that
 * subgroup.
 *
 * A link added before the subgroup is declared survives the declaration, and
 * so do a subgroup's links a second declaration of it.
 */
final class NavigationSubGroupTest extends TestCase
{
    private function module(): NavigationSubGroupProbeModule
    {
        return (new ReflectionClass(NavigationSubGroupProbeModule::class))->newInstanceWithoutConstructor();
    }

    public function testALinkAddedFirstIsKept(): void
    {
        $m = $this->module();
        $m->addNavigationLinkInSubGroup('Probe', 'probe.first', 'First', 1);
        $m->addNavigationSubGroup('Probe', 'probe.home', 'Probe', 3);

        $entry = $m->getNavigationLinks()['Reports']['Probe'];

        $this->assertSame('probe.home', $entry['ref']);
        $this->assertSame('Probe', $entry['anchortext']);
        $this->assertSame(['probe.first'], array_column($entry['subgroup'], 'ref'));
    }

    /** Declared again, the subgroup takes the new label, link and order, and keeps its links. */
    public function testDeclaringItAgainKeepsItsLinks(): void
    {
        $m = $this->module();
        $m->addNavigationSubGroup('Probe', 'probe.home', 'Probe', 3);
        $m->addNavigationLinkInSubGroup('Probe', 'probe.first', 'First', 1);
        $m->addNavigationSubGroup('Probe', 'probe.other', 'Renamed', 4);

        $entry = $m->getNavigationLinks()['Reports']['Probe'];

        $this->assertSame('probe.other', $entry['ref']);
        $this->assertSame('Renamed', $entry['anchortext']);
        $this->assertSame(4, $entry['order']);
        $this->assertSame(['probe.first'], array_column($entry['subgroup'], 'ref'));
    }

    /** The order Base registers in, subgroup then links, is unchanged. */
    public function testASubgroupThenItsLinks(): void
    {
        $m = $this->module();
        $m->addNavigationSubGroup('Probe', 'probe.home', 'Probe', 3, 'view_reports', 'Reports', 'fa fa-probe');
        $m->addNavigationLinkInSubGroup('Probe', 'probe.first', 'First', 1);

        $this->assertSame([
            'ref'        => 'probe.home',
            'anchortext' => 'Probe',
            'order'      => 3,
            'priviledge' => 'view_reports',
            'icon_class' => 'fa fa-probe',
            'subgroup'   => [[
                'ref'        => 'probe.first',
                'anchortext' => 'First',
                'order'      => 1,
                'priviledge' => 'view_reports',
                'icon_class' => '',
            ]],
        ], $m->getNavigationLinks()['Reports']['Probe']);
    }
}
