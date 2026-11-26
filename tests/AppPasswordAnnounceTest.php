<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';
require_once __DIR__ . '/fixtures/mail_to_nowhere.php';

use OWA\Module\Base\Classes\AppPasswords;

/**
 * Making an application password tells the account (AppPasswords::announce()):
 * an e-mail with a link that revokes it signed out, or, when the mail is not
 * sent, a notice in the account's bell. The link (base.appPasswordRevokeLink)
 * asks on a GET and revokes on the POST.
 */
final class AppPasswordAnnounceTest extends RestControllerTestCase
{
    /** @var array<string, mixed> */
    private array $mail = [];

    /** @var string */
    private $user_id = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mail    = owa_test_mail_to_nowhere();
        $this->user_id = $this->makeUser('viewer', 'announce')['user_id'];
    }

    protected function tearDown(): void
    {
        owa_test_mail_restore($this->mail);
        unset($_SERVER['REQUEST_METHOD']);

        if ($this->user_id !== '') {
            AppPasswords::revokeAllForUser($this->user_id);
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE source = 'app_password' AND user_id = ?",
                \OWA\Core\CoreAPI::entityFactory('base.notification')->getTableName()), [$this->user_id]);
        }

        parent::tearDown();
    }

    private function made(): array
    {
        $made = AppPasswords::create($this->user_id, 'Export script', 'read');
        $this->assertIsArray($made);

        return $made + ['user_id' => $this->user_id];
    }

    private function link(array $params, string $method): \OWA\Module\Base\Controller\AppPasswordRevokeLink
    {
        $_SERVER['REQUEST_METHOD'] = $method;

        $c = new \OWA\Module\Base\Controller\AppPasswordRevokeLink($params);
        $c->action();

        return $c;
    }

    public function testTheLinksKeyNamesOnePasswordOnly(): void
    {
        $made = $this->made();
        $key  = AppPasswords::revokeKey($made);

        $this->assertSame($made['lookup_id'], AppPasswords::forRevokeLink($made['lookup_id'], $key)['lookup_id']);
        $this->assertArrayNotHasKey('secret_hash', AppPasswords::forRevokeLink($made['lookup_id'], $key));

        $this->assertNull(AppPasswords::forRevokeLink($made['lookup_id'], str_repeat('0', 64)));
        $this->assertNull(AppPasswords::forRevokeLink($made['lookup_id'], ''));

        $other = $this->made();
        $this->assertNull(AppPasswords::forRevokeLink($other['lookup_id'], $key), 'another password\'s key');
    }

    /** A GET asks, so a mail scanner that follows the link revokes nothing; the POST revokes, once. */
    public function testTheLinkAsksThenRevokesSignedOut(): void
    {
        $made   = $this->made();
        $params = ['id' => $made['lookup_id'], 'key' => AppPasswords::revokeKey($made)];

        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated(), 'signed out');

        $asked = $this->link($params, 'GET');
        $this->assertSame('ask', $asked->data['outcome']);
        $this->assertSame('Export script', $asked->data['name']);
        $this->assertNotNull(AppPasswords::verify($made['token']), 'not revoked by a GET');

        $this->assertSame('revoked', $this->link($params, 'POST')->data['outcome']);
        $this->assertNull(AppPasswords::verify($made['token']));

        $this->assertSame('gone', $this->link($params, 'POST')->data['outcome'], 'once');
        $this->assertSame('gone', $this->link(['id' => $made['lookup_id'], 'key' => 'x'], 'GET')->data['outcome']);
    }

    /** No mail sent: a notice in the account's bell says what was made. */
    public function testWhenTheMailIsNotSentTheBellSaysSo(): void
    {
        $made = $this->made();

        $this->assertFalse(AppPasswords::announce($made, $this->user_id, '203.0.113.5'));

        $titles = array_column(\OWA\Module\Base\Classes\NotificationManager::undismissedFor($this->user_id), 'title');
        $this->assertContains('An application password, Export script, was made for your account.', $titles);
    }

    /** The e-mail names the password, its access, where it was made from, and carries the link. */
    public function testTheMailSaysWhatWasMadeAndCarriesTheLink(): void
    {
        $made = $this->made();
        $key  = AppPasswords::revokeKey($made);

        $html = $this->renderMail($made, '203.0.113.5', $key);

        $this->assertStringContainsString('Name: Export script', $html);
        $this->assertStringContainsString('Access: Read only', $html);
        $this->assertStringContainsString('From: 203.0.113.5', $html);
        $this->assertStringContainsString('base.appPasswordRevokeLink', $html);
        $this->assertStringContainsString($key, $html);
        $this->assertStringNotContainsString($made['token'], $html, 'never the password itself');
    }

    private function renderMail(array $made, string $from, string $key): string
    {
        $t = new \OWA\Core\Template('base');
        $t->set('name', $made['name']);
        $t->set('scope', 'Read only');
        $t->set('created', date('Y-m-d H:i T', (int) $made['created_at']));
        $t->set('from', $from);
        $t->set('lookup_id', $made['lookup_id']);
        $t->set('key', $key);
        $this->assertTrue($t->set_template('app_password_created_email.php'));

        return (string) $t->fetch();
    }
}
