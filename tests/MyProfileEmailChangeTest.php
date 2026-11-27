<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * Changing your own email address on My profile (base.myProfileSave) takes
 * the current password, and the address the account had is mailed about it
 * (base.email_address_changed). The same address saves without one, and an
 * admin changing another user's address on the Users screen
 * (base.usersEdit) needs neither.
 *
 * The mailer points at a closed port (RestControllerTestCase), so every send
 * fails: the change has to stand regardless.
 */
final class MyProfileEmailChangeTest extends RestControllerTestCase
{
    use CapturesLog;

    /** @var list<\OWA\Module\Base\Classes\Event> each base.email_address_changed raised while a test listens */
    private static $seen = [];

    /** @var bool */
    private static $listening = false;

    protected function setUp(): void
    {
        parent::setUp();

        static $attached = false;

        if (!$attached) {
            \OWA\Core\CoreAPI::getEventDispatch()->attach('base.email_address_changed', static function ($event) {
                if (self::$listening) {
                    self::$seen[] = $event;
                }
                return OWA_EHS_EVENT_HANDLED;
            });
            $attached = true;
        }

        self::$seen      = [];
        self::$listening = true;
    }

    protected function tearDown(): void
    {
        self::$listening = false;

        parent::tearDown();
    }

    private function address(string $user_id): string
    {
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $user_id);

        return (string) $u->get('email_address');
    }

    /** @param array<string, string> $params */
    private function save(array $params): \OWA\Module\Base\Controller\MyProfileSave
    {
        $save = new \OWA\Module\Base\Controller\MyProfileSave($params + ['real_name' => 'Me']);
        $save->action();

        return $save;
    }

    private function newAddress(): string
    {
        return 'moved-' . $this->tok . '@owatest.example.com';
    }

    public function testAnAddressChangeWithoutTheCurrentPasswordIsRefused(): void
    {
        $me = $this->authenticateAs('admin');

        $save = $this->save(['email_address' => $this->newAddress()]);

        $this->assertSame('3230', (string) ($save->data['myProfileError'] ?? ''));
        $this->assertSame($me['user_id'], $this->address($me['user_id']), 'unchanged');
        $this->assertSame([], self::$seen, 'nothing mailed');
    }

    public function testAnAddressChangeWithAWrongCurrentPasswordIsRefused(): void
    {
        $me = $this->authenticateAs('admin');

        $save = $this->save(['email_address' => $this->newAddress(), 'current_password' => 'not-the-password']);

        $this->assertSame('3236', (string) ($save->data['myProfileError'] ?? ''));
        $this->assertSame($me['user_id'], $this->address($me['user_id']), 'unchanged');
        $this->assertSame([], self::$seen, 'nothing mailed');
    }

    public function testWithTheCurrentPasswordTheAddressChangesAndTheOldOneIsMailed(): void
    {
        $me  = $this->authenticateAs('admin');
        $new = $this->newAddress();

        $log = $this->logDuring(function () use (&$save, $new) {
            $save = $this->save(['email_address' => $new, 'current_password' => 'pw' . $this->tok]);
        });

        $this->assertArrayNotHasKey('myProfileError', $save->data);
        $this->assertTrue((bool) ($save->data['myProfileSaved'] ?? false));
        $this->assertSame($new, $this->address($me['user_id']), 'changed, though the mail was not sent');

        $this->assertCount(1, self::$seen, 'one mail');
        $event = self::$seen[0];
        $this->assertSame($me['user_id'], $event->get('user_id'));
        $this->assertSame($me['user_id'], $event->get('old_email_address'));
        $this->assertSame($new, $event->get('new_email_address'));
        $this->assertSame(\OWA\Core\ClientAddress::fromRequest(), $event->get('ip'));
        $this->assertFalse($event->get('mail_sent'), 'the closed port refused it');
        $this->assertStringContainsString('the mail telling its previous address was not sent', $log);

        // The mail its handler sends: to the old address, saying what changed.
        $mail = new \OWA\Module\Base\Controller\UsersEmailChanged(['event' => $event]);
        $mail->action();

        $view = new \OWA\Module\Base\View\UsersEmailChanged();
        $view->setData($mail->data);
        $view->render($mail->data);

        $this->assertSame([$me['user_id']], array_column($view->po->mailer->getToAddresses(), 0));

        $body = (string) $view->body->fetch();
        $this->assertStringContainsString('User name: ' . $me['user_id'], $body);
        $this->assertStringContainsString('New address: ' . $new, $body);
        $this->assertStringContainsString('From: ' . \OWA\Core\ClientAddress::fromRequest(), $body);
        $this->assertStringContainsString('ask an administrator', $body);
    }

    public function testTheSameAddressSavesWithoutAPassword(): void
    {
        $me = $this->authenticateAs('admin');

        $save = $this->save(['email_address' => $me['user_id'], 'real_name' => 'Renamed']);

        $this->assertArrayNotHasKey('myProfileError', $save->data);
        $this->assertTrue((bool) ($save->data['myProfileSaved'] ?? false));
        $this->assertSame($me['user_id'], $this->address($me['user_id']));
        $this->assertSame([], self::$seen, 'nothing mailed');
    }

    /** The Users screen: an admin changes another user's address with no password, and nothing is mailed. */
    public function testAnAdminChangingAnotherUsersAddressIsUnchanged(): void
    {
        $this->authenticateAs('admin');
        $other = $this->makeUser('viewer', 'edit-addr');
        $new   = $this->newAddress();

        $edit = new \OWA\Module\Base\Controller\UsersEdit([
            'user_id' => $other['user_id'], 'email_address' => $new, 'real_name' => 'Edited', 'role' => 'viewer']);
        $edit->action();

        $this->assertArrayNotHasKey('validation_errors', $edit->data);
        $this->assertSame($new, $this->address($other['user_id']));
        $this->assertSame([], self::$seen, 'nothing mailed');
    }
}
