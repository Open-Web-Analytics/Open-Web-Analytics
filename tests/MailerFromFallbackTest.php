<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * setMailerDomain() computes a DEFAULT From address, not an override.
 *
 * WHY THIS EXISTS
 * ---------------
 * It runs from Settings::__construct(), i.e. before load() merges the stored
 * settings in, so it must not overwrite the shipped defaults array or anything
 * the config file sets. An authenticating SMTP relay rejects an envelope
 * sender the account does not own: a configured From replaced by
 * 'owa@<servername>' fails every mail at RCPT TO with "553 5.7.1 Sender
 * address rejected" -- silently for a password reset, which reports success
 * regardless, the mail failure being only a debug-level log line.
 *
 * The auto-computed value must still appear when nothing is configured: an
 * empty From builds a malformed header that the local MTA drops (see the note
 * in owa_mailer::__construct).
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

    /** A configured address survives the constructor's default. */
    public function testAConfiguredFromAddressIsNotOverwritten(): void
    {
        $c = $this->settings();
        $c->set('base', 'mailer-from', 'no-reply@example.com');

        $c->setMailerDomain();

        $this->assertSame('no-reply@example.com', $c->get('base', 'mailer-from'));
    }

    /** With nothing configured and no public URL, the web server's host name fills in. */
    public function testAnUnsetFromAddressGetsTheServerNameDefault(): void
    {
        $c = $this->settings();
        $c->set('base', 'mailer-from', '');

        $server_name = $_SERVER['SERVER_NAME'] ?? null;
        $_SERVER['SERVER_NAME'] = 'owa.example.com';

        try {
            $c->setMailerDomain('');
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
            $c->setMailerDomain('');
        } finally {
            if ($server_name === null) {
                unset($_SERVER['SERVER_NAME']);
            } else {
                $_SERVER['SERVER_NAME'] = $server_name;
            }
        }

        $this->assertSame('owa@localhost.localdomain', $c->get('base', 'mailer-from'));
    }

    /**
     * The public URL's host wins over SERVER_NAME: it is what OWA is served as,
     * the same in a web request and in CLI or cron, where SERVER_NAME is unset
     * or names whichever virtual host answered.
     */
    public function testThePublicUrlHostIsPreferredToTheServerName(): void
    {
        $c = $this->settings();

        $server_name = $_SERVER['SERVER_NAME'] ?? null;
        $_SERVER['SERVER_NAME'] = 'ip-10-0-0-5.internal';

        try {
            $c->set('base', 'mailer-from', '');
            $c->setMailerDomain('https://analytics.example.com/owa/');
            $this->assertSame('owa@analytics.example.com', $c->get('base', 'mailer-from'));

            // With no argument, OWA_PUBLIC_URL from owa-config.php.
            $c->set('base', 'mailer-from', '');
            $c->setMailerDomain();
            $expected = defined('OWA_PUBLIC_URL') && parse_url((string) OWA_PUBLIC_URL, PHP_URL_HOST)
                ? parse_url((string) OWA_PUBLIC_URL, PHP_URL_HOST) : 'ip-10-0-0-5.internal';
            $this->assertSame('owa@' . (strpos($expected, '.') === false ? $expected . '.localdomain' : $expected),
                $c->get('base', 'mailer-from'));
        } finally {
            if ($server_name === null) {
                unset($_SERVER['SERVER_NAME']);
            } else {
                $_SERVER['SERVER_NAME'] = $server_name;
            }
        }
    }

    /** A dotless public URL host gets the suffix too. */
    public function testADotlessPublicUrlHostStillGetsAValidDomain(): void
    {
        $c = $this->settings();
        $c->set('base', 'mailer-from', '');

        $c->setMailerDomain('http://localhost/owa/');

        $this->assertSame('owa@localhost.localdomain', $c->get('base', 'mailer-from'));
    }
}
