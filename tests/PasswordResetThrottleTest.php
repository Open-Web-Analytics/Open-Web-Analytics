<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';
require_once __DIR__ . '/fixtures/mail_to_nowhere.php';

use OWA\Module\Base\Entity\User;

/**
 * One password reset email per account per RESET_RESEND_AFTER.
 *
 * The request form is unauthenticated, and each request used to mint a new
 * key and send another email. A request inside the window now keeps the key
 * already sent and sends nothing; the requester's reply is the same either
 * way (PasswordResetDoesNotDiscloseAccountsTest).
 */
final class PasswordResetThrottleTest extends TestCase
{
    use CapturesLog;

    /** @return iterable<string, array{callable, bool}> */
    public static function keys(): iterable
    {
        $hex   = str_repeat('ab', 16);
        $reset = fn (int $minted_ago) => $hex . '.' . (time() - $minted_ago + User::RESET_TTL);

        yield 'a reset key minted just now'        => array(fn () => $reset(0), true);
        yield 'one minted 14 minutes ago'           => array(fn () => $reset(14 * 60), true);
        yield 'one minted 16 minutes ago'           => array(fn () => $reset(16 * 60), false);
        yield 'an invitation key minted just now'   => array(fn () => $hex . '.' . (time() + User::INVITE_TTL), false);
        yield 'a spent key'                         => array(fn () => $hex . '.0', false);
        yield 'an expired key'                      => array(fn () => $hex . '.' . (time() - 1), false);
        yield 'no key'                              => array(fn () => '', false);
    }

    /** @dataProvider keys */
    public function testWhatCountsAsARecentResetMail(callable $key, bool $recent): void
    {
        $this->assertSame($recent, User::resetMailedRecently($key()));
    }

    // ---------------------------------------------------------------------
    // Through the handler, against an account in the database
    // ---------------------------------------------------------------------

    private string $user_id = '';

    /** @var array<string,mixed> */
    private array $mail = array();

    private function account(string $key): User
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs an account in the database');
        }

        $this->mail    = owa_test_mail_to_nowhere();
        $this->user_id = 'owa-test-reset-' . bin2hex(random_bytes(4));

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->createNewUser($this->user_id, 'viewer', 'a password', $this->user_id . '@owatest.example.com', 'Reset Test');
        $u->load($this->user_id, 'user_id');
        $u->set('temp_passkey', $key);
        $u->update();

        return $u;
    }

    protected function tearDown(): void
    {
        if ($this->user_id !== '') {
            \OWA\Core\CoreAPI::entityFactory('base.user')->delete($this->user_id, 'user_id');
        }

        if ($this->mail) {
            owa_test_mail_restore($this->mail);
        }
    }

    /** @return mixed what the reset action returned: the mailer's answer, or null when it sent nothing */
    private function requestReset(): mixed
    {
        $d = \OWA\Core\CoreAPI::getEventDispatch();
        $event = $d->makeEvent('base.reset_password');
        $event->set('email_address', $this->user_id . '@owatest.example.com');

        $this->logDuring(function () use ($event, &$result) {
            $result = \OWA\Core\CoreAPI::performAction('base.usersResetPassword', array('event' => $event));
        });

        return $result;
    }

    private function storedKey(): string
    {
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->load($this->user_id, 'user_id');

        return (string) $u->get('temp_passkey');
    }

    public function testASecondRequestInsideTheWindowSendsNothingAndKeepsTheKey(): void
    {
        $key = User::newTempPasskey(User::RESET_TTL);
        $this->account($key);

        $this->assertNull($this->requestReset(), 'no mail attempted');
        $this->assertSame($key, $this->storedKey(), 'the key already sent still works');
    }

    public function testARequestAfterTheWindowSendsANewKey(): void
    {
        $old = str_repeat('cd', 16) . '.' . (time() - User::RESET_RESEND_AFTER - 60 + User::RESET_TTL);
        $this->account($old);

        $this->assertFalse($this->requestReset(), 'a mail was attempted (the closed port refused it)');
        $this->assertNotSame($old, $this->storedKey());
        $this->assertTrue(User::tempPasskeyIsCurrent($this->storedKey()));
    }
}
