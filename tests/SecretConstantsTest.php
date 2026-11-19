<?php

use OWA\Module\Base\Classes\Settings;
use PHPUnit\Framework\TestCase;

/**
 * OWA_NONCE_KEY, OWA_NONCE_SALT, OWA_AUTH_KEY and OWA_AUTH_SALT: a controller
 * does not run while owa-config.php leaves one undefined, shorter than 32
 * characters, or the owa-config-dist.php placeholder. The installer and
 * reset-secrets run regardless.
 */
final class SecretConstantsTest extends TestCase
{
    private const NAMES = ['OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @return array<string, string> four values the installer could have written */
    private static function installed(): array
    {
        $values = [];

        foreach (self::NAMES as $name) {
            $values[$name] = \OWA\Core\CoreAPI::secureRandomString(64);
        }

        return $values;
    }

    public function testValuesTheInstallerWritesAreAccepted(): void
    {
        $this->assertSame([], Settings::unusableSecretConstants(self::installed()));
    }

    public function testThirtyTwoCharactersAreAccepted(): void
    {
        $values = self::installed();
        $values['OWA_AUTH_KEY'] = str_repeat('a', 32);

        $this->assertSame([], Settings::unusableSecretConstants($values));
    }

    /** @return array<string, array{mixed}> */
    public static function unusable(): array
    {
        return [
            'empty'                    => [''],
            'spaces'                   => [str_repeat(' ', 40)],
            'thirty-one characters'    => [str_repeat('a', 31)],
            'the dist placeholder'     => ['yourauthkeygoeshere'],
            'a long placeholder'       => ['your' . str_repeat('x', 40) . 'goeshere'],
            'not a string'             => [12345678901234567890123456789012],
            'undefined'                => [null],
        ];
    }

    /** @dataProvider unusable */
    public function testAnUnusableValueIsNamed($value): void
    {
        $values = self::installed();

        if ($value === null) {
            unset($values['OWA_AUTH_KEY']);
        } else {
            $values['OWA_AUTH_KEY'] = $value;
        }

        $this->assertSame(['OWA_AUTH_KEY'], Settings::unusableSecretConstants($values));
    }

    public function testEveryPlaceholderFromTheDistFileIsNamed(): void
    {
        $dist = (string) file_get_contents(dirname(__DIR__) . '/owa-config-dist.php');

        preg_match_all("/define\\('(OWA_(?:NONCE|AUTH)_(?:KEY|SALT))', '([^']*)'\\)/", $dist, $m);

        $this->assertSame(self::NAMES, $m[1], 'owa-config-dist.php defines the four');

        $this->assertSame(self::NAMES, Settings::unusableSecretConstants(array_combine($m[1], $m[2])));
    }

    public function testAControllerAnswersWithTheConstantsAndDoesNotRun(): void
    {
        $this->skipWhileInstalling();

        $controller = self::controller(['OWA_NONCE_SALT', 'OWA_AUTH_KEY']);

        $data = $this->inMode('web_app', fn () => $controller->doAction());

        $this->assertFalse($controller->ran, 'the action did not run');
        $this->assertSame('base.error', $data['view']);
        $this->assertStringContainsString('OWA_NONCE_SALT, OWA_AUTH_KEY', $data['error_msg']);
        $this->assertStringContainsString('64 random hex characters', $data['error_msg']);
    }

    public function testOnTheCommandLineTheMessageNamesTheConstants(): void
    {
        $this->skipWhileInstalling();

        $controller = self::controller(['OWA_AUTH_SALT']);

        $data = $this->inMode('cli', fn () => $controller->doAction());

        $this->assertFalse($controller->ran);
        $this->assertSame('base.genericCli', $data['view']);
        $this->assertStringContainsString('OWA_AUTH_SALT', $data['msgs']);
        $this->assertStringContainsString('cmd=reset-secrets', $data['msgs']);
    }

    public function testAControllerRunsWhenTheSecretsAreUsable(): void
    {
        $this->skipWhileInstalling();

        $controller = self::controller([]);

        $this->inMode('web_app', fn () => $controller->doAction());

        $this->assertTrue($controller->ran);
    }

    public function testResetSecretsRunsWithoutThem(): void
    {
        $property = new ReflectionProperty(\OWA\Module\Base\Controller\ResetSecretsCli::class, 'requires_secrets');

        $this->assertFalse($property->getDefaultValue());
    }

    private function skipWhileInstalling(): void
    {
        if (defined('OWA_INSTALLING')) {
            $this->markTestSkipped('an installer controller in this process defined OWA_INSTALLING');
        }
    }

    /** @param string[] $unusable what the controller's owa-config.php check reports */
    private static function controller(array $unusable)
    {
        return new class($unusable) extends \OWA\Core\Controller {

            public $ran = false;

            private $unusable;

            public function __construct(array $unusable)
            {
                $this->unusable = $unusable;

                parent::__construct([]);
            }

            protected function unusableSecrets()
            {
                return $this->unusable;
            }

            public function action()
            {
                $this->ran = true;
            }
        };
    }

    private function inMode(string $mode, callable $run)
    {
        $before = \OWA\Core\CoreAPI::getSetting('base', 'request_mode');

        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', $mode);

        try {
            return $run();
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'request_mode', $before);
        }
    }
}
