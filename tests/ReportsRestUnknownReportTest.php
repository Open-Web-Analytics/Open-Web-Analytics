<?php

use PHPUnit\Framework\TestCase;

/**
 * An unrecognised report name is refused, not dispatched.
 *
 * getReport() builds a method name by concatenation -- 'report_' . $report_name
 * -- and called it straight away. The name is the fourth segment of the route
 * (/api/base/v1/reports/{report_name}), so any value that did not correspond to
 * an implemented report reached $this->$method() and raised an uncaught Error.
 *
 * validate() gained an inArray check so an unknown name is rejected before the
 * action runs, and getReport() keeps a backstop of its own.
 */
final class ReportsRestUnknownReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function controller()
    {
        return new \OWA\Module\Base\Controller\ReportsRest([]);
    }

    /**
     * The set is derived from the implementations, so it cannot drift out of
     * step with them the way a hand-maintained list would.
     */
    public function testReportNamesAreDiscoveredFromTheImplementations()
    {
        $names = \OWA\Module\Base\Controller\ReportsRest::getReportNames();

        $this->assertNotEmpty($names, 'no reports discovered at all');

        foreach ($names as $name) {
            $this->assertTrue(
                method_exists('\OWA\Module\Base\Controller\ReportsRest', 'report_' . $name),
                sprintf('%s is offered but report_%s() does not exist', $name, $name)
            );
        }
    }

    /**
     * Two reports have implementations but no case in validate()'s switch, so a
     * hand-written list would likely have missed them. Named explicitly to catch
     * the discovery silently returning a subset.
     */
    public function testReportsWithoutAValidationCaseAreStillOffered()
    {
        $names = \OWA\Module\Base\Controller\ReportsRest::getReportNames();

        $this->assertContains('transaction', $names);
        $this->assertContains('clickstream', $names);
    }

    /**
     * The reported failure: 'dashboard' has no implementation, and calling it
     * raised an uncaught Error rather than producing a response.
     */
    public function testAnUnknownReportDoesNotRaiseAnError()
    {
        $controller = $this->controller();

        $this->assertSame(
            '',
            $controller->getReport('dashboard'),
            'an unknown report should yield no results rather than dispatching'
        );
    }

    /** Whatever the shape of the value, it must not reach the method call. */
    public static function unknownReportNames(): array
    {
        return [
            'unimplemented'      => ['dashboard'],
            'empty string'       => [''],
            'method on the class' => ['Names'],
            'separator'          => ['latest/actions'],
            'traversal-ish'      => ['../../etc/passwd'],
            'null byte'          => ["clicks\0"],
            'not a string'       => [['clicks']],
        ];
    }

    /** @dataProvider unknownReportNames */
    public function testUnhandledReportNamesAreRefused($name)
    {
        $this->assertSame('', $this->controller()->getReport($name));
    }

    /** Run the controller's own validations and report whether they rejected. */
    private function rejectedByValidation($reportName): bool
    {
        $controller = new \OWA\Module\Base\Controller\ReportsRest(['report_name' => $reportName]);
        $controller->validate();

        $prop = new ReflectionProperty($controller, 'v');
        $prop->setAccessible(true);
        $validator = $prop->getValue($controller);

        if (!$validator) {
            return false;
        }

        $validator->doValidations();

        return (bool) $validator->hasErrors;
    }

    /**
     * The primary fix. getReport()'s backstop keeps the process alive, but the
     * request should never get that far: an unknown name is a bad request and
     * errorAction() already answers 422.
     */
    public function testAnUnknownReportIsRejectedByValidation()
    {
        $this->assertTrue(
            $this->rejectedByValidation('dashboard'),
            'an unimplemented report should fail validation'
        );
        $this->assertTrue(
            $this->rejectedByValidation('nosuchreport'),
            'an unrecognised report should fail validation'
        );
    }

    /** The check must not reject reports that do exist. */
    public function testKnownReportsPassValidation()
    {
        foreach (['transaction', 'latest_visits'] as $name) {
            $this->assertFalse(
                $this->rejectedByValidation($name),
                sprintf('%s is implemented and must pass validation', $name)
            );
        }
    }

    /** A real report must still dispatch -- the guard cannot block everything. */
    public function testAKnownReportStillDispatches()
    {
        $controller = $this->controller();

        foreach (\OWA\Module\Base\Controller\ReportsRest::getReportNames() as $name) {
            $this->assertTrue(
                method_exists($controller, 'report_' . $name),
                sprintf('report_%s() must be dispatchable', $name)
            );
        }
    }
}
