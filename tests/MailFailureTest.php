<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';
require_once __DIR__ . '/fixtures/mail_to_nowhere.php';

use OWA\Module\Base\Classes\Mailer;

/**
 * Mail that is not sent says so: the mailer returns whether it sent and logs
 * a failure as an error, a recipient it refuses is skipped rather than
 * thrown, and the invitation's outcome reaches whoever created the account.
 *
 * Mail goes to a closed local SMTP port (fixtures/mail_to_nowhere.php), or to
 * /bin/true as the sendmail binary: nothing reaches an MTA.
 */
final class MailFailureTest extends TestCase
{
    use CapturesLog;

    /** @var array<string,mixed> */
    private array $mail = [];

    protected function setUp(): void
    {
        $this->mail = owa_test_mail_to_nowhere();
    }

    protected function tearDown(): void
    {
        owa_test_mail_restore($this->mail);
    }

    private function message(): Mailer
    {
        $m = new Mailer();
        $m->setSubject('OWA mail test');
        $m->setHtmlBody('<p>test</p>');

        return $m;
    }

    public function testATransportFailureReturnsFalseAndIsLoggedAsAnError(): void
    {
        $m = $this->message();
        $m->addAddress('someone@owatest.example.com');

        $log = $this->logDuring(function () use ($m, &$sent) {
            $sent = $m->sendMail();
        });

        $this->assertFalse($sent);
        $this->assertMatchesRegularExpression("/\\[ERROR\\] Mail with the subject 'OWA mail test' was not sent: /", $log);
    }

    public function testASentMessageReturnsTrue(): void
    {
        $m = $this->message();
        $m->addAddress('someone@owatest.example.com');
        // A "sendmail" that accepts anything and delivers nothing.
        $m->mailer->isSendmail();
        $m->mailer->Sendmail = '/bin/true';

        $log = $this->logDuring(function () use ($m, &$sent) {
            $sent = $m->sendMail();
        });

        $this->assertTrue($sent);
        $this->assertStringContainsString("[NOTICE] Mail sent with the subject 'OWA mail test'.", $log);
    }

    /** PHPMailer's own default is 300 seconds. */
    public function testTheSmtpServerIsGivenTenSeconds(): void
    {
        $this->assertSame(10, $this->message()->mailer->Timeout);
    }

    /** A recipient the mailer refuses: skipped, logged, and the send fails with no recipient. */
    public function testARecipientTheMailerRefusesIsSkippedNotThrown(): void
    {
        $m = $this->message();

        $log = $this->logDuring(function () use ($m, &$added, &$sent) {
            $added = $m->addAddress('a@x, b@y');
            $sent  = $m->sendMail();
        });

        $this->assertFalse($added);
        $this->assertFalse($sent);
        $this->assertStringContainsString("[ERROR] Mail recipient 'a@x, b@y' skipped", $log);
    }

    /** admin_notice_email may hold several addresses: each is a recipient. */
    public function testAListOfAddressesIsEachAdded(): void
    {
        $view = new \OWA\Core\View\Mail();

        $this->assertSame(2, $view->addMailToAddress('one@owatest.example.com, two@owatest.example.org'));
        $this->assertSame(['one@owatest.example.com', 'two@owatest.example.org'],
            array_column($view->po->mailer->getToAddresses(), 0));

        $other = new \OWA\Core\View\Mail();
        $this->logDuring(function () use ($other, &$n) {
            $n = $other->addMailToAddress('good@owatest.example.com, bad@');
        });
        $this->assertSame(1, $n, 'the bad one is skipped, the good one kept');
    }

    // ---------------------------------------------------------------------
    // admin_notice_email
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{string, bool}> value, accepted */
    public static function noticeEmails(): iterable
    {
        yield 'empty: every admin'             => ['', true];
        yield 'one address'                    => ['ops@example.com', true];
        yield 'a list'                         => ['ops@example.com, owner@example.org', true];
        yield 'dotless domains'                => ['a@x, b@y', false];
        yield 'separated by a space'           => ['ops@example.com owner@example.org', false];
        yield 'not an address'                 => ['ops', false];
        yield 'a trailing comma'               => ['ops@example.com,', false];
    }

    /** @dataProvider noticeEmails */
    public function testAdminNoticeEmailIsCheckedOnSave(string $value, bool $accepted): void
    {
        $problem = \OWA\Core\CoreAPI::configSingleton()->valueProblem('base', 'admin_notice_email', $value);

        $accepted
            ? $this->assertNull($problem)
            : $this->assertSame('Admin Notice E-mail Address is an e-mail address, or several separated by commas.', $problem);
    }

    // ---------------------------------------------------------------------
    // The invitation
    // ---------------------------------------------------------------------

    /** The handler says on the event whether the invitation went: UsersAdd reads it. */
    public function testTheInvitationsOutcomeIsCarriedOnTheEvent(): void
    {
        $d = \OWA\Core\CoreAPI::getEventDispatch();
        $event = $d->makeEvent('base.new_user_account');
        $event->setProperties([
            'user_id'       => 'owa-test-invitee',
            'email_address' => 'invitee@owatest.example.com',
            'real_name'     => 'Test Invitee',
            'temp_passkey'  => \OWA\Module\Base\Entity\User::newTempPasskey(60),
        ]);

        $this->logDuring(fn () => (new \OWA\Module\Base\Handler\UserHandlers())->notify($event));

        $this->assertFalse($event->get('mail_sent'), 'the closed port refused it');
    }

    public function testTheUsersScreenSaysWhenTheInvitationWasNotSent(): void
    {
        $message = (new \OWA\Module\Base\Controller\PasswordResetRequest([]))->getMsg(3013);

        $this->assertStringContainsString('invitation email was not sent', $message['message']);
    }
}
