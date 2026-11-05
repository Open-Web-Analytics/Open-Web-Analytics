<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update061;
use OWA\Module\Base\Update\Update081;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update081: owa_custom_report gains property_id, and every row made before it
 * is given a Property, by rule; down() drops the column.
 *
 * The database tests run up() and down() on every saved report the suite's
 * database holds. down() loses which Property each row had, and up() derives
 * one by rule, so setUp() keeps each row's and tearDown() runs up() and puts
 * them back: the database ends as it started.
 */
final class Update081Test extends TestCase
{
    const FUNNEL_PROPERTY = '7781000000081001';
    const OWNER_PROPERTY  = '7781000000081002';
    const OTHER_PROPERTY  = '7781000000081003';

    const FUNNEL_SITE = 'update081-funnel-site';
    const OWNER_SITE  = 'update081-owner-site';

    const OWNED   = '7781000000081101';
    const ORPHAN  = '7781000000081102';
    const ADMINS  = '7781000000081103';
    const ALREADY = '7781000000081104';

    private Update081 $update;

    /** @var string[] user_ids made here */
    private $users = [];

    /** @var array id => property_id, every row's before the test */
    private $kept = [];

    protected function setUp(): void
    {
        $this->update = new Update081();
        $this->kept   = [];

        if (!owa_test_db_available()) {
            return;
        }

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                'SELECT id, property_id FROM owa_custom_report WHERE property_id IS NOT NULL') as $row) {
            $row = (array) $row;
            $this->kept[(string) $row['id']] = (string) $row['property_id'];
        }
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        $this->assertTrue($this->update->up(), 'the column back, every row assigned');

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->kept as $id => $property) {
            $db->query('UPDATE owa_custom_report SET property_id = ? WHERE id = ?', [$property, $id]);
        }

        $db->query('DELETE FROM owa_custom_report WHERE id IN (?, ?, ?, ?, ?)',
            [self::OWNED, self::ORPHAN, self::ADMINS, self::ALREADY, $this->funnelId()]);
        $db->query("DELETE FROM owa_setting WHERE name = 'goals' AND scope_id = ?", [self::FUNNEL_SITE]);

        foreach ($this->users as $user_id) {
            $row = (array) $db->get_row('SELECT id FROM owa_user WHERE user_id = ?', [$user_id]);

            if (!empty($row['id'])) {
                $db->query('DELETE FROM owa_site_user WHERE user_id = ?', [$row['id']]);
            }

            $db->query('DELETE FROM owa_user WHERE user_id = ?', [$user_id]);
        }

        $db->query('DELETE FROM owa_site WHERE site_id IN (?, ?)', [self::FUNNEL_SITE, self::OWNER_SITE]);
        $db->query(sprintf('DELETE FROM owa_property WHERE id IN (%s, %s, %s)',
            self::FUNNEL_PROPERTY, self::OWNER_PROPERTY, self::OTHER_PROPERTY));

        $this->users = [];
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(81, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(81,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** The rules, in order: a 1.x funnel's Profile, then the creator's one Property, then the first. */
    public function testTheRules(): void
    {
        $funnels = ['10' => 'F'];

        $this->assertSame('F', Update081::assign('10', $funnels, ['A'], 'FIRST')['property_id'],
            "a 1.x funnel takes its Profile's, whoever owns it");
        $this->assertSame('A', Update081::assign('11', $funnels, ['A', 'A'], 'FIRST')['property_id'],
            'a creator who may see exactly one');
        $this->assertSame('FIRST', Update081::assign('11', $funnels, ['A', 'B'], 'FIRST')['property_id'],
            'a creator who may see several');
        $this->assertSame('FIRST', Update081::assign('11', $funnels, [], 'FIRST')['property_id'],
            'a creator who may see none, or has no account');
        $this->assertSame('', Update081::assign('11', [], [], '')['property_id'], 'an install with no Property');

        $this->assertStringContainsString('may see 2', Update081::assign('11', [], ['A', 'B'], 'FIRST')['reason']);
    }

    private function requireDatabase(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('assigns stored reports');
        }
    }

    private function funnelId(): string
    {
        return Update061::funnelId(self::FUNNEL_SITE, 1);
    }

    private function report(string $id, string $user_id, ?string $property = null): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_custom_report (id, name, user_id, report_type, definition, property_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, 'Update081 ' . $id, $user_id, 'report', '{"title":"Update081"}', $property]);
    }

    private function propertyOf(string $id): string
    {
        return (string) (((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT property_id FROM owa_custom_report WHERE id = ?', [$id]))['property_id'] ?? '');
    }

    private function user(string $role): string
    {
        $user_id = 'update081-' . substr(md5(uniqid('', true)), 0, 8);

        $um = \OWA\Core\CoreAPI::supportClassFactory('base', 'userManager');
        $this->assertNotFalse($um->createNewUser([
            'user_id' => $user_id, 'role' => $role, 'password' => 'u081-' . uniqid('', true),
            'email_address' => $user_id . '@example.test', 'real_name' => 'Update081 probe',
        ]), 'could not create the probe user');

        $this->users[] = $user_id;

        return $user_id;
    }

    /** The install's first Property as the class defines it: live, lowest creation_date (none counts as oldest), then id. */
    private function firstProperty(): string
    {
        return (string) (((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT id FROM owa_property WHERE archived_date IS NULL OR archived_date = 0'
            . ' ORDER BY COALESCE(creation_date, 0), id LIMIT 1'))['id'] ?? '');
    }

    /**
     * Three Properties, a Profile on two, a creator granted one of them, an
     * administrator, a 1.x funnel's `goals` setting, and a report of each kind.
     */
    private function seed(): array
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::FUNNEL_PROPERTY => 2000000000, self::OWNER_PROPERTY => 2000000001, self::OTHER_PROPERTY => 2000000002]
                 as $property => $created) {
            $db->query(sprintf("INSERT INTO owa_property (id, name, domain, creation_date) VALUES (%s, 'Update081', 'update081.test', %d)",
                $property, $created));
        }

        foreach ([self::FUNNEL_SITE => self::FUNNEL_PROPERTY, self::OWNER_SITE => self::OWNER_PROPERTY] as $site => $property) {
            $db->query('INSERT INTO owa_site (id, site_id, domain, name, property_id) VALUES (?, ?, ?, ?, ?)',
                [\OWA\Core\Lib::setStringGuid($site), $site, 'update081.test', $site, $property]);
        }

        $analyst = $this->user('analyst');
        $admin   = $this->user('admin');

        $row = (array) $db->get_row('SELECT id FROM owa_user WHERE user_id = ?', [$analyst]);
        $db->query('INSERT INTO owa_site_user (site_id, user_id) VALUES (?, ?)',
            [\OWA\Core\Lib::setStringGuid(self::OWNER_SITE), $row['id']]);

        $goals = [1 => ['goal_name' => 'Signup', 'goal_number' => 1, 'details' => ['funnel_steps' => [
            1 => ['name' => 'Pricing', 'path' => '/pricing', 'step_number' => 1],
        ]]]];

        $db->query("INSERT INTO owa_setting (id, module, name, scope_type, scope_id, value) VALUES (?, 'base', 'goals', 'profile', ?, ?)",
            [\OWA\Core\Lib::setStringGuid('profile|' . self::FUNNEL_SITE . '|base|goals'), self::FUNNEL_SITE, serialize($goals)]);

        // Owned by the analyst, so only the funnel rule gives it the funnel's Property.
        $this->report($this->funnelId(), $analyst);
        $this->report(self::OWNED, $analyst);
        $this->report(self::ORPHAN, 'update081-nobody@example.test');
        $this->report(self::ADMINS, $admin);
        $this->report(self::ALREADY, $analyst, self::OTHER_PROPERTY);

        return [$analyst, $admin];
    }

    public function testUpAssignsEachRowByItsRule(): void
    {
        $this->requireDatabase();
        $this->seed();

        $this->assertTrue($this->update->up());

        $this->assertSame(self::FUNNEL_PROPERTY, $this->propertyOf($this->funnelId()), "the 1.x funnel: its Profile's");
        $this->assertSame(self::OWNER_PROPERTY, $this->propertyOf(self::OWNED), "its creator's one Property");
        $this->assertSame($this->firstProperty(), $this->propertyOf(self::ORPHAN), 'no account: the first Property');
        $this->assertSame($this->firstProperty(), $this->propertyOf(self::ADMINS),
            'an administrator may see every Property: the first');
        $this->assertSame(self::OTHER_PROPERTY, $this->propertyOf(self::ALREADY), 'one with a Property keeps it');
    }

    public function testUpTwiceChangesNothing(): void
    {
        $this->requireDatabase();
        $this->seed();

        $this->assertTrue($this->update->up());

        $before = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SELECT id, property_id FROM owa_custom_report ORDER BY id');

        $this->assertTrue($this->update->up());

        $this->assertSame($before,
            (array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SELECT id, property_id FROM owa_custom_report ORDER BY id'));
    }

    public function testDownDropsTheColumnAndTwiceIsANoOp(): void
    {
        $this->requireDatabase();
        $this->seed();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());

        $columns = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW COLUMNS FROM owa_custom_report LIKE 'property_id'");

        $this->assertSame([], $columns);
        $this->assertNotSame([], (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT id FROM owa_custom_report WHERE id = ?', [self::OWNED]), 'no row goes with it');

        $this->assertTrue($this->update->down(), 'a second down finds nothing to drop');

        $this->assertTrue($this->update->up());
        $this->assertSame(self::OWNER_PROPERTY, $this->propertyOf(self::OWNED), 'up again assigns again');
    }
}
