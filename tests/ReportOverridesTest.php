<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\CustomReports;
use OWA\Module\Base\Classes\ReportOverrides;

/**
 * A user's own version of a shipped report: per user, per Property, off the
 * roster, drawn in the shipped one's place, and told apart from a changed
 * default.
 */
final class ReportOverridesTest extends TestCase
{
    private const READER   = 'report-override-reader@example.test';
    private const OTHER    = 'report-override-other@example.test';
    private const PROPERTY = '7781000000000201';
    private const ELSEWHERE = '7781000000000202';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('an override is a stored row');
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);

        self::deleteFixtureRows();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            self::deleteFixtureRows();
        }
    }

    private static function deleteFixtureRows(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::READER, self::OTHER] as $owner) {
            $db->selectFrom(\OWA\Core\CoreAPI::entityFactory('base.custom_report')->getTableName());
            $db->selectColumn('id');
            $db->where('user_id', $owner);

            foreach ((array) $db->getAllRows() as $row) {
                CustomReports::delete($row['id']);
            }
        }
    }

    /** The shipped Dashboard with one widget fewer: a version of it a person might make. */
    private function edited(): array
    {
        $definition = ReportOverrides::shipped('dashboard');
        array_pop($definition['widgets']);

        return $definition;
    }

    public function testOnlyAReportTheBuilderCouldHaveMadeIsEditable(): void
    {
        $this->assertTrue(ReportOverrides::isEditable('dashboard'));
        $this->assertTrue(ReportOverrides::isEditable('clicks'), 'a report-level filter is kept by the builder');
        $this->assertFalse(ReportOverrides::isEditable('browsers'), 'draws the Property\'s metric sets');
        $this->assertFalse(ReportOverrides::isEditable('dom-clicks'), 'reads a page from the URL');
        $this->assertFalse(ReportOverrides::isEditable('custom-123'), 'not a shipped definition');
        $this->assertFalse(ReportOverrides::isEditable('no-such-report'));
    }

    public function testAVersionIsTheReadersOnThatPropertyAndOffTheRoster(): void
    {
        $saved = ReportOverrides::save('dashboard', 'My dashboard', $this->edited(), self::READER, self::PROPERTY);

        $this->assertTrue($saved['ok'], $saved['error']);

        $mine = ReportOverrides::forReader('dashboard', self::READER, self::PROPERTY);

        $this->assertNotNull($mine);
        $this->assertSame($saved['id'], $mine['id']);
        $this->assertSame('', $mine['error']);
        $this->assertFalse($mine['stale']);
        $this->assertCount(count($this->edited()['widgets']), $mine['definition']['widgets']);

        $this->assertNull(ReportOverrides::forReader('dashboard', self::OTHER, self::PROPERTY), 'another user sees the default');
        $this->assertNull(ReportOverrides::forReader('dashboard', self::READER, self::ELSEWHERE), 'another Property sees the default');

        $listed = array_column(CustomReports::roster(self::READER, true, '', null, null, null, false, false, self::PROPERTY), 'id');
        $this->assertNotContains($saved['id'], $listed, 'not a custom report on the roster');

        $row = CustomReports::load($saved['id']);
        $this->assertSame('dashboard', $row['replaces']);
        $this->assertEmpty($row['is_shared'], 'never shared');
    }

    public function testSavingAgainReplacesTheVersionRatherThanAddingOne(): void
    {
        $first  = ReportOverrides::save('dashboard', 'Mine', $this->edited(), self::READER, self::PROPERTY);
        $second = ReportOverrides::save('dashboard', 'Mine, again', $this->edited(), self::READER, self::PROPERTY);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('Mine, again', CustomReports::load($first['id'])['name']);
    }

    public function testAReportThatCannotBeEditedIsNotSaved(): void
    {
        $result = ReportOverrides::save('browsers', 'Mine', ReportOverrides::shipped('browsers'), self::READER, self::PROPERTY);

        $this->assertFalse($result['ok']);
        $this->assertNull(ReportOverrides::find('browsers', self::READER, self::PROPERTY));
    }

    public function testAVersionOutsideTheBuildersRulesIsNotSaved(): void
    {
        $definition = $this->edited();
        $definition['widgets'][0]['query']['metrics'] = 'noSuchMetric';

        $this->assertFalse(ReportOverrides::save('dashboard', 'Mine', $definition, self::READER, self::PROPERTY)['ok']);
    }

    /** A release that changes the shipped report: the version says so, and is still drawn. */
    public function testAChangedDefaultIsTold(): void
    {
        $saved = ReportOverrides::save('dashboard', 'Mine', $this->edited(), self::READER, self::PROPERTY);

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %s SET replaces_hash = ? WHERE id = ?',
            \OWA\Core\CoreAPI::entityFactory('base.custom_report')->getTableName()), ['an-older-default', $saved['id']]);

        $mine = ReportOverrides::forReader('dashboard', self::READER, self::PROPERTY);

        $this->assertTrue($mine['stale']);
        $this->assertNotEmpty($mine['definition']);
    }

    /** Reset is the delete: the reader is back on the default. */
    public function testResetLeavesTheDefault(): void
    {
        $saved = ReportOverrides::save('dashboard', 'Mine', $this->edited(), self::READER, self::PROPERTY);

        CustomReports::delete($saved['id']);

        $this->assertNull(ReportOverrides::forReader('dashboard', self::READER, self::PROPERTY));
    }

    /** The filter a shipped report applies is kept with the reader's version of it. */
    public function testAFilteredReportKeepsItsFilter(): void
    {
        $shipped = ReportOverrides::shipped('clicks');
        $saved   = ReportOverrides::save('clicks', 'My clicks', $shipped, self::READER, self::PROPERTY);

        $this->assertTrue($saved['ok'], $saved['error']);
        $this->assertSame($shipped['settings'],
            ReportOverrides::forReader('clicks', self::READER, self::PROPERTY)['definition']['settings']);
    }
}
