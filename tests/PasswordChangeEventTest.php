<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/fixtures/mail_to_nowhere.php';

/**
 * Setting a password from a reset link: the controller sets it, and
 * base.set_password carries only the user and the IP address.
 *
 * The event carried the new password in plain text and the passkey, and a
 * handler of the event set the password, so every listener received both.
 */
final class PasswordChangeEventTest extends TestCase
{
    /** @var array<string, mixed>|null the last base.set_password seen while a test listens */
    private static $seen = null;

    /** @var bool */
    private static $listening = false;

    /** @var array<string,mixed> */
    private array $mail = [];

    private string $userId = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs the database');
        }

        $this->mail = owa_test_mail_to_nowhere();

        static $attached = false;

        if (!$attached) {
            \OWA\Core\CoreAPI::getEventDispatch()->attach('base.set_password', static function ($event) {
                if (self::$listening) {
                    self::$seen = (array) $event->getProperties();
                }
                return OWA_EHS_EVENT_HANDLED;
            });
            $attached = true;
        }

        self::$seen      = null;
        self::$listening = true;
    }

    protected function tearDown(): void
    {
        self::$listening = false;

        if ($this->userId !== '') {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
                "DELETE FROM owa_user WHERE user_id = '%s'", addslashes($this->userId)));
        }

        if ($this->mail) {
            owa_test_mail_restore($this->mail);
        }
    }

    public function testThePasswordIsSetAndTheEventDoesNotCarryIt(): void
    {
        $this->userId = 'pw-event-' . bin2hex(random_bytes(4));

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $this->assertTrue((bool) $u->createNewUser($this->userId, 'viewer', 'old-password',
            $this->userId . '@owatest.example.com'), 'the test user must be written');
        $passkey = (string) $u->get('temp_passkey');

        $controller = new \OWA\Module\Base\Controller\UsersChangePassword([
            'k' => $passkey, 'password' => 'new-password-1', 'password2' => 'new-password-1',
        ]);
        $controller->action();

        $stored = \OWA\Core\CoreAPI::entityFactory('base.user');
        $stored->getByColumn('user_id', $this->userId);

        $this->assertTrue(password_verify('new-password-1', (string) $stored->get('password')),
            'the new password was not set');

        $this->assertIsArray(self::$seen, 'base.set_password was not raised');
        $this->assertSame($this->userId, self::$seen['user_id'] ?? null);
        $this->assertArrayNotHasKey('password', self::$seen);
        $this->assertArrayNotHasKey('key', self::$seen);
    }
}
