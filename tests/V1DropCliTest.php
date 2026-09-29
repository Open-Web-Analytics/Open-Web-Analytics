<?php

require_once __DIR__ . '/CliControllerTestCase.php';
require_once __DIR__ . '/V1Schema.php';

/**
 * cmd=v1-drop: reports by default, drops only when asked, and not before the
 * migration has run.
 *
 * Against the frozen 1.14 schema under its own prefix, so the installation's
 * own v1 tables are never touched.
 */
final class V1DropCliTest extends CliControllerTestCase
{
    private $schema;

    protected function setUp(): void
    {
        parent::setUp();

        V1Schema::load();
        $this->schema = \OWA\Core\CoreAPI::getSetting('base', 'schema_version');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'schema_version', $this->schema);
        \OWA\Core\CoreAPI::persistSetting('base', 'v1_tables_dropped', false);
        V1Schema::drop();

        parent::tearDown();
    }

    private function run_(array $params = []): \OWA\Module\Base\Controller\V1DropCli
    {
        $cli = new \OWA\Module\Base\Controller\V1DropCli($params);
        $cli->prefix = V1Schema::PREFIX;

        $cli->action();

        return $cli;
    }

    private function present(): array
    {
        return \OWA\Module\Base\Classes\Migration\V1Tables::present(
            \OWA\Core\CoreAPI::dbSingleton(), V1Schema::PREFIX);
    }

    public function testWithoutDropNothingIsDropped(): void
    {
        $before = $this->present();
        $this->assertNotEmpty($before, 'the fixture has v1 tables');

        $cli = $this->run_();

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertSame($before, $this->present());
        $this->assertFalse((bool) \OWA\Core\CoreAPI::getSetting('base', 'v1_tables_dropped'));
    }

    public function testDropRemovesEveryV1Table(): void
    {
        $cli = $this->run_(['drop' => true]);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertSame([], $this->present());
        $this->assertTrue((bool) \OWA\Core\CoreAPI::getSetting('base', 'v1_tables_dropped'),
            'recorded, so the migration is not claimed revertible');
    }

    public function testItIsRefusedBeforeTheMigrationHasRun(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'schema_version', 61);

        $before = $this->present();
        $cli = $this->run_(['drop' => true]);

        $this->assertSame('refused', $cli->getCliOutcome()['outcome']);
        $this->assertSame($before, $this->present(), 'nothing dropped');
    }
}
