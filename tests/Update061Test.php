<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update061: saved 1.14 custom reports into v2's names, pre-1.13 funnels into
 * visualizations, and down() putting both back.
 */
final class Update061Test extends TestCase
{
    private const SITE = 'update061-site';
    private const REPORT = '9200000000000005001';
    private const BROKEN = '9200000000000005002';

    private \OWA\Module\Base\Update\Update061 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes reports');
        }

        $this->update = new \OWA\Module\Base\Update\Update061();
        $this->clean();
        $this->update->up();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query('INSERT INTO owa_site (id, site_id, domain, name) VALUES (?, ?, ?, ?)',
            [\OWA\Core\Lib::setStringGuid(self::SITE), self::SITE, 'shop.example', 'Shop']);

        $this->report(self::REPORT, ['title' => 'Weekly', 'widgets' => [
            ['type' => 'grid', 'query' => ['metrics' => 'visits,actions', 'dimensions' => 'source']],
        ]]);

        // A widget type 2.0 does not build: the rewrite cannot make it render.
        $this->report(self::BROKEN, ['title' => 'Odd', 'widgets' => [
            ['type' => 'sparkline', 'query' => ['metrics' => 'visits']],
        ]]);

        $goals = [1 => [
            'goal_name'   => 'Signup',
            'goal_number' => 1,
            'details'     => ['goal_url' => '/thanks', 'match_type' => 'exact', 'funnel_steps' => [
                2 => ['name' => 'Form', 'path' => '/signup', 'step_number' => 2, 'is_required' => 1],
                1 => ['name' => 'Pricing', 'path' => '/pricing', 'step_number' => 1],
            ]],
        ], 2 => ['goal_name' => 'No funnel', 'details' => ['goal_url' => '/x']]];

        $db->query("INSERT INTO owa_setting (id, module, name, scope_type, scope_id, value) VALUES (?, 'base', 'goals', 'profile', ?, ?)",
            [\OWA\Core\Lib::setStringGuid('profile|' . self::SITE . '|base|goals'), self::SITE, serialize($goals)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->clean();
            $this->update->up();
        }
    }

    private function clean(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->plans() as $plan) {
            $db->query('DELETE FROM owa_custom_report WHERE id = ?', [$plan['id']]);
        }

        $db->query('DELETE FROM owa_custom_report WHERE id IN (?, ?)', [self::REPORT, self::BROKEN]);
        $db->query("DELETE FROM owa_setting WHERE name = 'goals' AND scope_id = ?", [self::SITE]);
        $db->query('DELETE FROM owa_site WHERE site_id = ?', [self::SITE]);
    }

    private function plans(): array
    {
        return array_values(array_filter($this->update->funnelPlans(), fn ($p) => $p['goal'] === 'Signup'));
    }

    private function report(string $id, array $definition): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_custom_report (id, name, user_id, report_type, definition) VALUES (?, ?, ?, ?, ?)',
            [$id, $definition['title'], 'alice', 'report', json_encode($definition)]);
    }

    private function row(string $id): array
    {
        return (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT * FROM owa_custom_report WHERE id = ?', [$id]);
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(61, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(61,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    public function testAReportIsRewrittenAndItsOriginalKept(): void
    {
        $original = $this->row(self::REPORT)['definition'];

        $this->assertTrue($this->update->up());

        $row = $this->row(self::REPORT);
        $definition = json_decode($row['definition'], true);

        $this->assertSame('sessions', $definition['widgets'][0]['query']['metrics']);
        $this->assertSame('sessionSource', $definition['widgets'][0]['query']['dimensions']);
        $this->assertSame($original, $row['v1_definition']);
        $this->assertSame('', \OWA\Module\Base\Classes\CustomReports::validate($definition), 'it renders');

        $this->assertTrue($this->update->up());
        $this->assertSame($row['definition'], $this->row(self::REPORT)['definition'], 'idempotent');
    }

    public function testAReportTheRewriteCannotFixIsLeftAsItWas(): void
    {
        $before = $this->row(self::BROKEN);

        $this->assertTrue($this->update->up());

        $after = $this->row(self::BROKEN);

        $this->assertSame($before['definition'], $after['definition']);
        $this->assertNull($after['v1_definition']);
    }

    public function testAPre113FunnelBecomesAVisualization(): void
    {
        $this->assertTrue($this->update->up());

        $plan = $this->plans()[0];
        $row = $this->row($plan['id']);

        $this->assertSame('visualization', $row['report_type']);
        $this->assertSame('funnel', $row['visualization_type']);
        $this->assertSame(1, (int) $row['is_shared']);
        $this->assertSame('Signup funnel (Shop, from 1.x)', $row['name']);

        $steps = json_decode($row['definition'], true)['steps'];

        $this->assertSame(['/pricing', '/signup'], array_column(array_slice($steps, 0, 2), 'path'), 'in step order');
        $this->assertSame([1, 2, 3], array_column($steps, 'step_number'));
        $this->assertSame('Signup', $steps[2]['name']);
        $this->assertArrayHasKey('goal_event_id', $steps[2]);
        $this->assertStringContainsString('marked required', implode(' ', $plan['notes']));

        $this->assertCount(1, $this->plans(), 'a goal without a funnel is not one');
        $this->assertTrue($this->update->up());
        $this->assertSame($row['creation_timestamp'], $this->row($plan['id'])['creation_timestamp'], 'not re-created');
    }

    public function testDownRestoresTheReportsRemovesTheFunnelsAndTheColumn(): void
    {
        $original = $this->row(self::REPORT)['definition'];

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent');

        $this->assertSame($original, $this->row(self::REPORT)['definition']);
        $this->assertArrayNotHasKey('v1_definition', $this->row(self::REPORT));
        $this->assertSame([], $this->row($this->plans()[0]['id']));

        $this->assertTrue($this->update->up());
        $this->assertSame('sessions', json_decode($this->row(self::REPORT)['definition'], true)['widgets'][0]['query']['metrics']);
    }
}
