<?php

use PHPUnit\Framework\TestCase;

/**
 * How cli.php reads its command line.
 *
 * Three spellings are accepted, and the reason is worth stating: a switch and a
 * value want different shapes. Nobody writes `dry-run=0` -- they leave it off --
 * so a switch has to mean true by its presence. Values still need `key=value`.
 *
 * The third form exists only for compatibility. `--force=1` used to store a
 * parameter whose NAME was literally `--force`, dashes included, because the
 * parser kept whatever sat left of the '='. UpdatesApplyCli read it back that
 * way. Anything relying on that now reads the undashed name.
 */
final class CliArgumentParsingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @param string[] $args */
    private function parse(array $args)
    {
        return \OWA\Core\Lib::parseCliArgs(array_merge(['cli.php'], $args));
    }

    /** A switch is true by its presence. */
    public function testSwitchesAreTrue()
    {
        $this->assertSame(
            ['cmd' => 'partition-rotate', 'dry-run' => true],
            $this->parse(['cmd=partition-rotate', '--dry-run'])
        );

        $this->assertSame(
            ['cmd' => 'update', 'listpending' => true, 'force' => true],
            $this->parse(['cmd=update', '--listpending', '--force'])
        );
    }

    /** Values work with or without the dashes. */
    public function testValuesInBothSpellings()
    {
        $this->assertSame(
            ['cmd' => 'partition-rotate', 'keep' => '24'],
            $this->parse(['cmd=partition-rotate', 'keep=24'])
        );

        $this->assertSame(
            ['cmd' => 'partition-rotate', 'keep' => '24'],
            $this->parse(['cmd=partition-rotate', '--keep=24'])
        );
    }

    /**
     * The compatibility case: `--force=1` must arrive as `force`, since that is
     * how invocations in the wild are written and how the controller reads it.
     */
    public function testTheLegacyDashedValueFormStillWorks()
    {
        foreach ([['--force'], ['--force=1'], ['force=1']] as $spelling) {

            $parsed = $this->parse(array_merge(['cmd=update'], $spelling));

            $this->assertArrayHasKey('force', $parsed, implode(' ', $spelling) . ' should set force');
            $this->assertArrayNotHasKey('--force', $parsed, 'the dashes must not survive into the name');
            $this->assertNotEmpty($parsed['force']);
        }
    }

    /** A value may contain an '=' without being split at the wrong place. */
    public function testValuesMayContainEquals()
    {
        $this->assertSame(
            ['cmd' => 'add-site', 'domain' => 'https://example.com/?a=b'],
            $this->parse(['cmd=add-site', 'domain=https://example.com/?a=b'])
        );
    }

    /**
     * A bare word is still an error. It is almost always a value whose key was
     * forgotten, and guessing at intent there would be worse than refusing.
     */
    public function testABareWordIsRefused()
    {
        foreach (['bogus', '-x', '--', '=novalue'] as $bad) {

            $result = $this->parse(['cmd=update', $bad]);

            $this->assertIsString($result, var_export($bad, true) . ' should be refused');
            $this->assertStringContainsString('key=value', $result, 'the message should name the accepted forms');
        }
    }

    /** No arguments is an empty map, not an error -- cli.php checks that itself. */
    public function testNoArgumentsIsEmpty()
    {
        $this->assertSame([], $this->parse([]));
    }
}
