<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The mail that confirms a password change is a mail: addressed to the user
 * whose password changed, with its subject.
 */
final class SetPasswordMailTest extends TestCase
{
    public function testTheConfirmationIsAddressedToTheUser(): void
    {
        $view = new \OWA\Module\Base\View\UsersSetPassword();

        $this->assertInstanceOf(\OWA\Core\View\Mail::class, $view);

        $view->render([
            'ip'            => '192.0.2.10',
            'subject'       => 'Password Change Complete',
            'email_address' => 'owatest@example.com',
        ]);

        $this->assertSame(['owatest@example.com'], array_column($view->po->mailer->getToAddresses(), 0));
        $this->assertSame('Password Change Complete', $view->po->mailer->Subject);
    }
}
