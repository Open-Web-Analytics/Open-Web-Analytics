<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What the Domstream module registers, asked of the module itself.
 *
 * Constructing a module registers into the service's maps, and the module is
 * not active on a fresh install, so each case constructs it against a
 * snapshot of the maps and puts them back.
 */
final class DomstreamModuleTest extends TestCase
{
    private array $maps;

    protected function setUp(): void
    {
        $this->maps = \OWA\Core\CoreAPI::serviceSingleton()->maps;
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->maps = $this->maps;
    }

    private function module(): \OWA\Module\Domstream\Module
    {
        return new \OWA\Module\Domstream\Module();
    }

    public function testTheDomstreamEventIsRoutedToTheModulesProcessor(): void
    {
        $this->module();

        $this->assertSame('domstream.processEvent', \OWA\Core\CoreAPI::serviceSingleton()
            ->getMapValue('event_processors', \OWA\Core\CoreAPI::trackingDispatchName('domstream')));
    }

    public function testItsActionsResolveToItsOwnControllers(): void
    {
        $this->module();

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ([
            'domstream.processEvent'     => \OWA\Module\Domstream\Controller\ProcessEvent::class,
            'domstream.reportDomstreams' => \OWA\Module\Domstream\Controller\ReportDomstreams::class,
            'domstream.domstreamsRest'   => \OWA\Module\Domstream\Controller\DomstreamsRestController::class,
        ] as $action => $class) {
            $this->assertSame($class, $s->getMapValue('actions', $action)['class_name'] ?? null, $action);
            $this->assertTrue(class_exists($class), $class);
        }
    }

    public function testItsTablesAreNamedForIt(): void
    {
        $module = $this->module();

        $this->assertSame(['domstream_chunk', 'domstream_payload'], $module->entities);

        foreach ($module->entities as $name) {
            $this->assertStringContainsString('domstream_',
                \OWA\Core\CoreAPI::entityFactory('domstream.' . $name)->getTableName());
        }
    }

    public function testTheReportIsItsController(): void
    {
        $module = $this->module();
        $module->registerReports();

        $this->assertSame('domstream.reportDomstreams', \OWA\Core\CoreAPI::serviceSingleton()
            ->getMapValue('reports', 'domstreams')['controller'] ?? null);
    }

    /**
     * log.php passes only registered client properties, and the tracker's
     * common ones are Base's. Without these a chunk arrives with no samples
     * and is refused.
     */
    public function testLogPhpAdmitsAChunksFields(): void
    {
        $this->module();

        $params = [
            'recording_id' => '1', 'seq' => '1', 'page_view_seq' => '1', 'offset_ms' => '0',
            'duration_ms' => '1', 'viewport_w' => '1', 'viewport_h' => '1', 'samples' => '[]',
        ];

        $this->assertSame($params,
            \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams($params));

        foreach (\OWA\Module\Domstream\Module::trackingProperties() as $name => $property) {
            $this->assertSame(['domstream'], $property['events'], "$name is scoped to the recorder's event");
            $this->assertArrayNotHasKey('column', $property, "$name is not a column of the event table");
        }
    }

    public function testTheSnippetStartsTheRecorder(): void
    {
        $this->assertSame(['a', "owa_cmds.push(['trackDomStream']);"],
            $this->module()->addToTracker(['a']));
    }

    public function testPageDetailLinksToThisPagesRecordings(): void
    {
        $links = $this->module()->addReportLinks([['reportId' => 'pages']], 'document', 'moreAnalytics');

        $this->assertSame('domstreams', $links[0]['reportId']);
        $this->assertSame(['pagePath' => '{pagePath}'], $links[0]['params']);
        $this->assertSame('pages', $links[1]['reportId'], 'what was there stays');
    }

    public function testContentsRelatedReportsLinkToRecordings(): void
    {
        $links = $this->module()->addReportLinks([], 'content', 'related');

        $this->assertSame([['reportId' => 'domstreams', 'label' => 'Recordings']], $links);
    }

    public function testNoOtherWidgetGainsALink(): void
    {
        $module = $this->module();

        foreach ([['document', 'related'], ['content', 'moreAnalytics'], ['pages', 'related'], ['', '']] as [$report, $widget]) {
            $this->assertSame([['reportId' => 'x']], $module->addReportLinks([['reportId' => 'x']], $report, $widget),
                "$report/$widget");
        }
    }
}
