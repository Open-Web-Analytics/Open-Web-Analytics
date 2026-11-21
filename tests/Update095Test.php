<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update095: severity, email_status and emailed_at on owa_notification.
 */
final class Update095Test extends TestCase
{
    private \OWA\Module\Base\Update\Update095 $update;

    protected function setUp(): void
    {
        $this->update = new \OWA\Module\Base\Update\Update095();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->update->up();
        }
    }

    /** @return string[] the three columns the table has, in table order */
    private function columns(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SHOW COLUMNS FROM owa_notification') as $row) {
            $field = ((array) $row)['Field'];

            if (in_array($field, \OWA\Module\Base\Update\Update095::COLUMNS, true)) {
                $out[] = $field;
            }
        }

        return $out;
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(95, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(95, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** The entity declares the same three, last, in the order ADD COLUMN appends them. */
    public function testTheEntityDeclaresThem(): void
    {
        $columns = \OWA\Core\CoreAPI::entityFactory('base.notification')->getColumns();

        $this->assertSame(\OWA\Module\Base\Update\Update095::COLUMNS, array_slice($columns, -3));
    }

    public function testUpDownAndAgain(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters owa_notification');
        }

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'up is idempotent');
        $this->assertSame(['severity', 'email_status', 'emailed_at'], $this->columns());

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent');
        $this->assertSame([], $this->columns());

        $this->assertTrue($this->update->up());
        $this->assertSame(['severity', 'email_status', 'emailed_at'], $this->columns());
    }
}
