<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update078;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update078: saved reports name the goal metrics by their new names, and the
 * conversions metric set by goals.
 *
 * The mapping is pure and tested without a database. The update itself is run
 * up, up again, down, down again and up, against fixture rows, so the database
 * the suite runs against ends where it started.
 */
final class Update078Test extends TestCase
{
    const PLAIN      = '7800000000000078001';
    const ENCODED    = '7800000000000078002';
    const UNREADABLE = '7800000000000078003';

    private Update078 $update;

    protected function setUp(): void
    {
        $this->update = new Update078();
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        $this->update->up();

        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_custom_report WHERE id IN (?, ?, ?)',
            [self::PLAIN, self::ENCODED, self::UNREADABLE]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(78, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(78,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** Every name it maps to is a registered metric, and every name it maps from is not. */
    public function testItMapsToTheMetricsThatExist(): void
    {
        foreach (Update078::METRICS as $from => $to) {
            $this->assertTrue(\OWA\Module\Base\Classes\CustomReports::isMetric($to), "$to is registered");
            $this->assertFalse(\OWA\Module\Base\Classes\CustomReports::isMetric($from), "$from is gone");
        }

        $this->assertArrayHasKey('goals', \OWA\Core\MetricSets::SETS);
        $this->assertArrayNotHasKey('conversions', \OWA\Core\MetricSets::SETS);
    }

    /** Every place a definition names a metric, and nowhere else. */
    public function testEveryPlaceADefinitionNamesAMetricIsRenamed(): void
    {
        $this->assertSame($this->newDefinition(),
            Update078::rename($this->oldDefinition(), Update078::METRICS, Update078::SETS));
    }

    /** down()'s map gives back exactly what up()'s was given. */
    public function testTheInverseMapGivesTheDefinitionBack(): void
    {
        $this->assertSame($this->oldDefinition(), Update078::rename($this->newDefinition(),
            array_flip(Update078::METRICS), array_flip(Update078::SETS)));
    }

    /** A definition stored plain or entity-encoded keeps every other byte, both ways. */
    public function testTheStoredTextChangesOnlyInTheNames(): void
    {
        $plain   = json_encode($this->oldDefinition());
        $encoded = htmlspecialchars($plain, ENT_QUOTES);

        foreach ([$plain, $encoded] as $stored) {

            $up = Update078::rewriteStored($stored, Update078::METRICS, Update078::SETS);

            $this->assertSame($this->newDefinition(), json_decode(html_entity_decode($up, ENT_QUOTES), true));
            $this->assertSame($stored, Update078::rewriteStored($up, array_flip(Update078::METRICS),
                array_flip(Update078::SETS)), 'down() gives back the bytes up() was given');
        }

        $this->assertStringContainsString('&quot;goals&quot;',
            Update078::rewriteStored($encoded, Update078::METRICS, Update078::SETS),
            'an entity-encoded definition stays entity-encoded');
    }

    /**
     * An author's title naming an old metric, or reading exactly like the old
     * set's name, is text. Replacing names in the stored text would change it,
     * so the definition is rewritten from its structure instead and the titles
     * are kept.
     */
    public function testANameInATitleIsKept(): void
    {
        $definition = $this->oldDefinition();
        $definition['title'] = 'Why goalConversions moved';
        $definition['widgets'][1]['title'] = 'conversions';

        $up = json_decode(Update078::rewriteStored(json_encode($definition), Update078::METRICS, Update078::SETS), true);

        $this->assertSame('Why goalConversions moved', $up['title']);
        $this->assertSame('conversions', $up['widgets'][1]['title']);
        $this->assertSame('sessions,goalEvents,goalEventsPerSession', $up['metrics']);
        $this->assertSame(['site_usage', 'goals'], $up['metricSets']);
    }

    public function testADefinitionNamingNoneIsUntouchedAndAnUnreadableOneIsNull(): void
    {
        $stored = json_encode(['metrics' => 'sessions , pageViews', 'widgets' => []]);

        $this->assertSame($stored, Update078::rewriteStored($stored, Update078::METRICS, Update078::SETS));
        $this->assertNull(Update078::rewriteStored('{"metrics": "goalConversions"', Update078::METRICS));
    }

    public function testUpTwiceThenDownTwiceThenUpAgain(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('rewrites owa_custom_report');
        }

        $plain   = json_encode($this->oldDefinition());
        $encoded = htmlspecialchars($plain, ENT_QUOTES);
        $broken  = '{"metrics": "goalConversions"';

        $this->report(self::PLAIN, $plain);
        $this->report(self::ENCODED, $encoded);
        $this->report(self::UNREADABLE, $broken);

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'twice');

        $this->assertSame($this->newDefinition(), json_decode($this->stored(self::PLAIN), true));
        $this->assertSame($this->newDefinition(),
            json_decode(html_entity_decode($this->stored(self::ENCODED), ENT_QUOTES), true));
        $this->assertSame($broken, $this->stored(self::UNREADABLE), 'a definition that does not decode is left');

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'twice');

        $this->assertSame($plain, $this->stored(self::PLAIN), 'down() gives back the bytes up() was given');
        $this->assertSame($encoded, $this->stored(self::ENCODED));
        $this->assertSame($broken, $this->stored(self::UNREADABLE));

        $this->assertTrue($this->update->up());
        $this->assertSame($this->newDefinition(), json_decode($this->stored(self::PLAIN), true));
    }

    /* ---------------- helpers ---------------- */

    /**
     * A definition naming the old metrics in every place one can be named. Its
     * title holds one inside a longer word, which is not the name.
     */
    private function oldDefinition(): array
    {
        return [
            'title'      => 'Goal conversions by goalConversionsChannel',
            'metrics'    => 'sessions,goalConversions,goalConversionRatePerSession',
            'metricSets' => ['site_usage', 'conversions'],
            'widgets'    => [
                [
                    'type'        => 'trend',
                    'query'       => ['dimensions' => 'date', 'sort' => 'date'],
                    'chartMetric' => 'goalConversions',
                ],
                [
                    'type'           => 'grid',
                    'title'          => 'By goal event',
                    'query'          => ['metrics' => ['goalConversions', 'goalConversionRatePerUser', 'totalUsers'],
                                         'dimensions' => 'eventName', 'sort' => 'goalConversions-, totalUsers'],
                    'excludeColumns' => ['goalConversionRatePerUser'],
                    'formatters'     => ['goalConversions' => 'integer', 'eventName' => 'text'],
                ],
            ],
        ];
    }

    /** The same, renamed. */
    private function newDefinition(): array
    {
        return [
            'title'      => 'Goal conversions by goalConversionsChannel',
            'metrics'    => 'sessions,goalEvents,goalEventsPerSession',
            'metricSets' => ['site_usage', 'goals'],
            'widgets'    => [
                [
                    'type'        => 'trend',
                    'query'       => ['dimensions' => 'date', 'sort' => 'date'],
                    'chartMetric' => 'goalEvents',
                ],
                [
                    'type'           => 'grid',
                    'title'          => 'By goal event',
                    'query'          => ['metrics' => ['goalEvents', 'goalEventsPerUser', 'totalUsers'],
                                         'dimensions' => 'eventName', 'sort' => 'goalEvents-, totalUsers'],
                    'excludeColumns' => ['goalEventsPerUser'],
                    'formatters'     => ['goalEvents' => 'integer', 'eventName' => 'text'],
                ],
            ],
        ];
    }

    private function report(string $id, string $definition): void
    {
        $this->assertTrue((bool) \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_custom_report (id, name, report_type, definition) VALUES (?, ?, ?, ?)',
            [$id, 'Update078 probe', 'report', $definition]));
    }

    private function stored(string $id): string
    {
        return (string) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT definition FROM owa_custom_report WHERE id = ?', [$id]))['definition'];
    }
}
