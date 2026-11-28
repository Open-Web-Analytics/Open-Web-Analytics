<?php

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

require_once __DIR__ . '/PropertyFixtures.php';

/**
 * Fails the run when it leaves a cube with no Property row: a test that
 * removed its Property without owa_test_remove_property().
 *
 * Registered in phpunit.xml, so it runs at the end of every PHPUnit process:
 * the whole suite, and each file of the isolation sweep, which names the
 * file. The cubes already without a row when the run first reaches the
 * database are left out, so a development database's old ones do not fail
 * every run. Without a database it does nothing.
 *
 * The failure is a test runner warning, which fails the run
 * (failOnPhpunitWarning).
 */
final class OrphanCubeCheck implements Extension
{
    /** @var list<string>|null the cubes without a row when the run first reached the database */
    private static ?array $before = null;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        // The test files are loaded by now, and bootstrap_owa.php has booted OWA when one requires it.
        self::snapshot();

        // A test case that boots OWA itself does it in setUpBeforeClass(), before its first test.
        $facade->registerSubscriber(new class implements \PHPUnit\Event\Test\PreparationStartedSubscriber {
            public function notify(\PHPUnit\Event\Test\PreparationStarted $event): void
            {
                OrphanCubeCheck::snapshot();
            }
        });

        $facade->registerSubscriber(new class implements \PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber {
            public function notify(\PHPUnit\Event\TestRunner\ExecutionFinished $event): void
            {
                OrphanCubeCheck::check();
            }
        });
    }

    /** Note the cubes without a row, the first time the database answers. */
    public static function snapshot(): void
    {
        if (self::$before === null && self::databaseAnswers()) {
            self::$before = owa_test_orphan_cubes();
        }
    }

    /** A test runner warning naming each cube the run left without a row. */
    public static function check(): void
    {
        if (!self::databaseAnswers()) {
            return;
        }

        $left = array_values(array_diff(owa_test_orphan_cubes(), self::$before ?? []));

        if ($left) {
            \PHPUnit\Event\Facade::emitter()->testRunnerTriggeredPhpunitWarning(sprintf(
                'The tests left %d %s with no Property row: %s. Remove a test Property with owa_test_remove_property() (tests/PropertyFixtures.php).',
                count($left), count($left) === 1 ? 'cube' : 'cubes', implode(', ', $left)));
        }
    }

    /** Whether OWA has been booted by a test and its database answers. */
    private static function databaseAnswers(): bool
    {
        $booted = false;

        foreach ($GLOBALS as $name => $value) {
            if (is_string($name) && strpos($name, 'owa_test_') === 0 && $value instanceof \owa) {
                $booted = true;
                break;
            }
        }

        if (!$booted) {
            return false;
        }

        set_error_handler(static function () { return true; });

        try {
            $row = \OWA\Core\CoreAPI::dbSingleton()->get_row('SELECT 1 AS ok');

            return is_array($row) && isset($row['ok']) && $row['ok'] == 1;
        } catch (\Throwable $e) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
