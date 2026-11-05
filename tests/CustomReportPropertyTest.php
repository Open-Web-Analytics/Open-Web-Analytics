<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\CoreAPI;
use OWA\Module\Base\Classes\CustomReportFavorites as Favorites;
use OWA\Module\Base\Classes\CustomReports;

/**
 * A custom report or funnel belongs to the Property it was made on: it is
 * stored there, listed there, and opened, edited, saved, deleted and starred
 * there alone. One of another Property is answered as one that does not exist.
 *
 * Two Properties, P (with a Profile, SITE_P) and Q, as ids only: nothing here
 * draws cube data.
 */
final class CustomReportPropertyTest extends TestCase
{
    const P      = '7781000000000001';
    const Q      = '7781000000000002';
    const SITE_P = 'owa-custom-report-property-site-p';
    const AUTHOR = 'custom-report-property-author@example.test';

    /** @var string[] custom report ids to delete */
    private $reports = [];

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $site = CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => 77810000000000010,
            'site_id'     => self::SITE_P,
            'property_id' => self::P,
            'name'        => 'Custom report Property fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (owa_test_db_available()) {
            self::dropFixture();
        }
    }

    private static function dropFixture(): void
    {
        $db = CoreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            CoreAPI::entityFactory('base.site')->getTableName(), $db->prepare(self::SITE_P)));

        foreach ((array) $db->get_results('SELECT id FROM owa_custom_report WHERE user_id = ?', [self::AUTHOR]) as $row) {
            CustomReports::delete(((array) $row)['id']);
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('stores custom reports');
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

    private function definition(string $title = 'Property fixture'): array
    {
        return [
            'title'   => $title,
            'widgets' => [[
                'type'      => 'grid',
                'id'        => 'pages',
                'container' => 'pages',
                'title'     => 'Pages',
                'query'     => ['metrics' => 'pageViews', 'dimensions' => 'pagePath', 'sort' => 'pageViews-'],
            ]],
        ];
    }

    /** A report saved on one Property, remembered for cleanup. */
    private function store(string $property, string $name = 'Property fixture', array $fields = []): string
    {
        $saved = CustomReports::save(['name' => $name, 'definition' => $this->definition($name)] + $fields,
            self::AUTHOR, $property);

        $this->assertTrue($saved['ok'], (string) $saved['error']);
        $this->reports[] = $saved['id'];

        return $saved['id'];
    }

    /** A funnel written as VisualizationSave writes one, on one Property. */
    private function storeFunnel(string $property): string
    {
        $save = new \OWA\Module\Base\Controller\VisualizationSave([
            'name'              => 'Property fixture funnel',
            'propertyId'        => $property,
            'visualizationType' => 'funnel',
            'stepName'          => ['Pricing'],
            'stepEvent'         => ['page_view'],
            'stepMatch'         => ['all'],
            'stepConditionProperty' => [['page_path']],
            'stepConditionOperator' => [['exact']],
            'stepConditionValue'    => [['/pricing']],
        ]);
        $save->action();

        $id = substr((string) ($this->data($save)['reportId'] ?? ''), strlen(\OWA\Module\Base\Controller\Report::CUSTOM_PREFIX));

        $this->assertNotSame('', $id, 'the funnel was not stored');
        $this->reports[] = $id;

        return $id;
    }

    private function data($controller): array
    {
        $property = new \ReflectionProperty(\OWA\Core\Controller::class, 'data');
        $property->setAccessible(true);

        return (array) $property->getValue($controller);
    }

    private function row(string $id): array
    {
        return (array) CoreAPI::dbSingleton()->get_row('SELECT * FROM owa_custom_report WHERE id = ?', [$id]);
    }

    // ---- create ------------------------------------------------------------

    public function testASavedReportIsStoredOnItsProperty(): void
    {
        $id = $this->store(self::P);

        $this->assertSame(self::P, (string) $this->row($id)['property_id']);
        $this->assertSame(self::P, CustomReports::propertyOf(CustomReports::load($id)));
    }

    public function testANewReportNeedsAProperty(): void
    {
        $saved = CustomReports::save(['name' => 'Nowhere', 'definition' => $this->definition('Nowhere')], self::AUTHOR, '');

        $this->assertFalse($saved['ok']);
        $this->assertSame('No Property was named to save this to.', $saved['error']);
    }

    public function testTheBuildersSaveStoresThePropertyItPosts(): void
    {
        $save = new \OWA\Module\Base\Controller\CustomReportSave([
            'customReportName'       => 'Saved by the builder',
            'customReportDefinition' => json_encode($this->definition('Saved by the builder')),
            'propertyId'             => self::P,
        ]);
        $save->action();

        $id = substr((string) ($this->data($save)['reportId'] ?? ''), strlen(\OWA\Module\Base\Controller\Report::CUSTOM_PREFIX));
        $this->reports[] = $id;

        $this->assertSame(self::P, (string) $this->row($id)['property_id']);
        $this->assertSame(self::P, (string) ($this->data($save)['propertyId'] ?? ''), 'the redirect lands on it');
    }

    public function testASavedFunnelIsStoredOnItsProperty(): void
    {
        $id = $this->storeFunnel(self::P);

        $this->assertSame(self::P, (string) $this->row($id)['property_id']);
        $this->assertSame('visualization', $this->row($id)['report_type']);
    }

    // ---- listing ----------------------------------------------------------

    public function testTheRosterListsItsPropertysReportsOnly(): void
    {
        $p = $this->store(self::P, 'On P');
        $q = $this->store(self::Q, 'On Q');

        $onP = array_column(CustomReports::roster(self::AUTHOR, true, '', null, null, null, false, false, self::P), 'id');

        $this->assertContains($p, $onP);
        $this->assertNotContains($q, $onP);
        $this->assertSame([], CustomReports::roster(self::AUTHOR, true, '', null, null, null, false, false, ''),
            'no Property lists none');
    }

    public function testTheRosterScreenListsThePropertyItIsOn(): void
    {
        $p = $this->store(self::P, 'Listed on P');
        $q = $this->store(self::Q, 'Listed on Q');

        $ids = function (array $params): array {
            $data = (array) (new \OWA\Module\Base\Controller\CustomReports(['do' => 'base.customReports'] + $params))->doAction();

            return array_column((array) ($data['custom_reports'] ?? []), 'id');
        };

        $this->assertContains($p, $ids(['siteId' => self::SITE_P]));
        $this->assertNotContains($q, $ids(['siteId' => self::SITE_P]));
        $this->assertContains($q, $ids(['propertyId' => self::Q]));
        $this->assertNotContains($p, $ids(['propertyId' => self::Q]));
    }

    public function testTheNavFavoritesAreThePropertysOnly(): void
    {
        $p = $this->store(self::P, 'Starred on P');
        $q = $this->store(self::Q, 'Starred on Q');

        Favorites::toggle($p, self::AUTHOR);
        Favorites::toggle($q, self::AUTHOR);

        $ids = array_column(CustomReports::favorites(self::AUTHOR, true, self::P), 'id');

        $this->assertContains($p, $ids);
        $this->assertNotContains($q, $ids);
        $this->assertSame([], CustomReports::favorites(self::AUTHOR, true, ''), 'a nav of no Property has none');
    }

    // ---- by id ------------------------------------------------------------

    public function testAReportOfAnotherPropertyIsNotFound(): void
    {
        $id = $this->store(self::P);

        $this->assertNotNull(CustomReports::loadOf($id, self::P));
        $this->assertNull(CustomReports::loadOf($id, self::Q));
        $this->assertNull(CustomReports::loadOf($id, ''));
    }

    /** A request naming no Property is on the report's own; one naming a Profile, on its Property. */
    public function testARequestIsOnThePropertyItNamesElseTheReportsOwn(): void
    {
        $id = $this->store(self::P);

        $this->assertSame(self::P, CustomReports::requestProperty($id, null, null));
        $this->assertSame(self::Q, CustomReports::requestProperty($id, self::Q, null));
        $this->assertSame(self::P, CustomReports::requestProperty($id, '', self::SITE_P));
        $this->assertSame('', CustomReports::requestProperty('', null, null));
    }

    public function testTheReportPageRefusesAnotherPropertysReport(): void
    {
        $id = $this->store(self::P);

        ob_start();
        $data = (array) (new \OWA\Module\Base\Controller\Report([
            'reportId'   => 'custom-' . $id,
            'propertyId' => self::Q,
            'period'     => 'last_thirty_days',
        ]))->doAction();
        ob_end_clean();

        $this->assertEmpty($data['subview'] ?? '', 'not found on Q');
        $this->assertArrayNotHasKey('custom_report_id', $data);
    }

    /**
     * A link to a report keeps working: its id is unchanged, and one naming no
     * Property, or a Profile of the report's, opens it on its own.
     */
    public function testALinkToAReportStillOpensIt(): void
    {
        $id = $this->store(self::P, 'Linked');

        foreach ([[], ['siteId' => self::SITE_P], ['propertyId' => self::P]] as $named) {

            $data = (array) (new \OWA\Module\Base\Controller\Report([
                'reportId' => 'custom-' . $id,
                'period'   => 'last_thirty_days',
            ] + $named))->doAction();

            $this->assertNotEmpty($data['subview'] ?? '', 'drawn for ' . json_encode($named));
            $this->assertSame($id, (string) ($data['custom_report_id'] ?? ''));
            $this->assertSame(self::P, (string) ($data['currentPropertyId'] ?? ''));
        }
    }

    public function testTheEditorRefusesAnotherPropertysReport(): void
    {
        $id = $this->store(self::P);

        $data = (array) (new \OWA\Module\Base\Controller\CustomReportEdit([
            'do'             => 'base.customReportEdit',
            'customReportId' => $id,
            'propertyId'     => self::Q,
        ]))->doAction();

        $this->assertNull($data['custom_report'] ?? null);
        $this->assertSame('', (string) ($data['custom_report_id'] ?? ''));
        $this->assertSame('That report no longer exists.', $data['custom_report_error'] ?? '');

        $own = (array) (new \OWA\Module\Base\Controller\CustomReportEdit([
            'do'             => 'base.customReportEdit',
            'customReportId' => $id,
        ]))->doAction();

        $this->assertSame($id, (string) ($own['custom_report_id'] ?? ''), 'no Property named: its own');
        $this->assertSame(self::P, (string) ($own['propertyId'] ?? ''));
    }

    public function testAnEditFromAnotherPropertyIsRefusedAndChangesNothing(): void
    {
        $id = $this->store(self::P, 'Untouched');

        $refused = CustomReports::save(['id' => $id, 'name' => 'Moved', 'definition' => $this->definition('Moved')],
            self::AUTHOR, self::Q);

        $this->assertFalse($refused['ok']);
        $this->assertSame('That report does not exist.', $refused['error']);

        $save = new \OWA\Module\Base\Controller\CustomReportSave([
            'customReportId'         => $id,
            'customReportName'       => 'Moved',
            'customReportDefinition' => json_encode($this->definition('Moved')),
            'propertyId'             => self::Q,
        ]);

        // Refused back to the builder, which is drawn.
        ob_start();
        $save->action();
        ob_end_clean();

        $row = $this->row($id);

        $this->assertSame('Untouched', $row['name']);
        $this->assertSame(self::P, (string) $row['property_id']);

        $this->assertTrue(CustomReports::save(['id' => $id, 'name' => 'Renamed', 'definition' => $this->definition('Renamed')],
            self::AUTHOR, self::P)['ok'], 'on its own Property');
    }

    public function testADeleteFromAnotherPropertyIsRefused(): void
    {
        $id = $this->store(self::P);

        $delete = new \OWA\Module\Base\Controller\CustomReportDelete(['customReportId' => $id, 'propertyId' => self::Q]);
        $delete->action();

        $this->assertSame(2513, $this->data($delete)['status_code'] ?? null);
        $this->assertNotSame([], $this->row($id), 'still there');

        $delete = new \OWA\Module\Base\Controller\CustomReportDelete(['customReportId' => $id]);
        $delete->action();

        $this->assertSame(2512, $this->data($delete)['status_code'] ?? null, 'no Property named: its own');
        $this->assertSame(self::P, (string) ($this->data($delete)['propertyId'] ?? ''), 'back to its roster');
        $this->assertSame([], $this->row($id));
    }

    public function testStarringFromAnotherPropertyIsRefused(): void
    {
        $id = $this->store(self::P);

        // The controller stars for the signed-in reader.
        $user = CoreAPI::getCurrentUser();
        $was  = $user->getUserData('user_id');
        $user->setUserData('user_id', self::AUTHOR);

        try {
            (new \OWA\Module\Base\Controller\CustomReportMarkFavorite(['customReportId' => $id, 'propertyId' => self::Q]))->action();

            $this->assertFalse(Favorites::isFavorite($id, self::AUTHOR));

            (new \OWA\Module\Base\Controller\CustomReportMarkFavorite(['customReportId' => $id, 'propertyId' => self::P]))->action();

            $this->assertTrue(Favorites::isFavorite($id, self::AUTHOR), 'starred on its own Property');
        } finally {
            $user->setUserData('user_id', $was);
        }
    }

    /** The funnel refusals say what happened, not that something could not be deleted. */
    public function testTheFunnelRefusalsHaveMessagesOfTheirOwn(): void
    {
        $_owa_messages = [];
        require __DIR__ . '/../conf/messages.php';

        $this->assertSame('That funnel does not exist.', $_owa_messages[2514]['message'] ?? null);
        $this->assertSame('That funnel belongs to another user.', $_owa_messages[2515]['message'] ?? null);
    }

    public function testAFunnelOfAnotherPropertyIsNotEditedSavedOrDrawn(): void
    {
        $id = $this->storeFunnel(self::P);

        $edit = (array) (new \OWA\Module\Base\Controller\VisualizationEdit([
            'do'              => 'base.visualizationEdit',
            'visualizationId' => $id,
            'propertyId'      => self::Q,
        ]))->doAction();

        $this->assertSame(2514, $edit['status_code'] ?? null, 'That funnel does not exist.');
        $this->assertSame('base.customReports', $edit['do'] ?? '');

        $save = new \OWA\Module\Base\Controller\VisualizationSave([
            'visualizationId' => $id,
            'name'            => 'Moved funnel',
            'propertyId'      => self::Q,
        ]);
        $save->action();

        $this->assertSame(2514, $this->data($save)['status_code'] ?? null);
        $this->assertSame('Property fixture funnel', $this->row($id)['name']);
        $this->assertSame(self::P, (string) $this->row($id)['property_id']);

        $funnel = static function (array $params) {
            return new class($params) extends \OWA\Module\Base\Controller\VisualizationFunnel {
                public function stored()
                {
                    return $this->visualization();
                }
            };
        };

        $this->assertNull($funnel(['reportId' => 'custom-' . $id, 'propertyId' => self::Q])->stored());
        $this->assertSame($id, (string) ($funnel(['reportId' => 'custom-' . $id])->stored()['id'] ?? ''),
            'no Property named: its own');
    }
}
