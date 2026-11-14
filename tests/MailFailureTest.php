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
    private array $mail = array();

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

    /** PHPMailer's own default is 300 seconds, and announcements are sent during ingest. */
    public function testTheSmtpServerIsGivenTenSeconds(): void
    {
        $this->assertSame(10, $this->message()->mailer->Timeout);
    }

    /** The address that used to throw on the ingest path: skipped, logged, and the send fails with no recipient. */
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

    /** notice_email may hold several addresses: each is a recipient. */
    public function testAListOfAddressesIsEachAdded(): void
    {
        $view = new \OWA\Core\View\Mail();

        $this->assertSame(2, $view->addMailToAddress('one@owatest.example.com, two@owatest.example.org'));
        $this->assertSame(array('one@owatest.example.com', 'two@owatest.example.org'),
            array_column($view->po->mailer->getToAddresses(), 0));

        $other = new \OWA\Core\View\Mail();
        $this->logDuring(function () use ($other, &$n) {
            $n = $other->addMailToAddress('good@owatest.example.com, bad@');
        });
        $this->assertSame(1, $n, 'the bad one is skipped, the good one kept');
    }

    // ---------------------------------------------------------------------
    // notice_email
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{string, bool}> value, accepted */
    public static function noticeEmails(): iterable
    {
        yield 'empty: announcements off'       => array('', true);
        yield 'one address'                    => array('ops@example.com', true);
        yield 'a list'                         => array('ops@example.com, owner@example.org', true);
        yield 'dotless domains'                => array('a@x, b@y', false);
        yield 'separated by a space'           => array('ops@example.com owner@example.org', false);
        yield 'not an address'                 => array('ops', false);
        yield 'a trailing comma'               => array('ops@example.com,', false);
    }

    /** @dataProvider noticeEmails */
    public function testNoticeEmailIsCheckedOnSave(string $value, bool $accepted): void
    {
        $problem = \OWA\Core\CoreAPI::configSingleton()->valueProblem('base', 'notice_email', $value);

        $accepted
            ? $this->assertNull($problem)
            : $this->assertSame('Notice E-mail Address is an e-mail address, or several separated by commas.', $problem);
    }

    // ---------------------------------------------------------------------
    // The invitation
    // ---------------------------------------------------------------------

    /** The handler says on the event whether the invitation went: UsersAdd reads it. */
    public function testTheInvitationsOutcomeIsCarriedOnTheEvent(): void
    {
        $d = \OWA\Core\CoreAPI::getEventDispatch();
        $event = $d->makeEvent('base.new_user_account');
        $event->setProperties(array(
            'user_id'       => 'owa-test-invitee',
            'email_address' => 'invitee@owatest.example.com',
            'real_name'     => 'Test Invitee',
            'temp_passkey'  => \OWA\Module\Base\Entity\User::newTempPasskey(60),
        ));

        $this->logDuring(fn () => (new \OWA\Module\Base\Handler\UserHandlers())->notify($event));

        $this->assertFalse($event->get('mail_sent'), 'the closed port refused it');
    }

    public function testTheUsersScreenSaysWhenTheInvitationWasNotSent(): void
    {
        $message = (new \OWA\Module\Base\Controller\PasswordResetRequest(array()))->getMsg(3013);

        $this->assertStringContainsString('invitation email was not sent', $message['message']);
    }

    // ---------------------------------------------------------------------
    // The new-visit announcement, on the ingest path
    // ---------------------------------------------------------------------

    /** The site the ingestion tests share (IngestionTestCase::ensureSiteRegistered()). */
    private function announcingSite(string $notice_email): string
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the announcement loads its site from the database');
        }

        $site_id = md5('owa-test-site');
        $site    = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($site->generateId($site_id));

        if (!$site->wasPersisted()) {
            \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager')
                ->createNewSite('owa-test-site', 'OWA ingestion test site', '', '', $site_id);
        }

        foreach (array('announce_visitors' => true, 'notice_email' => $notice_email) as $k => $v) {
            $this->mail['@' . $k] = \OWA\Core\CoreAPI::getSetting('base', $k);
            \OWA\Core\CoreAPI::setSetting('base', $k, $v);
        }

        return $site_id;
    }

    private function newSession(string $site_id)
    {
        $event = \OWA\Core\CoreAPI::getEventDispatch()->makeEvent('base.new_session');
        $event->setProperties(array(
            'site_id' => $site_id, 'visitor_id' => '1', 'user_name' => '', 'host' => '', 'city' => '',
            'country' => '', 'page_title' => 'Announce', 'page_url' => 'https://owa-test-site/announce',
        ));

        return $event;
    }

    private function restoreAnnouncementSettings(): void
    {
        foreach ($this->mail as $k => $v) {
            if ($k[0] === '@') {
                \OWA\Core\CoreAPI::setSetting('base', substr($k, 1), $v);
                unset($this->mail[$k]);
            }
        }
    }

    /** An address the mailer refuses -- set before notice_email was checked on save -- costs a log line, not the beacon. */
    public function testABadNoticeAddressDoesNotFailTheBeacon(): void
    {
        $site_id = $this->announcingSite('a@x, b@y');

        try {
            $log = $this->logDuring(function () use ($site_id, &$status) {
                $status = (new \OWA\Module\Base\Handler\NotifyHandlers())->notify($this->newSession($site_id));
            });
        } finally {
            $this->restoreAnnouncementSettings();
        }

        $this->assertSame(OWA_EHS_EVENT_HANDLED, $status);
        $this->assertStringContainsString("[ERROR] Mail recipient 'a@x' skipped", $log);
        $this->assertStringContainsString('[ERROR] Mail with the subject', $log);
    }

    public function testAnAnnouncementThatThrowsIsLoggedAndTheBeaconHandled(): void
    {
        $site_id = $this->announcingSite('ops@owatest.example.com');
        $s       = \OWA\Core\CoreAPI::serviceSingleton();
        $was     = $s->getMapValue('actions', 'base.notifyNewSession');
        $s->setMapValue('actions', 'base.notifyNewSession',
            array('class_name' => ThrowingAnnouncement::class, 'file' => __FILE__));

        try {
            $log = $this->logDuring(function () use ($site_id, &$status) {
                $status = (new \OWA\Module\Base\Handler\NotifyHandlers())->notify($this->newSession($site_id));
            });
        } finally {
            $s->setMapValue('actions', 'base.notifyNewSession', $was);
            $this->restoreAnnouncementSettings();
        }

        $this->assertSame(OWA_EHS_EVENT_HANDLED, $status);
        $this->assertStringContainsString('[ERROR] New session announcement for site_id ' . $site_id . ' not sent: SMTP gave up', $log);
    }
}

/** An announcement whose sending throws. */
class ThrowingAnnouncement extends \OWA\Core\Controller
{
    public function action()
    {
        throw new \RuntimeException('SMTP gave up');
    }
}
