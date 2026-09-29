<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Migration\WideIds;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * WideIds: the kept tables' 32-bit ids, re-keyed to 64-bit and back.
 *
 * Run against copies of the four tables, so the installation's own rows are
 * never touched.
 */
final class WideIdsTest extends TestCase
{
    private const SITE = 'wide-ids-site';

    private array $tables = [];

    private WideIds $ids;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $suffix = bin2hex(random_bytes(3));

        $this->ids = new WideIds();

        foreach (['base.site', 'base.site_user', 'base.setting', 'base.notification_state'] as $entity) {
            $real = \OWA\Core\CoreAPI::entityFactory($entity)->getTableName();
            $copy = 'owa_wideids_' . $suffix . '_' . substr($real, 4);
            $db->query(sprintf('CREATE TABLE %s LIKE %s', $copy, $real));
            $this->tables[] = $copy;
            $this->ids->tables[$entity] = $copy;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $t) {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s', $t));
        }
    }

    private function t(string $entity): string
    {
        return $this->ids->tables[$entity];
    }

    private function insert(string $entity, array $row): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->t($entity),
            implode(',', array_map(fn ($c) => "`$c`", array_keys($row))), implode(',', array_fill(0, count($row), '?'))),
            array_values($row));
    }

    private function ids(string $entity, string $column = 'id'): array
    {
        $ids = array_map(fn ($r) => (string) ((array) $r)[$column], (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf('SELECT %s FROM %s ORDER BY %s', $column, $this->t($entity), $column)));
        sort($ids);

        return $ids;
    }

    private static function narrow(string $content): string
    {
        return (string) crc32(strtolower($content));
    }

    private static function wide(string $content): string
    {
        return (string) \OWA\Core\Lib::wideStringGuid($content);
    }

    /** A 1.14 installation's rows: every derived id still 32-bit. */
    private function seedNarrow(): void
    {
        $this->insert('base.site', ['id' => self::narrow(self::SITE), 'site_id' => self::SITE, 'domain' => 'a.example']);
        $this->insert('base.site_user', ['site_id' => self::narrow(self::SITE), 'user_id' => '7']);
        $this->insert('base.setting', ['id' => self::narrow('profile|' . self::SITE . '|base|goals'),
            'scope_type' => 'profile', 'scope_id' => self::SITE, 'module' => 'base', 'name' => 'goals', 'value' => 'a']);
        $this->insert('base.notification_state', ['id' => self::narrow('9001alice'), 'notification_id' => '9001',
            'user_id' => 'alice', 'read_at' => 1]);
    }

    public function testEveryDerivedIdIsWidenedAndWhatHoldsItFollows(): void
    {
        $this->seedNarrow();

        $this->assertTrue($this->ids->widen());

        $this->assertSame([self::wide(self::SITE)], $this->ids('base.site'));
        $this->assertSame([self::wide(self::SITE)], $this->ids('base.site_user', 'site_id'));
        $this->assertSame([self::wide('profile|' . self::SITE . '|base|goals')], $this->ids('base.setting'));
        $this->assertSame([self::wide('9001alice')], $this->ids('base.notification_state'));
        $this->assertCount(3, $this->ids->report);

        $this->assertTrue($this->ids->widen());
        $this->assertSame([], $this->ids->report, 'a second run finds nothing');
    }

    /** The same derivation Lib uses: a lookup after widening finds the row. */
    public function testAWidenedIdIsTheOneTheCodeDerives(): void
    {
        $this->seedNarrow();
        $this->ids->widen();

        $setting = \OWA\Core\CoreAPI::entityFactory('base.setting');

        $this->assertSame((string) $setting->makeId('profile', self::SITE, 'base', 'goals'), $this->ids('base.setting')[0]);
        $this->assertSame((string) $setting->generateId(self::SITE), $this->ids('base.site')[0]);
    }

    /**
     * An installation that ran 1.14's command still wrote 32-bit settings
     * beside it; a row since written at the 64-bit id is the one read, so it
     * is kept and the 32-bit copy goes.
     */
    public function testA32BitCopyOfARowAlreadyAtItsNewIdIsRemoved(): void
    {
        $this->insert('base.setting', ['id' => self::narrow('profile|' . self::SITE . '|base|goals'),
            'scope_type' => 'profile', 'scope_id' => self::SITE, 'module' => 'base', 'name' => 'goals', 'value' => 'old']);
        $this->insert('base.setting', ['id' => self::wide('profile|' . self::SITE . '|base|goals'),
            'scope_type' => 'profile', 'scope_id' => self::SITE, 'module' => 'base', 'name' => 'goals', 'value' => 'new']);

        $this->assertTrue($this->ids->widen());

        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf('SELECT id, value FROM %s', $this->t('base.setting')));
        $this->assertCount(1, $rows);
        $this->assertSame('new', ((array) $rows[0])['value']);
        $this->assertStringContainsString('1 duplicate', implode(' ', $this->ids->report));
    }

    /** Only an id that IS the crc32 of its content moves; any other is left alone. */
    public function testAnIdNotDerivedFromItsContentIsLeftAlone(): void
    {
        $this->insert('base.site', ['id' => '12345', 'site_id' => self::SITE, 'domain' => 'a.example']);
        $this->insert('base.notification_state', ['id' => '777', 'notification_id' => '9001', 'user_id' => 'alice']);

        $this->assertTrue($this->ids->widen());

        $this->assertSame(['12345'], $this->ids('base.site'));
        $this->assertSame(['777'], $this->ids('base.notification_state'));
    }

    /** 1.x wrote crc32 into a signed column on some paths. */
    public function testANegative32BitIdIsRecognised(): void
    {
        $crc = crc32(self::SITE);
        $signed = (string) ($crc > 2147483647 ? $crc - 4294967296 : $crc);

        $this->assertTrue(WideIds::isNarrow($signed, self::SITE));
        $this->assertTrue(WideIds::isNarrow((string) $crc, self::SITE));
        $this->assertFalse(WideIds::isNarrow(self::wide(self::SITE), self::SITE));
    }

    public function testNarrowPutsEveryDerivedIdBack(): void
    {
        $this->seedNarrow();
        $before = [$this->ids('base.site'), $this->ids('base.site_user', 'site_id'), $this->ids('base.setting'),
            $this->ids('base.notification_state')];

        $this->ids->widen();
        $this->assertTrue($this->ids->narrow());

        $this->assertSame($before, [$this->ids('base.site'), $this->ids('base.site_user', 'site_id'),
            $this->ids('base.setting'), $this->ids('base.notification_state')]);
    }
}
