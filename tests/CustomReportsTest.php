<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\CustomReports;

/**
 * User-authored report definitions: what may be stored, and who may see them.
 *
 * A custom report is a report DEFINITION -- the same JSON a shipped report
 * holds -- rendered by the same Core\ConfiguredReport. That is only safe
 * because the format was narrowed for it during the conversion: the renderer is
 * fixed rather than named by the definition, formatters are selected by name
 * and never carried as code, and excludeColumns is a list of names rather than
 * a fragment of script.
 *
 * So the interesting question is not "does it render" but "what does it refuse
 * to store", and most of this file is about that. The names inside a definition
 * reach the query builder, so a name that does not resolve through the registry
 * is the reader choosing what appears in SQL -- the same invariant the REST
 * endpoint has.
 */
final class CustomReportsTest extends TestCase
{
    private const AUTHOR = 'custom-report-author@example.test';
    private const OTHER  = 'custom-report-other@example.test';

    /** @var string[] ids to clean up */
    private static $created = array();

    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        foreach (self::$created as $id) {
            CustomReports::delete($id);
        }

        self::$created = array();
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('storing a custom report needs a database');
        }
    }

    /** A definition that is valid, so a test can break exactly one thing about it. */
    private function definition(): array
    {
        return array(
            'title'   => 'A Custom Report',
            'metrics' => 'visits,uniqueVisitors',
            'widgets' => array(
                array(
                    'type'        => 'trend',
                    'id'          => 'trend',
                    'container'   => 'trend-chart',
                    'chartMetric' => 'visits',
                    'query'       => array('dimensions' => 'date', 'sort' => 'date'),
                ),
                array(
                    'type'      => 'grid',
                    'id'        => 'pages',
                    'container' => 'pages',
                    'title'     => 'Pages',
                    'query'     => array(
                        'metrics'        => 'pageViews',
                        'dimensions'     => 'pagePath',
                        'sort'           => 'pageViews-',
                        'resultsPerPage' => 10,
                    ),
                ),
            ),
        );
    }

    /** Store one and remember it for cleanup. */
    private function store(array $overrides = array(), string $author = self::AUTHOR): array
    {
        $result = CustomReports::save(array(
            'name'       => $overrides['name'] ?? 'A Custom Report',
            'definition' => $overrides['definition'] ?? $this->definition(),
        ) + $overrides, $author);

        if (!empty($result['id'])) {
            self::$created[] = $result['id'];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // What may be stored
    // ------------------------------------------------------------------

    public function testAWellFormedDefinitionIsAccepted(): void
    {
        $this->assertSame('', CustomReports::validate($this->definition()));
    }

    /**
     * The registry check, which is the one that matters.
     *
     * Every name in a definition reaches the query builder. A name that does
     * not resolve is the AUTHOR choosing what appears in SQL, which is the
     * invariant the REST endpoint has and the reason this is checked at save
     * time as well as at query time.
     *
     * @dataProvider unresolvableNameProvider
     */
    public function testANameThatDoesNotResolveIsRefused(callable $break, string $expected): void
    {
        $definition = $this->definition();

        $break($definition);

        $error = CustomReports::validate($definition);

        $this->assertNotSame('', $error, 'an unresolvable name must be refused');
        $this->assertStringContainsString($expected, $error,
            'the message must name what could not be resolved, or it cannot be fixed');
    }

    public static function unresolvableNameProvider(): array
    {
        return array(
            'unknown dimension' => array(
                function (array &$d) { $d['widgets'][1]['query']['dimensions'] = 'notADimension'; },
                'notADimension',
            ),
            'unknown metric' => array(
                function (array &$d) { $d['widgets'][1]['query']['metrics'] = 'notAMetric'; },
                'notAMetric',
            ),
            'unknown sort' => array(
                function (array &$d) { $d['widgets'][1]['query']['sort'] = 'notASort-'; },
                'notASort',
            ),
            'unknown name in the report metric set' => array(
                function (array &$d) { $d['metrics'] = 'visits,notAMetric'; },
                'notAMetric',
            ),
            'one bad name among good ones' => array(
                function (array &$d) { $d['widgets'][1]['query']['metrics'] = 'pageViews,notAMetric'; },
                'notAMetric',
            ),
        );
    }

    /**
     * A sort carries its direction as a trailing '-', which is not part of the
     * name. If that were not stripped, every descending sort would be refused.
     */
    public function testADescendingSortResolvesOnItsNameAlone(): void
    {
        $definition = $this->definition();

        $definition['widgets'][1]['query']['sort'] = 'pageViews-';
        $this->assertSame('', CustomReports::validate($definition));

        $definition['widgets'][1]['query']['sort'] = 'pageViews';
        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * Widget types are an allowlist, not a passthrough.
     *
     * The renderer can draw more types than the builder offers -- report-links,
     * heatmap-link, the badge widgets -- but each exists to serve one shipped
     * report and needs inputs the builder has no way to ask for.
     */
    public function testOnlyTheBuildableWidgetTypesAreAccepted(): void
    {
        foreach (array_keys(CustomReports::WIDGET_TYPES) as $type) {

            $definition = $this->definition();
            $definition['widgets'] = array(array(
                'type'        => $type,
                'id'          => 'w',
                'container'   => 'w',
                'chartMetric' => 'visits',
                'query'       => array('metrics' => 'visits', 'dimensions' => 'date'),
            ));

            $this->assertSame('', CustomReports::validate($definition),
                "$type is offered by the builder and must be accepted");
        }

        $definition = $this->definition();
        $definition['widgets'][0]['type'] = 'heatmap-link';

        $this->assertStringContainsString('heatmap-link', CustomReports::validate($definition));
    }

    /**
     * TEN, written out.
     *
     * Not array_fill(0, CustomReports::MAX_WIDGETS, ...): a fixture built from
     * the constant under test follows it anywhere it moves, so raising the cap
     * to 9999 would leave this passing. The number is the requirement, so the
     * number is what the test says.
     */
    public function testAtMostTenWidgets(): void
    {
        $this->assertSame(10, CustomReports::MAX_WIDGETS,
            'the limit is ten; change the requirement before changing this');

        $definition = $this->definition();
        $widget     = $definition['widgets'][0];

        $definition['widgets'] = array_fill(0, 10, $widget);
        $this->assertSame('', CustomReports::validate($definition), 'ten is allowed');

        $definition['widgets'] = array_fill(0, 11, $widget);
        $this->assertStringContainsString('at most', CustomReports::validate($definition),
            'eleven is refused');
    }

    public function testAReportNeedsAtLeastOneWidget(): void
    {
        $definition = $this->definition();
        $definition['widgets'] = array();

        $this->assertNotSame('', CustomReports::validate($definition));
    }

    /**
     * The narrowings that make user authorship safe are still enforced, by the
     * renderer's own validator which this delegates to. Asserted here because
     * they are the reason this feature can exist at all -- a later relaxation
     * of KNOWN_KEYS would show up as this test failing.
     */
    public function testADefinitionCannotNameItsOwnRenderer(): void
    {
        $definition = $this->definition();
        $definition['subview'] = 'base.someOtherView';

        $error = CustomReports::validate($definition);

        $this->assertNotSame('', $error,
            'a definition that could name a view could point at any view in the tree');
        $this->assertStringContainsString('subview', $error);
    }

    public function testAnUnknownTopLevelKeyIsRefused(): void
    {
        $definition = $this->definition();
        $definition['setings'] = array('foo' => 'bar');

        $this->assertStringContainsString('setings', CustomReports::validate($definition));
    }

    // ------------------------------------------------------------------
    // Storing
    // ------------------------------------------------------------------

    public function testARoundTripKeepsTheDefinition(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Round Trip'));

        $this->assertTrue($saved['ok'], $saved['error']);

        $loaded = CustomReports::load($saved['id']);

        $this->assertNotNull($loaded);
        $this->assertSame('Round Trip', $loaded['name']);
        $this->assertSame(self::AUTHOR, $loaded['user_id']);
        $this->assertCount(2, $loaded['definition']['widgets']);

        // Stored strings are entity-encoded on write, so a definition that was
        // not decoded on the way back out would not parse at all.
        $this->assertSame('pagePath', $loaded['definition']['widgets'][1]['query']['dimensions']);
    }

    /**
     * The name and the report heading are ONE field.
     *
     * Two fields that must agree is a way for the roster and the report itself
     * to disagree, so the title is set from the name rather than asked for
     * twice.
     */
    public function testTheNameBecomesTheReportTitle(): void
    {
        $this->requireDb();

        $definition = $this->definition();
        $definition['title'] = 'something else entirely';

        $saved  = $this->store(array('name' => 'The Real Name', 'definition' => $definition));
        $loaded = CustomReports::load($saved['id']);

        $this->assertSame('The Real Name', $loaded['definition']['title']);
    }

    public function testAnInvalidDefinitionIsNotStored(): void
    {
        $this->requireDb();

        $definition = $this->definition();
        $definition['widgets'][1]['query']['dimensions'] = 'notADimension';

        $before = count(CustomReports::roster('', true));
        $saved  = $this->store(array('name' => 'Should Not Store', 'definition' => $definition));

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('notADimension', $saved['error']);
        $this->assertSame($before, count(CustomReports::roster('', true)),
            'a refused report must not leave a row behind');
    }

    public function testAReportNeedsAName(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => '   '));

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('name', $saved['error']);
    }

    public function testDefinitionsMayArriveAsJsonText(): void
    {
        $this->requireDb();

        $saved = $this->store(array(
            'name'       => 'From JSON',
            'definition' => json_encode($this->definition()),
        ));

        $this->assertTrue($saved['ok'], $saved['error']);
        $this->assertCount(2, CustomReports::load($saved['id'])['definition']['widgets']);
    }

    public function testMalformedJsonIsRefusedWithItsReason(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Broken', 'definition' => '{not json'));

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('JSON', $saved['error']);
    }

    /** An edit must not silently reassign authorship. */
    public function testEditingDoesNotChangeTheCreator(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Originally Mine'));

        $edited = CustomReports::save(array(
            'id'         => $saved['id'],
            'name'       => 'Edited By An Admin',
            'definition' => $this->definition(),
        ), 'an-admin@example.test');

        $this->assertTrue($edited['ok'], $edited['error']);
        $this->assertSame($saved['id'], $edited['id'], 'an edit is not a new report');

        $loaded = CustomReports::load($saved['id']);

        $this->assertSame('Edited By An Admin', $loaded['name']);
        $this->assertSame(self::AUTHOR, $loaded['user_id'],
            'the creator is a fact about the report, not about who last touched it');
    }

    // ------------------------------------------------------------------
    // Who sees what
    // ------------------------------------------------------------------

    public function testTheRosterShowsAUserOnlyTheirOwnReports(): void
    {
        $this->requireDb();

        $mine   = $this->store(array('name' => 'Mine'), self::AUTHOR);
        $theirs = $this->store(array('name' => 'Theirs'), self::OTHER);

        $ids = array_column(CustomReports::roster(self::AUTHOR), 'id');

        $this->assertContains($mine['id'], $ids);
        $this->assertNotContains($theirs['id'], $ids,
            'a non-admin roster must not list somebody else\'s report');
    }

    public function testAUserWhoMaySeeEverythingSeesBothReports(): void
    {
        $this->requireDb();

        $mine   = $this->store(array('name' => 'Mine For Admin'), self::AUTHOR);
        $theirs = $this->store(array('name' => 'Theirs For Admin'), self::OTHER);

        $ids = array_column(CustomReports::roster(self::AUTHOR, true), 'id');

        $this->assertContains($mine['id'], $ids);
        $this->assertContains($theirs['id'], $ids);
    }

    /** The roster has to say who made each report, which means carrying it. */
    public function testTheRosterCarriesTheNameAndTheCreator(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Named And Attributed'));

        $row = null;

        foreach (CustomReports::roster(self::AUTHOR) as $candidate) {
            if ($candidate['id'] === $saved['id']) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame('Named And Attributed', $row['name']);
        $this->assertSame(self::AUTHOR, $row['user_id']);
    }

    /**
     * Editing is narrower than viewing.
     *
     * @dataProvider editPermissionProvider
     */
    public function testWhoMayEdit(string $user, bool $seesAll, bool $expected): void
    {
        $report = array('id' => '1', 'user_id' => self::AUTHOR);

        $this->assertSame($expected, CustomReports::mayEdit($report, $user, $seesAll));
    }

    public static function editPermissionProvider(): array
    {
        return array(
            'the creator'        => array(self::AUTHOR, false, true),
            'somebody else'      => array(self::OTHER,  false, false),
            'an admin'           => array(self::OTHER,  true,  true),
            'nobody at all'      => array('',           false, false),
        );
    }

    public function testAMissingReportIsNotEditableByAnyone(): void
    {
        $this->assertFalse(CustomReports::mayEdit(null, self::AUTHOR, true));
    }

    public function testLoadingSomethingThatIsNotThereReturnsNull(): void
    {
        $this->requireDb();

        $this->assertNull(CustomReports::load('9100000000000000999'));
        $this->assertNull(CustomReports::load(''));
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * A custom report renders through the ordinary report dispatcher, which is
     * what gives it the same chrome -- site filter and date picker -- as every
     * other report, without this feature supplying either.
     */
    public function testACustomReportRendersWithTheOrdinaryReportChrome(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Renders'));

        $data = (array) (new \OWA\Module\Base\Controller\Report(array(
            'reportId' => 'custom-' . $saved['id'],
            'period'   => 'last_thirty_days',
        )))->doAction();

        $this->assertNotEmpty($data['subview'] ?? '', 'the report did not render');

        foreach (array('sites', 'currentSiteId', 'period', 'dom_id') as $key) {
            $this->assertArrayHasKey($key, $data,
                "a custom report must come with $key like every other report");
        }

        foreach (array('siteId', 'period', 'startDate', 'endDate') as $key) {
            $this->assertArrayHasKey($key, $data['params'],
                "params.$key is what carries the selection into the report's own links");
        }
    }

    /** The URL is the whole address: the same id renders the same report. */
    public function testTheSameUrlRendersTheSameReport(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Shareable'));

        $render = function () use ($saved) {
            return (array) (new \OWA\Module\Base\Controller\Report(array(
                'reportId' => 'custom-' . $saved['id'],
                'period'   => 'last_thirty_days',
            )))->doAction();
        };

        $first  = $render();
        $second = $render();

        $this->assertSame($first['subview'], $second['subview']);
        $this->assertNotEmpty($first['subview']);
    }

    /**
     * A stored definition is re-validated on the way OUT.
     *
     * Not paranoia: the registry changes under a saved report -- a module
     * deactivated since it was written takes its metrics with it -- and a row
     * can be edited by something that is not the builder. Rendering it with the
     * bad widget dropped would show a report quietly missing a section.
     */
    public function testADefinitionThatStoppedBeingValidIsRefusedRatherThanPartlyRendered(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Goes Stale'));

        // Corrupt the stored row directly, which is the situation being modelled.
        $entity = \OWA\Core\CoreAPI::entityFactory('base.custom_report');
        $entity->load($saved['id']);

        $definition = $this->definition();
        $definition['widgets'][1]['query']['dimensions'] = 'aDimensionThatNoLongerExists';

        $entity->set('definition', json_encode($definition));
        $entity->update();

        $data = (array) (new \OWA\Module\Base\Controller\Report(array(
            'reportId' => 'custom-' . $saved['id'],
            'period'   => 'last_thirty_days',
        )))->doAction();

        $this->assertEmpty($data['subview'] ?? '',
            'a report that no longer validates must not render');
    }

    public function testAnUnknownCustomIdIsNotFound(): void
    {
        $this->requireDb();

        $data = (array) (new \OWA\Module\Base\Controller\Report(array(
            'reportId' => 'custom-9100000000000000998',
            'period'   => 'last_thirty_days',
        )))->doAction();

        $this->assertEmpty($data['subview'] ?? '');
    }

    // ------------------------------------------------------------------
    // Who may reach what
    // ------------------------------------------------------------------

    /** Put the current user in a role, for the duration of one assertion. */
    private function asRole(string $role, bool $authenticated = true): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole($role);
        $user->setAuthStatus($authenticated);
    }

    /**
     * Authoring is gated on edit_reports, which is admin-only by default.
     *
     * The point of a separate capability is that an installation can grant it
     * to analysts without granting them everything else an admin has -- so the
     * test that matters is that a role WITHOUT it is refused, not merely that
     * an admin is allowed.
     */
    public function testTheBuilderIsRefusedWithoutEditReports(): void
    {
        $this->requireDb();

        $this->asRole('viewer');

        $refused = (array) (new \OWA\Module\Base\Controller\CustomReportEdit(
            array('do' => 'base.customReportEdit')))->doAction();

        $this->assertArrayNotHasKey('subview', $refused,
            'a role without edit_reports reached the builder');

        // ...and it is allowed with the capability, so the refusal above is
        // authorization rather than a broken screen.
        $this->asRole('admin');

        $allowed = (array) (new \OWA\Module\Base\Controller\CustomReportEdit(
            array('do' => 'base.customReportEdit')))->doAction();

        $this->assertSame('base.customReportEdit', $allowed['subview'] ?? null);
    }

    /**
     * Each screen asks for the capability that matches what it IS.
     *
     * The roster asks for view_site_list -- the capability that means "any
     * signed-in user". Not view_reports, which looks like the obvious choice
     * and is wrong: view_reports sits in capabilitiesThatRequireSiteAccess, so
     * it is only ever satisfied against a particular site, and the roster has
     * no site. Requiring it refused every non-admin with "No access to any
     * site", which the e2e suite caught and no unit test could.
     *
     * ASSERTED AS THE DECLARED CAPABILITY rather than by driving each screen,
     * for the same reason: passing view_reports needs a user with a real grant
     * on a real site, and a test user given only a ROLE has no grants. The
     * behavioural tests -- an analyst opening a report an admin built, and an
     * analyst being refused the builder -- live in the e2e suite, where the
     * fixture users are real.
     *
     * @dataProvider declaredCapabilityProvider
     */
    public function testEachScreenAsksForTheRightCapability(string $class, string $expected): void
    {
        $controller = new $class(array());

        $this->assertSame($expected, $controller->getRequiredCapability());
    }

    public static function declaredCapabilityProvider(): array
    {
        return array(
            'the roster is for any signed-in user' => array(
                '\OWA\Module\Base\Controller\CustomReports', 'view_site_list'),
            'building is for authors' => array(
                '\OWA\Module\Base\Controller\CustomReportEdit', 'edit_reports'),
            'saving is for authors' => array(
                '\OWA\Module\Base\Controller\CustomReportSave', 'edit_reports'),
            'deleting is for authors' => array(
                '\OWA\Module\Base\Controller\CustomReportDelete', 'edit_reports'),
        );
    }

    /**
     * Viewing a custom report is not gated on authorship.
     *
     * The dispatcher declares no capability of its own and delegates, so a
     * custom report is authorised exactly like a shipped one -- by
     * ConfiguredReport's view_reports. Asserted by rendering as a role that
     * HAS edit_reports removed from under it would be the same test as above;
     * what matters here is that the custom branch does not add a check of its
     * own, which is what would silently break sharing.
     */
    public function testViewingACustomReportAddsNoCapabilityOfItsOwn(): void
    {
        $dispatcher = new \OWA\Module\Base\Controller\Report(array());

        $this->assertEmpty($dispatcher->getRequiredCapability(),
            'the report dispatcher must delegate authorisation, not add to it');

        $configured = new \OWA\Core\ConfiguredReport(array());

        $this->assertSame('view_reports', $configured->getRequiredCapability(),
            'a custom report is authorised as a report, not as an edit');
    }

    /** An unauthenticated request reaches neither the roster nor the builder. */
    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        $this->requireDb();

        $this->asRole('everyone', false);

        foreach (array(
            '\OWA\Module\Base\Controller\CustomReports'    => 'base.customReports',
            '\OWA\Module\Base\Controller\CustomReportEdit' => 'base.customReportEdit',
        ) as $class => $action) {

            $refused = (array) (new $class(array('do' => $action)))->doAction();

            $this->assertArrayNotHasKey('subview', $refused,
                "$action was reachable unauthenticated");
        }

        $this->asRole('admin');
    }

        /**
     * The reserved prefix is checked BEFORE the registry, so a module cannot
     * register an id in that space and shadow somebody's saved report.
     */
    public function testTheCustomPrefixIsReservedAgainstTheRegistry(): void
    {
        $registry = (array) \OWA\Core\CoreAPI::getReportRegistry();

        foreach (array_keys($registry) as $id) {

            $this->assertStringStartsNotWith(
                \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX, (string) $id,
                "report id '$id' sits in the id space reserved for custom reports");
        }
    }
}
