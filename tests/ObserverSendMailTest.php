<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/fixtures/mail_to_nowhere.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * Observer::sendMail() sends through the configured Mailer: a refused
 * transport is logged and returns false, an accepted one returns true.
 */
final class ObserverSendMailTest extends TestCase
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

    private function observer(bool $accepts): \OWA\Core\Observer
    {
        return new class($accepts) extends \OWA\Core\Observer {

            public function __construct(private bool $accepts) {}

            protected function mailer()
            {
                $m = parent::mailer();

                if ($this->accepts) {
                    // A "sendmail" that accepts anything and delivers nothing.
                    $m->mailer->isSendmail();
                    $m->mailer->Sendmail = '/bin/true';
                }

                return $m;
            }
        };
    }

    public function testAnAcceptedMessageIsSentThroughTheMailer(): void
    {
        $log = $this->logDuring(function () use (&$sent) {
            $sent = $this->observer(true)->sendMail('someone@owatest.example.com', 'Observer mail', "line one\nline two");
        });

        $this->assertTrue($sent);
        $this->assertStringContainsString("[NOTICE] Mail sent with the subject 'Observer mail'.", $log);
    }

    public function testATransportFailureReturnsFalseAndIsLogged(): void
    {
        $log = $this->logDuring(function () use (&$sent) {
            $sent = $this->observer(false)->sendMail('someone@owatest.example.com', 'Observer mail', 'body');
        });

        $this->assertFalse($sent);
        $this->assertMatchesRegularExpression("/\\[ERROR\\] Mail with the subject 'Observer mail' was not sent: /", $log);
    }

    public function testAnAddressTheMailerRefusesSendsNothing(): void
    {
        $this->assertFalse($this->observer(true)->sendMail('a@x, b@y', 'Observer mail', 'body'));
    }
}
