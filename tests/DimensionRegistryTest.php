<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\CoreAPI;
use OWA\Module\Base\Classes\CustomReports;
use OWA\Module\Base\Classes\DimensionRegistry;
use OWA\Module\Base\Classes\ResultSetManager;
use OWA\Module\Base\Entity\CustomDimension;

/**
 * Classes\DimensionRegistry: one Property's dimensions, read by everything that
 * offers or checks a dimension name.
 *
 * Two Properties, each with an applied custom dimension (A cd_plan, B cd_seat),
 * and A with a pending one (cd_tier). The registrations are rows only: no cube
 * column is needed to be a name, so nothing here builds or queries a cube.
 * CustomDimensionBuildTest queries one.
 */
final class DimensionRegistryTest extends TestCase
{
    const A      = '7779300000000001';
    const B      = '7779300000000002';
    const SITE_A = 'owa-dimension-registry-site-a';
    const AUTHOR = 'dimension-registry-author@example.test';

    /** @var string[] custom report ids to delete */
    private $reports = [];

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $insert = function ($id, $property, $key, $state) {
            $db = CoreAPI::dbSingleton();
            $db->query(sprintf('INSERT INTO %s (id, property_id, scope, dimension_key, column_name, data_type, max_length,'
                . ' label, creation_date, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                CoreAPI::entityFactory('base.custom_dimension')->getTableName()),
                [$id, $property, 'event', $key, 'cd_' . $key, 'string', 64, ucfirst($key), time(), $state]);
        };

        $insert('7779300000000101', self::A, 'plan', CustomDimension::STATE_APPLIED);
        $insert('7779300000000102', self::A, 'tier', CustomDimension::STATE_PENDING);
        $insert('7779300000000103', self::B, 'seat', CustomDimension::STATE_APPLIED);

        $site = CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => 77793000000000010,
            'site_id'     => self::SITE_A,
            'property_id' => self::A,
            'name'        => 'Dimension registry fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        DimensionRegistry::forget();
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
        DimensionRegistry::forget();
    }

    private static function dropFixture(): void
    {
        $db = CoreAPI::dbSingleton();

        $db->query(sprintf('DELETE FROM %s WHERE property_id IN (?, ?)',
            CoreAPI::entityFactory('base.custom_dimension')->getTableName()), [self::A, self::B]);

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            CoreAPI::entityFactory('base.site')->getTableName(), $db->prepare(self::SITE_A)));
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads custom dimension registrations.');
        }

        $user = CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->reports as $id) {
            CustomReports::delete($id);
        }

        $this->reports = [];
    }

    /** A widget grouped by one dimension. */
    private function definition(string $dimension): array
    {
        return [
            'title'   => 'Dimension registry fixture',
            'widgets' => [[
                'type'      => 'grid',
                'id'        => 'rows',
                'container' => 'rows',
                'title'     => 'Rows',
                'query'     => ['metrics' => 'pageViews', 'dimensions' => $dimension, 'sort' => 'pageViews-'],
            ]],
        ];
    }

    /** @return string[] names a picker offers */
    private static function names(array $choices): array
    {
        return array_column($choices, 'name');
    }

    // ---- the seam ---------------------------------------------------------

    /** A Property's applied custom dimensions are its alone; a pending one is nobody's. */
    public function testAPropertysCustomDimensionsAreItsAlone(): void
    {
        $a = DimensionRegistry::forProperty(self::A);
        $b = DimensionRegistry::forProperty(self::B);

        $this->assertTrue($a->has('cd_plan'));
        $this->assertFalse($a->has('cd_seat'), "another Property's");
        $this->assertFalse($a->has('cd_tier'), 'pending');
        $this->assertTrue($b->has('cd_seat'));
        $this->assertFalse($b->has('cd_plan'));

        $this->assertSame(['cd_plan'], array_column($a->custom(), 'name'));
        $this->assertSame('base.event', $a->denormalized('cd_plan', 'base.event')['entity'] ?? null);
        $this->assertNull($a->denormalized('cd_plan', 'base.session'));
        $this->assertArrayHasKey('cd_plan', $a->onEntity('base.event'));
        $this->assertTrue($a->has('pagePath'), "the modules' dimensions are every Property's");

        $none = DimensionRegistry::forProperty(null);

        $this->assertFalse($none->has('cd_plan'));
        $this->assertFalse($none->has('cd_seat'));
        $this->assertSame([], $none->custom());
    }

    /**
     * A then B in one process: B's answer has none of A's, and nothing is left
     * in the process-wide registry for a later request to find.
     */
    public function testTwoPropertiesInOneProcessDoNotShare(): void
    {
        $this->assertArrayHasKey('cd_plan', CoreAPI::getAllDimensions(null, self::A));

        $b = CoreAPI::getAllDimensions(null, self::B);

        $this->assertArrayNotHasKey('cd_plan', $b);
        $this->assertArrayHasKey('cd_seat', $b);

        $this->assertArrayNotHasKey('cd_plan', CoreAPI::getAllDimensions());
        $this->assertArrayNotHasKey('cd_seat', CoreAPI::getAllDimensions());

        $service = CoreAPI::serviceSingleton();

        $this->assertSame([], preg_grep('/^cd_/', array_keys($service->denormalizedDimensions)),
            'no custom dimension is in the modules\' registry');
        $this->assertSame([], preg_grep('/^cd_/', array_keys($service->dimensions)));
    }

    // ---- the query engine -------------------------------------------------

    /** The result set manager reads its own Property's, by propertyId or by siteId. */
    public function testTheQueryEngineReadsItsOwnPropertys(): void
    {
        $a = new ResultSetManager;
        $a->setPropertyId(self::A);

        $this->assertTrue($a->isDimension('cd_plan'));
        $this->assertFalse($a->isDimension('cd_seat'));
        $this->assertContains(['name' => 'cd_plan', 'label' => 'Plan'],
            (array) ($a->getAllRelatedDimensions(CoreAPI::entityFactory('base.event'))['custom'] ?? []),
            'the grid offers it under custom');

        $bySite = new ResultSetManager;
        $bySite->setSiteId(self::SITE_A);

        $this->assertTrue($bySite->isDimension('cd_plan'), 'a siteId names its Property');

        $b = new ResultSetManager;
        $b->setPropertyId(self::B);

        $this->assertFalse($b->isDimension('cd_plan'));

        $custom = array_column(
            (array) ($b->getAllRelatedDimensions(CoreAPI::entityFactory('base.event'))['custom'] ?? []), 'name');

        $this->assertContains('cd_seat', $custom);
        $this->assertNotContains('cd_plan', $custom, "the grid does not offer another Property's");

        $b->setConstraints($b->constraintsStringToArray('cd_plan==gold'));

        $this->assertTrue($b->hasRequestErrors(), "a constraint on another Property's is refused");

        $a->setConstraints($a->constraintsStringToArray('cd_plan==gold'));

        $this->assertFalse($a->hasRequestErrors());
        $this->assertSame('gold', $a->getConstraint('cd_plan')['value'] ?? null);
    }

    /** The REST route checks the request's names against its Property before it sets the filter. */
    public function testTheRestRouteChecksNamesAgainstTheRequestsProperty(): void
    {
        $rest = function (array $params) {
            return (new \OWA\Module\Base\Controller\ReportsRest($params + ['metrics' => 'pageViews']))
                ->resultSetManager();
        };

        $a = $rest(['siteId' => self::SITE_A, 'dimensions' => 'cd_plan',
            'constraints' => 'cd_plan==gold', 'segment' => 'cd_plan==gold']);

        $this->assertSame([], $a->request_errors);
        $this->assertSame('gold', $a->getConstraint('cd_plan')['value'] ?? null);
        $this->assertSame(['cd_plan'], $a->getDimensionNamesFromSegment());

        $b = $rest(['propertyId' => self::B, 'constraints' => 'cd_plan==gold']);

        $this->assertNotSame([], $b->request_errors, "another Property's, as a constraint");

        $b = $rest(['propertyId' => self::B, 'segment' => 'cd_plan==gold']);

        $this->assertNotSame([], $b->request_errors, "another Property's, as a segment");
    }

    /**
     * The request's own siteId constraint does not replace the siteId param,
     * and does not decide whose dimensions the request may name.
     */
    public function testASiteIdConstraintDoesNotOverrideTheSiteParam(): void
    {
        $rsm = (new \OWA\Module\Base\Controller\ReportsRest([
            'metrics'     => 'pageViews',
            'siteId'      => self::SITE_A,
            'constraints' => 'siteId==some-other-site,cd_plan==gold',
        ]))->resultSetManager();

        $this->assertSame([], $rsm->request_errors);
        $this->assertSame(self::SITE_A, $rsm->getConstraint('siteId')['value'] ?? null);
        $this->assertSame(self::A, $rsm->dimensionRegistry()->propertyId());
    }

    // ---- the custom report editor and its validation ----------------------

    public function testTheReportEditorsChoicesAreThePropertys(): void
    {
        $edit = \OWA\Module\Base\Controller\CustomReportEdit::class;

        $this->assertContains('cd_plan', self::names($edit::dimensionChoices(self::A)));
        $this->assertNotContains('cd_seat', self::names($edit::dimensionChoices(self::A)));
        $this->assertNotContains('cd_plan', self::names($edit::dimensionChoices(self::B)));
        $this->assertNotContains('cd_plan', self::names($edit::dimensionChoices(null)));

        $this->assertSame(['base.event'], $edit::dimensionEntities(self::A)['cd_plan'] ?? null);
        $this->assertArrayNotHasKey('cd_plan', $edit::dimensionEntities(self::B));
    }

    public function testAReportIsValidatedAgainstThePropertysDimensions(): void
    {
        $definition = $this->definition('cd_plan');

        $this->assertSame('', CustomReports::validate($definition, self::A));
        $this->assertStringContainsString('cd_plan', CustomReports::validate($definition, self::B));
        $this->assertStringContainsString('cd_plan', CustomReports::validate($definition));

        $this->assertSame('', CustomReports::constraintsProblem('cd_plan==gold', 'The funnel', self::A));
        $this->assertStringContainsString('cd_plan',
            CustomReports::constraintsProblem('cd_plan==gold', 'The funnel', self::B));

        $this->assertSame(['Plan is gold'], CustomReports::constraintsInWords('cd_plan==gold', self::A));
        $this->assertSame(['cd_plan is gold'], CustomReports::constraintsInWords('cd_plan==gold', self::B));
    }

    /**
     * The builder opened with no propertyId on the URL resolves the Property
     * first, so it offers its custom dimensions, and a widget grouped by one
     * comes back with it among the choices.
     */
    public function testTheEditorOpenedWithoutAPropertyIdOffersThem(): void
    {
        $saved = CustomReports::save([
            'name'       => 'Dimension registry fixture',
            'definition' => $this->definition('cd_plan'),
        ], self::AUTHOR, self::A);

        $this->assertTrue($saved['ok'], (string) $saved['error']);
        $this->reports[] = $saved['id'];

        $refused = CustomReports::save([
            'name'       => 'Dimension registry fixture',
            'definition' => $this->definition('cd_plan'),
        ], self::AUTHOR, self::B);

        $this->assertFalse($refused['ok'], "saved on a Property that has no cd_plan");

        $data = (array) (new \OWA\Module\Base\Controller\CustomReportEdit([
            'do'             => 'base.customReportEdit',
            'customReportId' => $saved['id'],
            'siteId'         => self::SITE_A,
        ]))->doAction();

        $this->assertSame(self::A, (string) ($data['propertyId'] ?? ''));
        $this->assertContains('cd_plan', self::names((array) ($data['dimension_choices'] ?? [])),
            'offered, so the widget keeps it selected');
        $this->assertSame('cd_plan',
            $data['custom_report_definition']['widgets'][0]['query']['dimensions'] ?? null);
    }

    /**
     * A custom report grouped by one is drawn for its Property, though the
     * dispatcher checks it before any pre() has resolved one, and its pencil
     * opens the editor on that Property.
     */
    public function testAReportUsingOneIsDrawnAndItsEditLinkNamesTheProperty(): void
    {
        $saved = CustomReports::save([
            'name'       => 'Dimension registry fixture',
            'definition' => $this->definition('cd_plan'),
        ], self::AUTHOR, self::A);

        $this->assertTrue($saved['ok'], (string) $saved['error']);
        $this->reports[] = $saved['id'];

        $data = (array) (new \OWA\Module\Base\Controller\Report([
            'reportId' => 'custom-' . $saved['id'],
            'siteId'   => self::SITE_A,
            'period'   => 'last_thirty_days',
        ]))->doAction();

        $this->assertNotEmpty($data['subview'] ?? '', 'drawn, not refused for naming cd_plan');

        $edit = array_values(array_filter((array) ($data['title_actions'] ?? []),
            static fn ($action) => ($action['label'] ?? '') === 'Edit report'));

        $this->assertCount(1, $edit);
        $this->assertStringContainsString('customReportEdit', (string) $edit[0]['url']);
        $this->assertStringContainsString('propertyId=' . self::A, (string) $edit[0]['url']);
    }

    /** The roster's + New, followed without JavaScript, opens the builder on the roster's Property. */
    public function testTheRostersNewLinkNamesTheProperty(): void
    {
        $data = (array) (new \OWA\Module\Base\Controller\CustomReports([
            'do'     => 'base.customReports',
            'siteId' => self::SITE_A,
        ]))->doAction();

        $new = (string) ($data['title_actions'][0]['url'] ?? '');

        $this->assertStringContainsString('customReportEdit', $new);
        $this->assertStringContainsString('propertyId=' . self::A, $new);
    }

    // ---- funnels ----------------------------------------------------------

    public function testTheFunnelEditorOffersThePropertys(): void
    {
        $data = (array) (new \OWA\Module\Base\Controller\VisualizationEdit([
            'do'     => 'base.visualizationEdit',
            'siteId' => self::SITE_A,
        ]))->doAction();

        $this->assertContains('cd_plan', self::names((array) ($data['dimensionChoices'] ?? [])),
            'the funnel filter offers it');

        $vocabulary = (array) ($data['conditionVocabulary'] ?? []);

        $this->assertNotEmpty($vocabulary);

        foreach ($vocabulary as $event => $properties) {
            $this->assertContains('cd_plan', array_column($properties, 'name'), "a $event step can test it");
            $this->assertNotContains('cd_seat', array_column($properties, 'name'));
        }
    }

    public function testAFunnelStepTestsThePropertysOnly(): void
    {
        $row = ['event' => 'click', 'conditions' => [
            ['property' => 'cd_plan', 'operator' => 'exact', 'value' => 'gold'],
        ]];

        $this->assertSame(['cd_plan' => 'Plan'], \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(self::A));
        $this->assertSame([], \OWA\Module\Base\Classes\FunnelStep::problems($row, 1,
            \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(self::A)));
        $this->assertNotSame([], \OWA\Module\Base\Classes\FunnelStep::problems($row, 1,
            \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(self::B)));
    }

    // ---- segments ---------------------------------------------------------

    /**
     * A segment is checked against its own Property's. B has no cd_plan, so the
     * segment is refused by name rather than answering for everybody. A's side
     * needs a cube; CustomDimensionBuildTest has it.
     */
    public function testASegmentOnAnotherPropertysDimensionIsRefused(): void
    {
        $segment = new \OWA\Module\Base\Classes\ReportSegment(self::B, 'today', null, null, 'cd_plan==gold');

        $this->assertSame([], $segment->subjects('visitor'));
        $this->assertStringContainsString('cd_plan', (string) $segment->getError());
    }
}
