<?php

/**
 * Point OWA's mailer at a local SMTP port nothing listens on, so a test that
 * makes OWA send mail gets a quick, real transport failure and no message
 * reaches an MTA. Returns the settings it replaced, for
 * owa_test_mail_restore().
 *
 * @return array<string,mixed>
 */
function owa_test_mail_to_nowhere(): array
{
    // A port the kernel just handed out and that is closed again: connecting is refused at once.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port   = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $values = array(
        'mailer-use-smtp' => true,
        'mailer-host'     => '127.0.0.1',
        'mailer-port'     => $port,
        'mailer-smtpAuth' => false,
        'mailer-from'     => 'owa@owatest.example.com',
    );

    $was = array();

    foreach ($values as $key => $value) {
        $was[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        \OWA\Core\CoreAPI::setSetting('base', $key, $value);
    }

    return $was;
}

/** @param array<string,mixed> $was what owa_test_mail_to_nowhere() returned */
function owa_test_mail_restore(array $was): void
{
    foreach ($was as $key => $value) {
        \OWA\Core\CoreAPI::setSetting('base', $key, $value);
    }
}
