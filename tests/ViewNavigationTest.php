<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** Adds one link to a view's nav, and counts how often it is asked to. */
class ViewNavigationProbeModule extends \OWA\Core\Module
{
    /** @var int */
    public $registered = 0;

    public function registerNavigation()
    {
        $this->registered++;
        $this->addViewNavigationLink('probe.view', 'probe_nav', 'probe.second', 'Second', 2);
        $this->addViewNavigationLink('probe.view', 'probe_nav', 'probe.first', 'First', 1);
        $this->addViewNavigationLink('probe.view', 'other_nav', 'probe.elsewhere', 'Elsewhere', 1);

        return true;
    }
}

/**
 * CoreAPI::getNavigation() answers the links modules add to a view's named nav.
 *
 * It read `view` and `nav_name` off the module's report nav, which is group =>
 * subgroup => item and names neither, so nothing a module registered reached
 * it: only the nav_view filter could add an entry.
 */
final class ViewNavigationTest extends TestCase
{
    private const NAME = 'zzviewnavprobe';

    private function inject(\OWA\Core\Module $m): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->modules[self::NAME] = $m;
    }

    protected function tearDown(): void
    {
        unset(\OWA\Core\CoreAPI::serviceSingleton()->modules[self::NAME]);
    }

    private function probe(): ViewNavigationProbeModule
    {
        return (new ReflectionClass(ViewNavigationProbeModule::class))->newInstanceWithoutConstructor();
    }

    public function testAModulesLinksAreTheViewsNavInOrder(): void
    {
        $this->inject($this->probe());

        $nav = (array) \OWA\Core\CoreAPI::getNavigation('probe.view', 'probe_nav');

        $this->assertSame(['probe.first', 'probe.second'], array_column($nav, 'ref'));
        $this->assertSame('First', $nav[0]['anchortext']);
        $this->assertSame('view_reports', $nav[0]['priviledge']);
    }

    /** Asked again, or for the report nav, the module registers once and its links appear once. */
    public function testRegisteredOnce(): void
    {
        $m = $this->probe();
        $this->inject($m);

        \OWA\Core\CoreAPI::getNavigation('probe.view', 'probe_nav');
        \OWA\Core\CoreAPI::getGroupNavigation('Reports');
        $nav = (array) \OWA\Core\CoreAPI::getNavigation('probe.view', 'probe_nav');

        $this->assertSame(1, $m->registered);
        $this->assertCount(2, $nav);
    }

    public function testAnotherNavIsNotThisOne(): void
    {
        $this->inject($this->probe());

        $this->assertSame(['probe.elsewhere'],
            array_column((array) \OWA\Core\CoreAPI::getNavigation('probe.view', 'other_nav'), 'ref'));
        $this->assertFalse(\OWA\Core\CoreAPI::getNavigation('probe.view', 'no_such_nav'));
    }

    /** The 1.0 form -- an entry written into nav_links with its own view and nav_name -- is not read. */
    public function testANavLinksEntryNamingItsViewIsNotRead(): void
    {
        $m = $this->probe();
        $m->nav_links = [[
            'view' => 'probe.view', 'nav_name' => 'legacy_nav', 'ref' => 'probe.legacy', 'anchortext' => 'Legacy', 'order' => 1,
        ]];
        $this->inject($m);

        $this->assertFalse(\OWA\Core\CoreAPI::getNavigation('probe.view', 'legacy_nav'));
    }
}
