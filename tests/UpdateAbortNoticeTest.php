<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** A concrete update, named, so its notices can be matched. */
class UpdateAbortNoticeTestUpdate extends \OWA\Core\Update {

    function up( $force = false ) {
        return true;
    }
}

/**
 * An update that refuses to run names itself, the concrete class, in its
 * notice.
 */
final class UpdateAbortNoticeTest extends TestCase
{
    private function noticesDuring(callable $fn): string
    {
        $e = \OWA\Core\CoreAPI::errorSingleton();
        $p = new ReflectionProperty($e, 'sinks');
        $was = $p->getValue($e);
        $stream = fopen('php://memory', 'w+');
        $p->setValue($e, [['path' => null, 'stream' => $stream]]);
        try {
            $fn();
        } finally {
            $p->setValue($e, $was);
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testAnUpdateWithNoSchemaVersionNamesItsOwnClass(): void
    {
        $u = new UpdateAbortNoticeTestUpdate();
        $u->module_name = 'zz_abort_test';

        $log = $this->noticesDuring(function () use ($u) {
            $this->assertFalse($u->apply());
        });

        $this->assertStringContainsString(
            'Aborting UpdateAbortNoticeTestUpdate Update (zz_abort_test): Schema Version Number is not set.', $log);
    }

    public function testAnUpdateAlreadyAppliedNamesItsOwnClass(): void
    {
        $u = new UpdateAbortNoticeTestUpdate();
        $u->module_name = 'zz_abort_test';
        $u->schema_version = 7;
        $u->c = new class {
            function get($module, $key) {
                return 7;
            }
        };

        $log = $this->noticesDuring(function () use ($u) {
            $this->assertFalse($u->apply());
        });

        $this->assertStringContainsString(
            'Aborting UpdateAbortNoticeTestUpdate Update (zz_abort_test): Update has already been applied.', $log);
    }
}
