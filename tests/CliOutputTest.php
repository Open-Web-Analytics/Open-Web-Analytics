<?php

require_once(__DIR__ . '/CliControllerTestCase.php');

/**
 * What change-password, add-site and core-install print, run as a person runs them:
 * through notice(), with cli.php's exit code. Each refused case is refused
 * before the command acts.
 */
final class CliOutputTest extends CliControllerTestCase
{
    /** @return array{code:int, out:string} stdout and stderr together */
    private function cli(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/cli.php');

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $proc = proc_open($cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        $out  = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['code' => proc_close($proc), 'out' => $out];
    }

    public function testChangePasswordWithoutAUserSaysWhyAndExits2(): void
    {
        $r = $this->cli('cmd=change-password', 'password=owa-cli-output-pass');

        $this->assertSame(2, $r['code'], $r['out']);
        $this->assertStringContainsString('cmd=change-password: user=<user id> is required. Nothing was done.', $r['out']);
    }

    /** A value the argument does not take is named, with what it takes, and nothing is done. */
    public function testAValueAnArgumentDoesNotTakeSaysWhatItTakesAndExits2(): void
    {
        $r = $this->cli('cmd=partition-rotate', 'months-ahead=abc');

        $this->assertSame(2, $r['code'], $r['out']);
        $this->assertStringContainsString('cmd=partition-rotate: months-ahead=abc: Must be a whole number of at least 1. Nothing was done.', $r['out']);
    }

    public function testAddSiteWithoutANameSaysWhyAndExits2(): void
    {
        $r = $this->cli('cmd=add-site', 'domain=https://owatest-cli-output-' . $this->tok . '.example.com');

        $this->assertSame(2, $r['code'], $r['out']);
        $this->assertStringContainsString('Command failed. There were some errors:', $r['out']);
        $this->assertStringContainsString('Name is required.', $r['out']);
    }

    /** cmd=install is cmd=core-install now: cli.php says so and runs nothing. */
    public function testInstallSaysItIsNowCoreInstallAndExits2(): void
    {
        $r = $this->cli('cmd=install', 'user_id=owatest-admin');

        $this->assertSame(2, $r['code'], $r['out']);
        $this->assertStringContainsString('cmd=install is now cmd=core-install.', $r['out']);
        $this->assertStringNotContainsString('Schema:', $r['out']);
    }

    /** A password on the command line is refused, naming the two ways to give one, before anything is done. */
    public function testCoreInstallRefusesAPasswordArgumentAndExits2(): void
    {
        $r = $this->cli('cmd=core-install', 'password=owa-cli-output-pass');

        $this->assertSame(2, $r['code'], $r['out']);
        $this->assertStringContainsString('cmd=core-install: password= is not taken: a password on the command line is seen by other users and kept in shell history. '
            . 'Leave it out to have one generated and printed once, or give --password-stdin and pipe it in. Nothing was done.', $r['out']);
        $this->assertStringNotContainsString('Schema:', $r['out']);
    }

    public function testAddSiteSaysWhatItAdded(): void
    {
        $domain = 'owatest-cli-output-' . $this->tok . '.example.com';

        $r = $this->cli('cmd=add-site', 'domain=https://' . $domain, 'name=CLI output ' . $this->tok);

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($domain, 'domain');
        $this->trackForCleanup('base.site', $site->get('id'), 'id');
        $this->trackForCleanup('base.property', $site->get('property_id'), 'id');

        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('Site added successfully.', $r['out']);
        $this->assertStringContainsString('"site_id": "' . $site->get('site_id') . '"', $r['out']);
    }
}
