<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * enableEcommerceReporting moves from the Profile to the Property, and down()
 * gives every Profile back the value it answered with.
 */
final class Update070Test extends TestCase
{
    const TABLE = 'owa_setting_update070_test';

    /** High and fixed, like the funnel fixtures'. */
    const PROPERTY = 92000070;

    /** Holds a value of its own already, which its Profile overrode. */
    const HELD_PROPERTY = 92000071;

    /** On itself, with every Profile switched off. */
    const OFF_PROPERTY = 92000072;

    const ON  = 'update070-on';
    const OFF = 'update070-off';
    const NUL = 'update070-null';
    const HELD = 'update070-held';
    const ALL_OFF = 'update070-all-off';
    const ORPHAN = 'update070-orphan';

    private \OWA\Module\Base\Update\Update070 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('moves rows between tables');
        }

        $this->dropFixture();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE,
            \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName()));

        foreach ([self::PROPERTY => [self::ON, self::OFF, self::NUL], self::HELD_PROPERTY => [self::HELD],
                  self::OFF_PROPERTY => [self::ALL_OFF]] as $property => $sites) {
            $db->query(sprintf("INSERT INTO owa_property (id, name, domain) VALUES (%d, 'Update070 fixture', 'update070.test')", $property));

            foreach ($sites as $i => $site) {
                $db->query(sprintf("INSERT INTO owa_site (id, site_id, property_id, name, domain) VALUES (%d, ?, %d, ?, 'update070.test')",
                    $property * 10 + $i, $property), [$site, $site]);
            }
        }

        $this->row('profile', self::ON, serialize('1'));
        $this->row('profile', self::OFF, serialize(false));
        $this->row('profile', self::NUL, serialize(null));
        $this->row('profile', self::HELD, serialize(true));
        $this->row('property', (string) self::HELD_PROPERTY, serialize(false));
        $this->row('profile', self::ALL_OFF, serialize(false));
        $this->row('property', (string) self::OFF_PROPERTY, serialize(true));
        $this->row('profile', self::ORPHAN, serialize(true));
        // Another setting at the profile scope, which this does not move.
        $this->row('profile', self::ON, serialize('example.test'), 'domain_aliases');

        $this->update = new \OWA\Module\Base\Update\Update070();
        $this->update->table = self::TABLE;
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->dropFixture();
        }
    }

    private function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);

        foreach ([self::ON, self::OFF, self::NUL, self::HELD, self::ALL_OFF] as $site) {
            $db->query('DELETE FROM owa_site WHERE site_id = ?', [$site]);
        }

        $db->query(sprintf('DELETE FROM owa_property WHERE id IN (%d, %d, %d)',
            self::PROPERTY, self::HELD_PROPERTY, self::OFF_PROPERTY));
    }

    private function row(string $scope, string $id, string $value, string $name = 'enableEcommerceReporting'): void
    {
        $setting = \OWA\Core\CoreAPI::entityFactory('base.setting');

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date) VALUES (?, ?, ?, 'base', ?, ?, 1, 1)",
            self::TABLE), [$setting->makeId($scope, $id, 'base', $name), $scope, $id, $name, $value]);
    }

    /** @return array<string,string> "scope:id:name" => value, every row */
    private function rows(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SELECT * FROM ' . self::TABLE) as $row) {
            $row = (array) $row;
            $out[$row['scope_type'] . ':' . $row['scope_id'] . ':' . $row['name']] = $row['value'];
        }

        ksort($out);

        return $out;
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(70, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(70,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    private function value(string $scope, string $id): ?string
    {
        return $this->rows()[$scope . ':' . $id . ':enableEcommerceReporting'] ?? null;
    }

    public function testEachPropertyIsOnWhenAnyProfileWas(): void
    {
        $this->assertTrue($this->update->up());

        // One Profile on is the Property on, whatever its siblings held.
        $this->assertSame(serialize(true), $this->value('property', (string) self::PROPERTY));

        // A Profile on under a Property off: the Profile wins, so it keeps its reports.
        $this->assertSame(serialize(true), $this->value('property', (string) self::HELD_PROPERTY));

        // Every Profile off under a Property on: no Profile showed them.
        $this->assertSame(serialize(false), $this->value('property', (string) self::OFF_PROPERTY));

        $this->assertSame([], array_values(preg_grep('/^profile:.*:enableEcommerceReporting$/', array_keys($this->rows()))),
            'a Profile still holds it');

        // Nothing else moves.
        $this->assertSame(serialize('example.test'), $this->rows()['profile:' . self::ON . ':domain_aliases'] ?? null);
    }

    /** NULL is no value: a Profile holding it inherits, like one with no row. */
    public function testANullProfileInheritsWhatItsPropertyHeld(): void
    {
        // The Property is on; its only Profile holds NULL rather than false.
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %s SET value = ? WHERE scope_id = ?', self::TABLE),
            [serialize(null), self::ALL_OFF]);

        $this->assertTrue($this->update->up());

        $this->assertSame(serialize(true), $this->value('property', (string) self::OFF_PROPERTY));
    }

    public function testUpIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $after = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($after, $this->rows());
    }

    /**
     * Each Profile answers, after down(), as its Property did after up(): the
     * code before this reads the Profile's row first.
     */
    public function testDownGivesEachProfileItsPropertysValue(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());

        foreach ([self::ON, self::OFF, self::NUL] as $site) {
            $this->assertSame(serialize(true), $this->value('profile', $site), $site);
        }

        $this->assertSame(serialize(true), $this->value('profile', self::HELD));
        $this->assertSame(serialize(false), $this->value('profile', self::ALL_OFF));

        $after = $this->rows();
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertSame($after, $this->rows());
    }

    /** up, down, up ends where one up does. */
    public function testTheRoundTripEndsWhereUpDoes(): void
    {
        $this->assertTrue($this->update->up());
        $once = $this->rows();

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->up());

        $this->assertSame($once, $this->rows());
    }
}
