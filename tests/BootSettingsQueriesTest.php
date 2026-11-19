<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a boot asks the database for its settings: one query, and nothing
 * later for a key that query already answered.
 *
 * On a fresh Settings, so the booted one is not disturbed.
 */
final class BootSettingsQueriesTest extends TestCase
{
    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('counts the queries a boot sends');
        }
    }

    private function queriesDuring(callable $block): int
    {
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $before = $db->num_queries;

        $block();

        return $db->num_queries - $before;
    }

    /** The rows say they are the store, so the legacy table is not looked for. */
    public function testALoadIsOneQuery(): void
    {
        $this->requireDb();

        $s = new \OWA\Module\Base\Classes\Settings();

        $this->assertSame(1, $this->queriesDuring(fn () => $s->load(1)));
    }

    /** An eager key with no row was asked for at boot; "no row" is the answer. */
    public function testAnEagerKeyWithNoRowIsNotAskedForAgain(): void
    {
        $this->requireDb();

        $s = new \OWA\Module\Base\Classes\Settings();
        $s->registerField('base', 'zz_boot_probe_eager', ['autoload' => true, 'default' => 'the-default']);
        $s->registerField('base', 'zz_boot_probe_lazy', ['storable' => true, 'default' => 'lazy-default']);
        $s->load(1);

        $this->assertSame(0, $this->queriesDuring(function () use ($s) {
            $this->assertSame('the-default', $s->get('base', 'zz_boot_probe_eager'));
        }));

        // A lazy key is still resolved, by the one batch.
        $this->assertSame(1, $this->queriesDuring(function () use ($s) {
            $this->assertSame('lazy-default', $s->get('base', 'zz_boot_probe_lazy'));
        }));
    }

    /** Read for every beacon, so fetched with boot's query rather than by a batch of every install row. */
    public function testTheTrackingPathsSettingsAreEager(): void
    {
        $eager = \OWA\Core\CoreAPI::configSingleton()->eagerSettings()['base'] ?? [];

        foreach (['tracker_cookie_domain', 'campaignKeys', 'campaignUtmParams', 'timezone'] as $key) {
            $this->assertContains($key, $eager, $key);
        }
    }

    /**
     * Rows show Update043 complete from schema 43. Before that -- none, or an
     * Update043 that stopped part way with the blob's own number in them --
     * the legacy table decides.
     */
    public function testTheRowsAreTheStoreFromSchema43(): void
    {
        $m = new ReflectionMethod(\OWA\Module\Base\Classes\Settings::class, 'rowsAreTheStore');

        $this->assertTrue($m->invoke(null, ['base' => ['schema_version' => 93]]));
        $this->assertTrue($m->invoke(null, ['base' => ['schema_version' => '43']]));
        $this->assertFalse($m->invoke(null, ['base' => ['schema_version' => 42]]));
        $this->assertFalse($m->invoke(null, ['base' => ['is_active' => true]]));
        $this->assertFalse($m->invoke(null, []));
    }
}
