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
            'Aborting UpdateAbortNoticeTestUpdate Update (zz_abort_test): the schema is at 7, so update 7 has been applied.', $log);
    }

    /** An update with a schema recorded at this version. */
    private function at(int $current, int $seq): UpdateAbortNoticeTestUpdate
    {
        $u = new UpdateAbortNoticeTestUpdate();
        $u->module_name = 'zz_abort_test';
        $u->schema_version = $seq;
        $u->c = new class($current) {
            public $persisted = [];
            function __construct(private int $current) {}
            function get($module, $key) { return $this->current; }
            function persistSetting($module, $key, $value) { $this->persisted[$module][$key] = $value; }
            function save() { return true; }
        };

        return $u;
    }

    /** apply=base.N with the schema past N: running it again would re-run it over every later update. */
    public function testAnEarlierUpdateIsRefused(): void
    {
        $u = $this->at(60, 50);

        $log = $this->noticesDuring(function () use ($u) {
            $this->assertFalse($u->apply());
        });

        $this->assertStringContainsString('the schema is at 60, so update 50 has been applied. Use --force to apply it again.', $log);
        $this->assertSame([], $u->c->persisted);
    }

    /** An update further ahead than the next one would skip the ones between. */
    public function testAnUpdateAheadOfTheNextIsRefused(): void
    {
        $u = $this->at(60, 62);

        $log = $this->noticesDuring(function () use ($u) {
            $this->assertFalse($u->apply());
        });

        $this->assertStringContainsString('the schema is at 60, so update 61 is next, not 62. Use --force to apply it out of sequence.', $log);
        $this->assertSame([], $u->c->persisted);
    }

    public function testTheNextUpdateApplies(): void
    {
        $u = $this->at(60, 61);

        $this->assertTrue($u->apply());
        $this->assertSame(61, $u->c->persisted['zz_abort_test']['schema_version']);
    }

    public function testForceAppliesAnyUpdate(): void
    {
        $u = $this->at(60, 50);

        $this->assertTrue($u->apply(true));
        $this->assertSame(50, $u->c->persisted['zz_abort_test']['schema_version']);
    }
}
