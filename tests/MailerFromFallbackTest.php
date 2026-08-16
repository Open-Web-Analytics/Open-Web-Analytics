<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * setMailerDomain() computes a DEFAULT From address, not an override.
 *
 * WHY THIS EXISTS
 * ---------------
 * It runs from Settings::__construct(), i.e. before load() merges the stored
 * settings in. A stored address therefore always wins, but the two earlier
 * layers -- the shipped defaults array and anything the config file sets --
 * used to be overwritten unconditionally with 'owa@' . SERVER_NAME.
 *
 * That is not a cosmetic default. An authenticating SMTP relay rejects an
 * envelope sender the account does not own, so an install whose operator had
 * configured a real From still handed the relay 'owa@<servername>' and every
 * mail died at RCPT TO with "553 5.7.1 Sender address rejected". The
 * password-reset flow reports success regardless -- the mail failure is only a
 * debug-level log line -- so the symptom was a reset mail that silently never
 * arrived.
 *
 * The auto-computed value must still appear when nothing is configured: an
 * empty From builds a malformed header that the local MTA drops (see the note
 * in owa_mailer::__construct), which is the failure this fallback exists to
 * prevent in the first place.
 */
final class MailerFromFallbackTest extends TestCase
{
    use SettingsSingletonSnapshot;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
    }

    /** The regression: a configured address survives the constructor's default. */
    public function testAConfiguredFromAddressIsNotOverwritten(): void
    {
        $c = $this->settings();
        $c->set('base', 'mailer-from', 'no-reply@example.com');

        $c->setMailerDomain();

        $this->assertSame('no-reply@example.com', $c->get('base', 'mailer-from'));
    }

    /** With nothing configured the auto-computed default still fills in. */
    public function testAnUnsetFromAddressGetsTheServerNameDefault(): void
    {
        $c = $this->settings();
        $c->set('base', 'mailer-from', '');

        $server_name = $_SERVER['SERVER_NAME'] ?? null;
        $_SERVER['SERVER_NAME'] = 'owa.example.com';

        try {
            $c->setMailerDomain();
        } finally {
            if ($server_name === null) {
                unset($_SERVER['SERVER_NAME']);
            } else {
                $_SERVER['SERVER_NAME'] = $server_name;
            }
        }

        $this->assertSame('owa@owa.example.com', $c->get('base', 'mailer-from'));
    }

    /**
     * A dotless host still gets the '.localdomain' suffix -- PHPMailer rejects
     * 'owa@localhost' as invalid.
     */
    public function testADotlessServerNameStillGetsAValidDomain(): void
    {
        $c = $this->settings();
        $c->set('base', 'mailer-from', '');

        $server_name = $_SERVER['SERVER_NAME'] ?? null;
        $_SERVER['SERVER_NAME'] = 'localhost';

        try {
            $c->setMailerDomain();
        } finally {
            if ($server_name === null) {
                unset($_SERVER['SERVER_NAME']);
            } else {
                $_SERVER['SERVER_NAME'] = $server_name;
            }
        }

        $this->assertSame('owa@localhost.localdomain', $c->get('base', 'mailer-from'));
    }
}
