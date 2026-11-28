<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * No screen shows a user's legacy API key: the key signs nothing in, and a
 * user upgraded from 1.x still has one stored.
 */
final class NoApiKeyOnScreenTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    public function testTheUserEditScreenDoesNotShowTheKey(): void
    {
        $t = new \OWA\Core\Template('base');

        foreach ([
            'headline' => 'Edit user', 'action' => 'base.usersEdit', 'edit' => true,
            'roles' => ['admin', 'viewer'], 'app_passwords' => [], 'app_password_scopes' => [],
            'user' => ['user_id' => 'someone', 'real_name' => 'Someone', 'role' => 'viewer',
                'email_address' => 'someone@example.test', 'api_key' => self::KEY],
        ] as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template('users_addoredit.php'));
        $html = (string) $t->fetch();

        $this->assertStringContainsString('someone', $html, 'the screen rendered the user');
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsStringIgnoringCase('API Key', $html);
    }

    /** No template reads the key. */
    public function testNoTemplateReadsTheKey(): void
    {
        $found = [];

        foreach (glob(\OWA\Core\Env::root() . '/modules/*/templates/*.php') as $file) {
            if (preg_match('/api_key|getApiKey|apiKey/i', (string) file_get_contents($file))) {
                $found[] = basename($file);
            }
        }

        $this->assertSame([], $found);
    }
}
