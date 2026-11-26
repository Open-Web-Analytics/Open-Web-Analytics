<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * Editing a user (base.usersEdit) and one's own profile (base.myProfileSave):
 * another user's email address is refused -- a reset link for it would reach
 * the wrong account -- the user's own is kept, and an edit that posts no role
 * keeps the role.
 */
final class UsersEditTest extends RestControllerTestCase
{
    private function edit(array $params): \OWA\Module\Base\Controller\UsersEdit
    {
        $c = new \OWA\Module\Base\Controller\UsersEdit($params + ['real_name' => 'Edited']);
        $c->action();

        return $c;
    }

    private function user(string $user_id): \OWA\Core\Entity
    {
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $user_id);

        return $u;
    }

    public function testAnotherUsersAddressIsRefused(): void
    {
        $a = $this->makeUser('analyst', 'edit-a');
        $b = $this->makeUser('viewer', 'edit-b');

        $c = $this->edit(['user_id' => $a['user_id'], 'email_address' => $b['user_id'], 'role' => 'analyst']);

        $this->assertArrayHasKey('email_address', (array) ($c->data['validation_errors'] ?? []));
        $this->assertSame($a['user_id'], (string) $this->user($a['user_id'])->get('email_address'), 'unchanged');
    }

    public function testTheUsersOwnAddressAndRoleAreKept(): void
    {
        $a = $this->makeUser('analyst', 'edit-own');

        $c = $this->edit(['user_id' => $a['user_id'], 'email_address' => $a['user_id']]);

        $this->assertArrayNotHasKey('validation_errors', $c->data);
        $this->assertSame('analyst', (string) $this->user($a['user_id'])->get('role'), 'no role posted: the role is kept');
        $this->assertSame('Edited', (string) $this->user($a['user_id'])->get('real_name'));
    }

    /** My Profile refuses another user's address too, for an admin, who may change their own. */
    public function testMyProfileRefusesAnotherUsersAddress(): void
    {
        $me    = $this->authenticateAs('admin');
        $other = $this->makeUser('viewer', 'edit-other');

        $save = new \OWA\Module\Base\Controller\MyProfileSave(['real_name' => 'Me', 'email_address' => $other['user_id']]);
        $save->action();

        $this->assertSame('3009', (string) ($save->data['myProfileError'] ?? ''));
        $this->assertSame($me['user_id'], (string) $this->user($me['user_id'])->get('email_address'), 'unchanged');
    }
}
