<?php

declare(strict_types=1);

use OWA\Core\Controller\Cli;
use OWA\Module\Base\Classes\AutoUpdate\Install;
use OWA\Module\Base\Classes\InstallManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/InstallerHarness.php';
require_once __DIR__ . '/SettingsSingletonSnapshot.php';

/** Every message logged, at any level, debug included whatever OWA_DEBUG says. */
final class CoreInstallRecordingError extends \OWA\Module\Base\Classes\Error
{
    /** @var list<string> */
    public $logged = [];

    public function debug($message, $context = null)
    {
        $this->logged[] = \OWA\Core\Lib::forLog($message) . ($context === null ? '' : ' ' . \OWA\Core\Lib::forLog($context));

        return null;
    }

    public function log($err, $priority = 'notice')
    {
        $this->logged[] = \OWA\Core\Lib::forLog($err);

        return null;
    }
}

/**
 * cmd=core-install, the one install command: a new installation's database,
 * a check and repair of an installed one, or a release's code. Through the
 * recording install manager (InstallerHarness): nothing reaches the database,
 * the data directory or OWA's code.
 *
 *   not installed                    the schema, the admin, the site, the permissions
 *   installed                        permissions, missing tables, schema updates,
 *                                    tracking bundles, in that order; the admin and
 *                                    the sites are not changed
 *   installed, unknown schema state  refused, nothing done; with --force the
 *                                    module's tables moved aside and it installed
 *                                    fresh, Base as a new installation
 *   installed, --version or --file   that release (Install::byHand(), Install::fromFile())
 *   not installed, a release named   refused
 */
final class CoreInstallCliTest extends TestCase
{
    use InstallerHarness;
    use SettingsSingletonSnapshot;

    private const SETUP = [
        'cmd'           => 'core-install',
        'user_id'       => 'owatest-admin',
        'email_address' => 'owatest-admin@example.test',
        'domain'        => 'https://example.test',
    ];

    /** @var CoreInstallRecordingError */
    private $log;

    /** @var \OWA\Module\Base\Classes\Error|null */
    private $savedLog;

    protected function setUp(): void
    {
        $this->snapshotSettings();
        $this->setUpInstaller();
        Cli::$exit_code = 0;

        // Nothing here reaches the database, the time zone check included.
        \OWA\Module\Base\Classes\DatabaseRequirement::assumeTimeZones(true);

        $error          = new ReflectionProperty(\OWA\Core\CoreAPI::class, 'error');
        $this->savedLog = $error->getValue();
        $this->log      = new CoreInstallRecordingError();
        $error->setValue(null, $this->log);

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(\OWA\Core\CoreAPI::class, 'error'))->setValue(null, $this->savedLog);
        Cli::$exit_code = 0;
        \OWA\Module\Base\Classes\DatabaseRequirement::assumeTimeZones(null);
        $this->restoreSettings();
    }

    /** The command run as cli.php runs it: its declared arguments, its rules, then action(). */
    private function runCommand(array $params, ?string $stdin = null): \OWA\Module\Base\Controller\CoreInstallCli
    {
        $cli = $this->coreInstallCli($params, $stdin);
        $cli->doAction();

        return $cli;
    }

    /** Row 1, not installed: the database is set up, and the generated password printed once, last, and never logged. */
    public function testANewInstallationIsSetUpAndItsGeneratedPasswordPrintedOnce(): void
    {
        $cli = $this->runCommand(self::SETUP + ['web-group' => 'owatest-web']);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(0, Cli::$exit_code);
        $this->assertSame([
            'installSchema',
            'finishInstall:begin',
            'createAdminUser:owatest-admin',
            'createDefaultSite:https://example.test',
            'finishInstall:end',
            'announceInstallComplete:owatest-admin:owatest-site',
            'secureDataDir:owatest-web',
        ], InstallerRecording::$calls);

        $password = (string) $this->im->passwords[0];
        $this->assertSame(InstallManager::GENERATED_PASSWORD_LENGTH, strlen($password));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]+$/', $password);
        $this->assertSame(['Admin password for "owatest-admin": ' . $password . '  Save it now; it is not shown again.'], $cli->written);

        $this->assertContains('Install Completed.', $this->log->logged);
        $this->assertNotEmpty($this->log->logged);
        foreach ($this->log->logged as $line) {
            $this->assertStringNotContainsString($password, $line, 'the password was logged');
        }
    }

    /** Missing time zone tables are said, and the install goes on. */
    public function testANewInstallationWarnsOfMissingTimeZoneTables(): void
    {
        \OWA\Module\Base\Classes\DatabaseRequirement::assumeTimeZones(false);

        $cli = $this->runCommand(self::SETUP);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertContains(\OWA\Module\Base\Classes\DatabaseRequirement::timeZoneWarningText(), $this->log->logged);
        $this->assertContains('Install Completed.', $this->log->logged);

        \OWA\Module\Base\Classes\DatabaseRequirement::assumeTimeZones(true);
        $this->log->logged = [];

        $this->runCommand(self::SETUP);

        $this->assertNotContains(\OWA\Module\Base\Classes\DatabaseRequirement::timeZoneWarningText(), $this->log->logged);
    }

    /** Each run generates its own. */
    public function testTheGeneratedPasswordDiffersEachRun(): void
    {
        $this->runCommand(self::SETUP);
        $this->runCommand(self::SETUP);

        $this->assertCount(2, $this->im->passwords);
        $this->assertNotSame($this->im->passwords[0], $this->im->passwords[1]);
    }

    /** --password-stdin: the line read is the password, and nothing is printed. */
    public function testPasswordStdinIsThePassword(): void
    {
        $cli = $this->runCommand(self::SETUP + ['password-stdin' => true], 'owatest-piped-pass');

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(['owatest-piped-pass'], $this->im->passwords);
        $this->assertSame([], $cli->written);
        foreach ($this->log->logged as $line) {
            $this->assertStringNotContainsString('owatest-piped-pass', $line);
        }
    }

    /** One line, its line ending trimmed and nothing else. */
    public function testStandardInputIsReadAsOneLine(): void
    {
        foreach (["pass word \n" => 'pass word ', "crlf-pass\r\nsecond line\n" => 'crlf-pass', 'no-newline' => 'no-newline', '' => ''] as $in => $line) {
            $h = fopen('php://memory', 'w+b');
            fwrite($h, $in);
            rewind($h);
            $this->assertSame($line, \OWA\Module\Base\Controller\CoreInstallCli::firstLine($h), json_encode($in));
            fclose($h);
        }
    }

    /** A password piped in is held to the same rules as anywhere else, named as --password-stdin. */
    public function testAPipedPasswordOutsideTheRulesIsRefused(): void
    {
        foreach ([
            ''                    => '--password-stdin: Password is required.',
            'short'               => '--password-stdin: The password must be at least 6 characters.',
            str_repeat('a', 73)   => '--password-stdin: ',
        ] as $stdin => $says) {
            InstallerRecording::$calls = [];
            Cli::$exit_code = 0;

            $cli = $this->runCommand(self::SETUP + ['password-stdin' => true], (string) $stdin);

            $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code, (string) $stdin);
            $this->assertStringContainsString($says, $cli->getCliOutcome()['message']);
            $this->assertSame([], InstallerRecording::$calls, 'nothing was installed');
        }

        $this->assertStringContainsString('72 bytes', $cli->getCliOutcome()['message']);
    }

    /** password= is refused, naming the two ways to give one, before anything is read or done. */
    public function testAPasswordArgumentIsRefused(): void
    {
        $cli = $this->runCommand(self::SETUP + ['password' => 'owatest-pass']);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame('cmd=core-install: password= is not taken: a password on the command line is seen by other users and kept in shell history. '
            . 'Leave it out to have one generated and printed once, or give --password-stdin and pipe it in. Nothing was done.',
            $cli->getCliOutcome()['message']);
        $this->assertSame([], InstallerRecording::$calls);
        $this->assertSame([], $this->im->passwords);
    }

    /**
     * Each setup argument missing or invalid: the wizard's rule and message
     * (InstallManager::setupRules()), named by its argument, exit 2, nothing done.
     *
     * @dataProvider refusedSetups
     */
    public function testASetupArgumentMissingOrInvalidIsNamed(array $params, string $says): void
    {
        $cli = $this->runCommand($params);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame(['outcome' => 'failed', 'message' => 'cmd=core-install: ' . $says . ' Nothing was done.', 'final' => true], $cli->getCliOutcome());
        $this->assertSame([], InstallerRecording::$calls);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedSetups(): iterable
    {
        $without = static fn (string $name): array => array_diff_key(self::SETUP, [$name => 1]);

        yield 'no user_id'          => [$without('user_id'), 'user_id: A user name for the admin account is required.'];
        yield 'user_id not allowed' => [['user_id' => 'owatest admin'] + self::SETUP, 'user_id: The user name contains characters that are not allowed.'];
        yield 'no email_address'    => [$without('email_address'), 'email_address: An email address for the admin account is required.'];
        yield 'not an email'        => [['email_address' => 'owatest-admin'] + self::SETUP, 'email_address: Enter a valid email address.'];
        yield 'no domain'           => [$without('domain'), 'domain: Site domain is required.'];
        yield 'a domain of nothing' => [['domain' => 'https://'] + self::SETUP, 'domain: Site domain is required.'];
    }

    /** The wizard checks the same rules: InstallBase registers setupRules(), then its timezone. */
    public function testTheWizardChecksTheSameRules(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/modules/Base/Controller/InstallBase.php');

        $this->assertStringContainsString('InstallManager::setupRules(', $source);
        $this->assertSame(1, preg_match_all("/addValidation\\(\\s*'/", $source), 'one rule of its own: the timezone');
    }

    /** The four steps on an installed system, in order. */
    private const STEPS = ['secureDataDir:owatest-web', 'createMissingTables', 'run:CoreUpdateSchemaCli', 'run:TrackerPublishCli'];

    /**
     * Row 2, installed and no release named: the permissions, the missing
     * tables, the schema updates and the tracking bundles, in that order, each
     * outcome said; the admin and the site not changed.
     */
    public function testAnInstalledSystemRunsTheFourStepsInOrder(): void
    {
        $this->im->installed   = true;
        $this->im->permissions = ['/owa-data is now writable by its owner only.'];
        $this->im->created     = ['owa_login_failure', 'owa_spam_block'];

        $cli           = $this->coreInstallCli(['cmd' => 'core-install', 'web-group' => 'owatest-web']);
        $cli->outcomes = ['TrackerPublishCli' => ['outcome' => 'ok', 'message' => '0 published, 1 current, 0 failed, 0 removed', 'final' => false]];
        $cli->doAction();

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(0, Cli::$exit_code);
        $this->assertSame(self::STEPS, InstallerRecording::$calls);
        $this->assertSame([
            'Permissions: /owa-data is now writable by its owner only.',
            'Tables: created owa_login_failure, owa_spam_block',
            'Schema updates: applied',
            'Tracking bundles: 0 published, 1 current, 0 failed, 0 removed',
            'Admin "owatest-admin": already exists, not changed',
            'Site: already exists, not changed',
        ], $cli->written);
    }

    /** Nothing missing and nothing pending: each step says so. */
    public function testEachStepSaysWhenItHadNothingToDo(): void
    {
        $this->im->installed = true;

        $cli          = $this->coreInstallCli(['cmd' => 'core-install', 'web-group' => 'owatest-web']);
        $cli->pending = false;
        $cli->doAction();

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(self::STEPS, InstallerRecording::$calls, 'the updates and the bundles run anyway, as their commands do');
        $this->assertSame(['Permissions: nothing to change', 'Tables: none missing', 'Schema updates: none pending'],
            array_slice($cli->written, 0, 3));
    }

    /** A failed update stops the command, non-zero, naming the update; the bundles are not republished. */
    public function testAFailedUpdateStopsTheRunAndIsNamed(): void
    {
        $this->im->installed = true;

        $cli           = $this->coreInstallCli(['cmd' => 'core-install', 'web-group' => 'owatest-web']);
        $cli->outcomes = ['CoreUpdateSchemaCli' => ['outcome' => 'failed', 'message' => 'The updates failed. See the error log.', 'final' => false]];
        $cli->doAction();

        $this->assertSame(['outcome' => 'failed', 'message' => 'Schema updates: update base.76 did not apply. The updates failed. See the error log.', 'final' => false],
            $cli->getCliOutcome());
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertSame(array_slice(self::STEPS, 0, 3), InstallerRecording::$calls);
    }

    /** A table that cannot be created stops the command before any update runs. */
    public function testATableThatCannotBeCreatedStopsTheRunBeforeTheUpdates(): void
    {
        $this->im->installed  = true;
        $this->im->notCreated = ['owa_job_queue'];

        $cli = $this->runCommand(['cmd' => 'core-install', 'web-group' => 'owatest-web']);

        $this->assertSame('Tables: owa_job_queue could not be created. The error log says why.', $cli->getCliOutcome()['message']);
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertSame(array_slice(self::STEPS, 0, 2), InstallerRecording::$calls);
    }

    /** Bundles that are not published fail the command. */
    public function testBundlesNotPublishedFailTheRun(): void
    {
        $this->im->installed = true;

        $cli           = $this->coreInstallCli(['cmd' => 'core-install', 'web-group' => 'owatest-web']);
        $cli->outcomes = ['TrackerPublishCli' => ['outcome' => 'failed', 'message' => 'Tracker bundle for 1 was not published; see the log.', 'final' => false]];
        $cli->doAction();

        $this->assertSame('Tracking bundles: Tracker bundle for 1 was not published; see the log.', $cli->getCliOutcome()['message']);
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertSame(self::STEPS, InstallerRecording::$calls);
    }

    /** The command runs while updates are pending: applying them is its third step. */
    public function testItRunsWhileUpdatesArePending(): void
    {
        $this->assertFalse($this->coreInstallCli(['cmd' => 'core-install'])->needsCurrentSchema());
    }

    /** Setup arguments given to an installed system are ignored and said to be; standard input is not read. */
    public function testSetupArgumentsOnAnInstalledSystemAreIgnored(): void
    {
        $this->im->installed = true;
        $this->im->admin     = null;
        $this->im->site      = false;

        $cli = $this->runCommand(self::SETUP + ['password-stdin' => true]);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(['secureDataDir:', 'createMissingTables', 'run:CoreUpdateSchemaCli', 'run:TrackerPublishCli'], InstallerRecording::$calls);
        $this->assertSame([], $this->im->passwords);
        $this->assertContains('OWA is already installed, so these arguments are ignored: user_id, email_address, domain, password-stdin.', $this->log->logged);
        $this->assertSame([
            'Admin: there is none, and none was created',
            'Site: there is none, and none was created; add one with cmd=add-site',
        ], array_slice($cli->written, -2));
    }

    /** A module with tables and no recorded schema version: refused before the first step, nothing done. */
    public function testAModuleInAnUnknownStateIsRefusedAndNothingIsDone(): void
    {
        $this->im->installed = true;
        $this->im->unknown   = ['domstream'];

        $cli = $this->runCommand(['cmd' => 'core-install', 'web-group' => 'owatest-web']);

        $this->assertSame(['outcome' => 'failed', 'message' => 'Module domstream has tables but no recorded schema version, so the state of its tables is unknown. '
            . 'Run php cli.php cmd=core-install --force to move them aside and install the module fresh.', 'final' => true], $cli->getCliOutcome());
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertSame([], InstallerRecording::$calls);
        $this->assertSame([], $cli->written);
    }

    /** --force: a module other than Base has its tables moved aside and is installed fresh, each rename said; then the four steps. */
    public function testForceInstallsAModuleInAnUnknownStateFresh(): void
    {
        $this->im->installed = true;
        $this->im->unknown   = ['domstream'];

        $cli = $this->runCommand(['cmd' => 'core-install', 'force' => true, 'web-group' => 'owatest-web']);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(array_merge(['reinstallModule:domstream:20261128120000'], self::STEPS), InstallerRecording::$calls);
        $this->assertSame([
            'Moved aside: owa_domstream_chunk is now owa_domstream_chunk_before_20261128120000',
            'Module domstream: installed fresh',
        ], array_slice($cli->written, 0, 2));
    }

    /** --force: a new name that would collide, or is too long, is refused and nothing more is done. */
    public function testForceStopsWhenATableCannotBeMovedAside(): void
    {
        $this->im->installed   = true;
        $this->im->unknown     = ['domstream'];
        $this->im->moveProblem = 'owa_domstream_chunk cannot be moved aside: owa_domstream_chunk_before_20261128120000 exists. Nothing was renamed.';

        $cli = $this->runCommand(['cmd' => 'core-install', 'force' => true]);

        $this->assertSame($this->im->moveProblem, $cli->getCliOutcome()['message']);
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertSame(['reinstallModule:domstream:20261128120000'], InstallerRecording::$calls);
        $this->assertSame([], $cli->written);
    }

    /** --force with Base in an unknown state is a new installation: the setup arguments are required, and nothing is done without them. */
    public function testForcedBaseNeedsTheSetupArguments(): void
    {
        $this->im->installed = true;
        $this->im->unknown   = ['base'];

        $cli = $this->runCommand(['cmd' => 'core-install', 'force' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('user_id: A user name for the admin account is required.', $cli->getCliOutcome()['message']);
        $this->assertSame([], InstallerRecording::$calls);
    }

    /** --force with Base in an unknown state and the setup arguments: every module's tables moved aside, a new installation, the other modules installed fresh. */
    public function testForcedBaseIsANewInstallation(): void
    {
        $this->im->installed = true;
        $this->im->unknown   = ['base'];
        $this->im->others    = ['domstream'];

        $cli = $this->runCommand(self::SETUP + ['force' => true, 'web-group' => 'owatest-web']);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame([
            'moveAsideForNewInstall:20261128120000',
            'installSchema',
            'finishInstall:begin',
            'createAdminUser:owatest-admin',
            'createDefaultSite:https://example.test',
            'finishInstall:end',
            'announceInstallComplete:owatest-admin:owatest-site',
            'secureDataDir:owatest-web',
            'reinstallModule:domstream:20261128120000',
        ], InstallerRecording::$calls);
        $this->assertSame('Moved aside: owa_site is now owa_site_before_20261128120000', $cli->written[0]);
        $this->assertContains('Module domstream: installed fresh', $cli->written);
    }

    /** --force with no module in an unknown state: says so, and runs the four steps as without it. */
    public function testForceWithNothingUnknownChangesNothingMore(): void
    {
        $this->im->installed = true;

        $cli = $this->runCommand(['cmd' => 'core-install', 'force' => true, 'web-group' => 'owatest-web']);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame(self::STEPS, InstallerRecording::$calls);
        $this->assertSame('--force: no module has tables without a recorded schema version, so nothing more is done.', $cli->written[0]);
    }

    /**
     * A fresh install that stopped part way: some of Base's tables, no version,
     * no install_complete. Run again with the setup arguments it carries on,
     * as the wizard's re-submit does: InstallManager::unknownStateModules()
     * names no module before the install is complete.
     */
    public function testAPartialFreshInstallCarriesOnWhenRunAgain(): void
    {
        $base = new class extends \OWA\Core\Module {
            const NAME = 'base';

            protected function installComplete()
            {
                return false;
            }

            public function existingTables()
            {
                return ['owa_site', 'owa_setting'];
            }
        };

        $this->im = new class extends RecordingInstallManager {
            public function unknownStateModules()
            {
                return (new ReflectionMethod(InstallManager::class, 'unknownStateModules'))->invoke($this);
            }
        };

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $saved   = $service->modules;

        try {
            $service->modules = ['base' => $base];

            $cli = $this->runCommand(self::SETUP + ['web-group' => 'owatest-web']);
        } finally {
            $service->modules = $saved;
        }

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame([
            'installSchema',
            'finishInstall:begin',
            'createAdminUser:owatest-admin',
            'createDefaultSite:https://example.test',
            'finishInstall:end',
            'announceInstallComplete:owatest-admin:owatest-site',
            'secureDataDir:owatest-web',
        ], InstallerRecording::$calls);
    }

    /** Row 3, installed with --version or --file: that release installs; the database is not touched. */
    public function testAnInstalledSystemInstallsTheReleaseNamed(): void
    {
        $this->im->installed = true;

        $cli = $this->runCommand(['cmd' => 'core-install', 'version' => '2.0.3', 'force' => true]);
        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame([['version', '2.0.3', true]], $cli->releases);

        $cli = $this->runCommand(['cmd' => 'core-install', 'file' => '/tmp/owa_2.0.3_packaged.tar', 'sig' => '/tmp/owa.sig']);
        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertSame([['file', '/tmp/owa_2.0.3_packaged.tar', '/tmp/owa.sig', false]], $cli->releases);

        $cli = $this->runCommand(['cmd' => 'core-install', 'file' => '/tmp/owa_2.0.3_packaged.tar']);
        $this->assertSame([['file', '/tmp/owa_2.0.3_packaged.tar', null, false]], $cli->releases, 'the signature beside it');

        $this->assertSame([], InstallerRecording::$calls);
    }

    /** A release that does not install fails; only another install running is retried. */
    public function testAReleaseThatDoesNotInstallFails(): void
    {
        $this->im->installed = true;

        foreach ([Install::REFUSED => true, Install::FAILED => true, Install::BUSY => false] as $answer => $final) {
            Cli::$exit_code = 0;
            $cli         = $this->coreInstallCli(['cmd' => 'core-install', 'version' => '2.0.3']);
            $cli->answer = $answer;
            $cli->doAction();

            $this->assertSame(['outcome' => 'failed', 'message' => 'answered ' . $answer, 'final' => $final], $cli->getCliOutcome());
            $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        }
    }

    /** Row 4, not installed with --version or --file: refused, nothing done, and no setup argument asked for. */
    public function testAReleaseBeforeTheInstallIsRefused(): void
    {
        foreach ([['version' => '2.0.3'], ['file' => '/tmp/owa_2.0.3_packaged.tar']] as $release) {
            Cli::$exit_code = 0;

            $cli = $this->runCommand(['cmd' => 'core-install'] + $release);

            $this->assertSame(['outcome' => 'failed', 'message' => 'OWA is not installed yet. Run core-install with the admin and site arguments first.', 'final' => true],
                $cli->getCliOutcome());
            $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
            $this->assertSame([], $cli->releases);
            $this->assertSame([], InstallerRecording::$calls);
        }
    }

    /** Arguments that do not go together: a usage error, whatever is installed. */
    public function testArgumentsThatDoNotGoTogetherAreRefused(): void
    {
        $this->im->installed = true;

        foreach ([
            'Give --version or --file, not both.'      => ['version' => '2.0.3', 'file' => '/tmp/a.tar'],
            '--sig goes with --file.'                  => ['version' => '2.0.3', 'sig' => '/tmp/a.sig'],
        ] as $says => $params) {
            Cli::$exit_code = 0;

            $cli = $this->runCommand(['cmd' => 'core-install'] + $params);

            $this->assertSame('cmd=core-install: ' . $says . ' Nothing was done.', $cli->getCliOutcome()['message']);
            $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
            $this->assertSame([], $cli->releases);
            $this->assertSame([], InstallerRecording::$calls);
        }
    }

    /** An install that did not complete fails, and its generated password, never used, is not printed. */
    public function testAnInstallThatDidNotCompletePrintsNoPassword(): void
    {
        $this->im->commits = false;

        $cli = $this->runCommand(self::SETUP);

        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertSame([], $cli->written);
    }

    /** cmd=install is gone; cli.php names core-install. */
    public function testInstallIsNowCoreInstall(): void
    {
        $this->assertSame('core-install', Cli::RENAMED['install']);
        $this->assertFalse(class_exists('OWA\\Module\\Base\\Controller\\InstallCli'));
        $this->assertArrayNotHasKey('install', (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap('cli_commands'));
    }
}
