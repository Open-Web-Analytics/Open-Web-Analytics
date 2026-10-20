<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\AppPasswords;

/**
 * cmd=app-password-create, cmd=app-password-list and cmd=app-password-revoke.
 *
 * What a command writes to standard output is captured by overriding write();
 * what it reports through notice() lands in OWA's log, which is where a token
 * must never go.
 */
final class AppPasswordCliTest extends CliControllerTestCase
{
    /** @var string[] users whose passwords to delete */
    private $users = array();

    protected function tearDown(): void
    {
        foreach ($this->users as $user_id) {
            AppPasswords::revokeAllForUser($user_id);
        }

        parent::tearDown();
    }

    private function user(string $label = 'apppw'): string
    {
        $user = $this->makeUser('viewer', $label);
        $this->users[] = $user['user_id'];

        return $user['user_id'];
    }

    /**
     * Run a command, keeping what it wrote to standard output.
     *
     * @return array{0: \OWA\Core\Controller\Cli, 1: string[]}
     */
    private function run_(string $class, array $params): array
    {
        $map = array(
            \OWA\Module\Base\Controller\AppPasswordCreateCli::class => AppPasswordCreateCliCapture::class,
            \OWA\Module\Base\Controller\AppPasswordListCli::class   => AppPasswordListCliCapture::class,
            \OWA\Module\Base\Controller\AppPasswordRevokeCli::class => AppPasswordRevokeCliCapture::class,
        );

        $cli = new $map[$class]($params);
        $cli->action();

        return array($cli, $cli->written);
    }

    private function create(array $params): array
    {
        return $this->run_(\OWA\Module\Base\Controller\AppPasswordCreateCli::class, $params);
    }

    public function testTheCommandsAreRegistered(): void
    {
        $this->assertSame('base.appPasswordCreateCli', $this->commandClass('app-password-create'));
        $this->assertSame('base.appPasswordListCli', $this->commandClass('app-password-list'));
        $this->assertSame('base.appPasswordRevokeCli', $this->commandClass('app-password-revoke'));
    }

    public function testCreatePrintsTheTokenAndDefaultsToReadOnly(): void
    {
        $user_id = $this->user();

        list($cli, $out) = $this->create(array('user' => $user_id, 'name' => 'Nightly export'));

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);

        $token = end($out);
        $this->assertTrue(AppPasswords::looksLikeToken($token), 'The last line is the token.');

        $row = AppPasswords::verify($token);
        $this->assertSame($user_id, $row['user_id']);
        $this->assertSame('read', $row['scope']);
        $this->assertSame('Nightly export', $row['name']);
    }

    /** notice() appends to the log file; the token goes to standard output only. */
    public function testTheTokenIsNotWrittenToTheLog(): void
    {
        $log = (string) \OWA\Core\CoreAPI::getSetting('base', 'error_log_file');
        $this->assertNotSame('', $log);

        $before = is_file($log) ? filesize($log) : 0;

        list(, $out) = $this->create(array('user' => $this->user(), 'name' => 'Deploy hook', 'scope' => 'full'));

        clearstatcache();
        $logged = (string) file_get_contents($log, false, null, $before);
        $token  = end($out);

        $this->assertStringContainsString('Application password ' . substr($token, 0, 12), $logged,
            'The creation is recorded in the log.');
        $this->assertStringNotContainsString(substr($token, 12), $logged);
    }

    /** @dataProvider refusedCreates */
    public function testCreateRefuses(array $params): void
    {
        $user_id = $this->user();
        $params  = array_map(fn($v) => $v === '{user}' ? $user_id : $v, $params);

        list($cli, $out) = $this->create($params);

        $this->assertSame('refused', $cli->getCliOutcome()['outcome']);
        $this->assertSame(array(), $out, 'Nothing printed as if a token.');
        $this->assertSame(array(), AppPasswords::forUser($user_id));
    }

    public static function refusedCreates(): array
    {
        return array(
            'no user'      => array(array('name' => 'x')),
            'unknown user' => array(array('user' => 'nobody-' . uniqid() . '@owatest.example.com', 'name' => 'x')),
            'no name'      => array(array('user' => '{user}')),
            'bad scope'    => array(array('user' => '{user}', 'name' => 'x', 'scope' => 'admin')),
        );
    }

    public function testListShowsTheIdAndNoSecret(): void
    {
        $user_id = $this->user();
        $made    = AppPasswords::create($user_id, 'Export script', AppPasswords::SCOPE_FULL);

        list(, $out) = $this->run_(\OWA\Module\Base\Controller\AppPasswordListCli::class, array('user' => $user_id));

        $text = implode("\n", $out);

        $this->assertStringContainsString($made['lookup_id'], $text);
        $this->assertStringContainsString('Export script', $text);
        $this->assertStringContainsString('never', $text);
        $this->assertStringNotContainsString(substr($made['token'], 12), $text);
    }

    public function testRevokeOne(): void
    {
        $user_id = $this->user();
        $gone    = AppPasswords::create($user_id, 'Old', AppPasswords::SCOPE_FULL);
        $kept    = AppPasswords::create($user_id, 'New', AppPasswords::SCOPE_FULL);

        list($cli) = $this->run_(\OWA\Module\Base\Controller\AppPasswordRevokeCli::class,
            array('user' => $user_id, 'id' => $gone['lookup_id']));

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertNull(AppPasswords::verify($gone['token']));
        $this->assertNotNull(AppPasswords::verify($kept['token']));
    }

    public function testRevokeNeedsTheOwner(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $made  = AppPasswords::create($owner, 'Theirs', AppPasswords::SCOPE_FULL);

        list($cli) = $this->run_(\OWA\Module\Base\Controller\AppPasswordRevokeCli::class,
            array('user' => $other, 'id' => $made['lookup_id']));

        $this->assertSame('refused', $cli->getCliOutcome()['outcome']);
        $this->assertNotNull(AppPasswords::verify($made['token']));
    }

    public function testRevokeAll(): void
    {
        $user_id = $this->user();
        AppPasswords::create($user_id, 'One', AppPasswords::SCOPE_FULL);
        AppPasswords::create($user_id, 'Two', AppPasswords::SCOPE_READ);

        list($cli) = $this->run_(\OWA\Module\Base\Controller\AppPasswordRevokeCli::class,
            array('user' => $user_id, 'all' => true));

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertSame(array(), AppPasswords::forUser($user_id));
    }

    public function testRevokeWithNeitherIdNorAllIsRefused(): void
    {
        $user_id = $this->user();
        $made    = AppPasswords::create($user_id, 'Kept', AppPasswords::SCOPE_FULL);

        list($cli) = $this->run_(\OWA\Module\Base\Controller\AppPasswordRevokeCli::class, array('user' => $user_id));

        $this->assertSame('refused', $cli->getCliOutcome()['outcome']);
        $this->assertNotNull(AppPasswords::verify($made['token']));
    }

    public function testTheCommandsNeedEditUsers(): void
    {
        foreach (array('AppPasswordCreateCli', 'AppPasswordListCli', 'AppPasswordRevokeCli') as $name) {
            $class = '\\OWA\\Module\\Base\\Controller\\' . $name;
            $this->assertSame('edit_users', (new $class(array()))->getRequiredCapability(), $name);
        }
    }
}

/** What a command wrote to standard output, kept instead of printed. */
trait CapturesCliOutput
{
    public $written = array();

    protected function write($lines)
    {
        foreach ((array) $lines as $line) {
            $this->written[] = $line;
        }
    }
}

class AppPasswordCreateCliCapture extends \OWA\Module\Base\Controller\AppPasswordCreateCli { use CapturesCliOutput; }
class AppPasswordListCliCapture extends \OWA\Module\Base\Controller\AppPasswordListCli { use CapturesCliOutput; }
class AppPasswordRevokeCliCapture extends \OWA\Module\Base\Controller\AppPasswordRevokeCli { use CapturesCliOutput; }
