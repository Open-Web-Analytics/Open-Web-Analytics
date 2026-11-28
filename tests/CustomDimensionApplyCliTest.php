<?php

require_once __DIR__ . '/CliControllerTestCase.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * cmd=custom-dimension-apply's summary. The reconcile itself is
 * CustomDimensionBuildTest's.
 */
final class CustomDimensionApplyCliTest extends CliControllerTestCase
{
    use CapturesLog;

    protected function setUp(): void
    {
        parent::setUp();

        \OWA\Core\CoreAPI::entityFactory('base.job_lock')->createTable();
    }

    /**
     * A cube whose lock is held is counted as busy, not as matching its
     * registry, and the run exits 0: the build holding the lock reconciles
     * before it builds.
     */
    public function testABusyCubeIsCountedSeparately(): void
    {
        $table = 'owa_test_busy_cube_' . $this->tok;
        $build = new \OWA\Module\Base\Classes\JobLease('cube-build:' . $table);

        $this->assertTrue($build->acquire(600));

        $apply = new class([]) extends \OWA\Module\Base\Controller\CustomDimensionApplyCli {
            public static $table;
            protected function cubesToApply($only) { return ['1' => self::$table]; }
        };
        $apply::$table = $table;

        try {
            $log = $this->logDuring(fn () => $apply->doAction());
        } finally {
            $build->release();
        }

        $this->assertStringContainsString($table . ' is busy; its build will apply these.', $log);
        $this->assertStringContainsString('1 cube busy: its build will apply the columns. 0 changed.', $log);
        $this->assertStringNotContainsString('already matches its registry', $log);
        $this->assertSame('ok', $apply->getCliOutcome()['outcome']);
        $this->assertSame(0, \OWA\Core\Controller\Cli::$exit_code);
    }
}
