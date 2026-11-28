<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\NotificationManager as NM;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * NotificationManager::notify(): a notice OWA raises for its admins, stored
 * once and e-mailed at once by the process that raised it.
 */
final class NotificationNoticeTest extends TestCase
{
    /** Every source this class writes under starts with this. */
    private const SOURCE_PREFIX = 'unittest_notice_';

    private string $was_type = '';
    private string $was_recipients = '';

    /** @var list<array{to: list<string>, subject: string, text: string, html: string}> */
    private array $sent = [];

    private bool $accept = true;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('stores notifications');
        }

        $this->was_type       = (string) \OWA\Core\CoreAPI::getSetting('base', 'request_type');
        $this->was_recipients = (string) \OWA\Core\CoreAPI::getSetting('base', 'admin_notice_email');

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
        \OWA\Core\CoreAPI::setSetting('base', 'admin_notice_email', 'ops@example.test, second@example.test');

        $test = $this;

        NM::$mailerFactory = static function () use ($test) {

            return new class($test) {

                /** @var list<string> */
                public array $to = [];
                public string $subject = '';
                public string $text = '';
                public string $html = '';

                public function __construct(private NotificationNoticeTest $test) {}

                public function addAddress($address, $name = '') { $this->to[] = (string) $address; return true; }
                public function setSubject($s) { $this->subject = (string) $s; }
                public function setAltBody($t) { $this->text = (string) $t; }
                public function setHtmlBody($h) { $this->html = (string) $h; }
                public function sendMail() { return $this->test->deliver($this->to, $this->subject, $this->text, $this->html); }
            };
        };
    }

    protected function tearDown(): void
    {
        NM::$mailerFactory = null;

        if (!owa_test_db_available()) {
            return;
        }

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->was_type);
        \OWA\Core\CoreAPI::setSetting('base', 'admin_notice_email', $this->was_recipients);

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_notification');
        $db->selectColumn('id');
        $db->selectColumn('source');

        foreach ((array) $db->getAllRows() as $row) {
            if (strpos((string) $row['source'], self::SOURCE_PREFIX) === 0) {
                \OWA\Core\CoreAPI::entityFactory('base.notification')->delete($row['id']);
            }
        }
    }

    /** The fake mailer's send: recorded, and accepted unless the test says otherwise. */
    public function deliver(array $to, string $subject, string $text, string $html): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];

        return $this->accept;
    }

    private function notice(string $key, array $more = []): array
    {
        return $more + [
            'source'     => self::SOURCE_PREFIX . 'src',
            'source_key' => $key,
            'title'      => 'Update to 9.9.9 failed',
            'body'       => "Schema update 95 failed: <b>lock</b> timeout.\nRetrying at 03:15.",
            'severity'   => 'warning',
            'email'      => true,
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        $n = \OWA\Core\CoreAPI::entityFactory('base.notification');
        $n->load($n->generateId(self::SOURCE_PREFIX . 'src' . $key));

        return [
            // Strings: the entity layer stores an empty string as NULL.
            'severity'     => (string) $n->get('severity'),
            'email_status' => (string) $n->get('email_status'),
            'emailed_at'   => (int) $n->get('emailed_at'),
            'user_id'      => (string) $n->get('user_id'),
            'title'        => (string) $n->get('title'),
        ];
    }

    /**
     * @covers-setting base.admin_notice_email
     * @covers-setting base.request_type
     */
    public function testANoticeIsStoredForEveryoneAndMailedOnce(): void
    {
        $this->assertTrue(NM::notify($this->notice('k1')));
        $this->assertFalse(NM::notify($this->notice('k1')), 'the same source and key is a notice already raised');

        $row = $this->row('k1');

        $this->assertSame('warning', $row['severity']);
        $this->assertSame('', $row['user_id'], 'global: every admin sees it');
        $this->assertSame(NM::EMAIL_SENT, $row['email_status']);
        $this->assertGreaterThan(0, $row['emailed_at']);

        $this->assertCount(1, $this->sent, 'raised twice, mailed once');
        $this->assertSame(['ops@example.test', 'second@example.test'], $this->sent[0]['to']);
        $this->assertSame('OWA: Update to 9.9.9 failed', $this->sent[0]['subject']);
        $this->assertStringContainsString("Retrying at 03:15.", $this->sent[0]['text']);
        $this->assertStringContainsString('&lt;b&gt;lock&lt;/b&gt;', $this->sent[0]['html'], 'the HTML copy is escaped');
        $this->assertStringNotContainsString('<b>', $this->sent[0]['html']);
    }

    /** Another key under the same source is another notice: no watermark, no first-fetch limit. */
    public function testEveryNewKeyIsANotice(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
            $this->assertTrue(NM::notify($this->notice($key)));
        }

        $this->assertCount(5, $this->sent);
    }

    public function testAnUnknownSeverityIsInfo(): void
    {
        NM::notify($this->notice('k2', ['severity' => 'apocalyptic']));

        $this->assertSame('info', $this->row('k2')['severity']);
    }

    public function testANoticeThatAsksForNoMailSendsNone(): void
    {
        NM::notify($this->notice('k3', ['email' => false]));

        $this->assertSame('', $this->row('k3')['email_status']);
        $this->assertSame(0, $this->row('k3')['emailed_at']);
        $this->assertSame([], $this->sent);
    }

    /**
     * A page request never sends mail; it records that it did not.
     *
     * @covers-setting base.request_type
     */
    public function testAPageRequestSendsNoMail(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'ui');

        $this->assertTrue(NM::notify($this->notice('k4')));

        $this->assertSame(NM::EMAIL_NOT_FROM_WEB, $this->row('k4')['email_status']);
        $this->assertSame(0, $this->row('k4')['emailed_at']);
        $this->assertSame([], $this->sent);
    }

    /** A refused send is recorded and not retried: raising it again sends nothing. */
    public function testARefusedSendIsRecordedAndNotRetried(): void
    {
        $this->accept = false;

        NM::notify($this->notice('k5'));

        $this->assertSame(NM::EMAIL_FAILED, $this->row('k5')['email_status']);

        $this->accept = true;
        NM::notify($this->notice('k5'));

        $this->assertCount(1, $this->sent);
        $this->assertSame(NM::EMAIL_FAILED, $this->row('k5')['email_status']);
    }

    /**
     * @covers-setting base.admin_notice_email
     */
    public function testConfiguredRecipientsAreTrimmedAndOnce(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'admin_notice_email', ' a@example.test ,b@example.test,, a@example.test ');

        $this->assertSame(['a@example.test', 'b@example.test'], NM::adminRecipients());
    }

    /**
     * Empty means every admin user's address: the admin users, nobody else.
     *
     * @covers-setting base.admin_notice_email
     */
    public function testNoConfiguredRecipientMeansTheAdmins(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'admin_notice_email', '');

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_user');
        $db->selectColumn('email_address');
        $db->selectColumn('role');

        $admins = [];

        foreach ((array) $db->getAllRows() as $row) {
            $address = trim((string) $row['email_address']);

            if ($row['role'] === 'admin' && $address !== '' && !in_array($address, $admins, true)) {
                $admins[] = $address;
            }
        }

        $this->assertSame($admins, NM::adminRecipients());
    }

    /** The last notice that asked for mail is what System Health reports. */
    public function testSystemHealthReportsTheLastNoticeMail(): void
    {
        $this->accept = false;
        NM::notify($this->notice('k6'));

        $finding = \OWA\Module\Base\Classes\SystemHealth::noticeEmail();

        $this->assertSame('red', $finding['level']);
        $this->assertStringContainsString('Update to 9.9.9 failed', $finding['detail']);

        // created_at orders them: the failed one a minute older.
        $n = \OWA\Core\CoreAPI::entityFactory('base.notification');
        $n->load($n->generateId(self::SOURCE_PREFIX . 'src' . 'k6'));
        $n->set('created_at', time() - 60);
        $n->update();

        $this->accept = true;
        NM::notify($this->notice('k7', ['title' => 'Updated to 9.9.9']));

        $finding = \OWA\Module\Base\Classes\SystemHealth::noticeEmail();

        $this->assertSame('green', $finding['level']);
        $this->assertStringContainsString('Sent to 2 addresses.', $finding['detail']);
        $this->assertStringContainsString('Updated to 9.9.9', $finding['detail']);
    }
}
