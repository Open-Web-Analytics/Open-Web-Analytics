<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/** base.usersEdit counting one admin account, whatever the database holds. */
class UsersEditTestOneAdmin extends \OWA\Module\Base\Controller\UsersEdit
{
    protected function userManager()
    {
        return new class extends \OWA\Module\Base\Classes\UserManager {

            protected function adminCount()
            {
                return 1;
            }
        };
    }

    protected function resolveCurrentSiteId( $siteId = '', $propertyId = '' )
    {
        return '';
    }

    protected function settingsChrome( array $params, $siteId = '', $propertyId = '' )
    {
    }
}

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

    public function testTheOnlyAdminKeepsTheAdminRole(): void
    {
        $a = $this->makeUser('admin', 'edit-only-admin');

        $c = new UsersEditTestOneAdmin(['user_id' => $a['user_id'], 'email_address' => $a['user_id'],
            'role' => 'viewer', 'real_name' => 'Edited']);
        $c->action();

        $this->assertSame('The only admin account keeps the admin role. Give another user the admin role first.',
            $c->data['validation_errors']['role'] ?? null);
        $this->assertTrue($c->data['edit'] ?? null, 'the edit form again');
        $this->assertSame('admin', (string) $this->user($a['user_id'])->get('role'), 'unchanged');
        $this->assertNotSame('Edited', (string) $this->user($a['user_id'])->get('real_name'), 'nothing saved');
    }

    public function testAnAdminIsGivenAnotherRoleWhileAnotherAdminRemains(): void
    {
        $this->makeUser('admin', 'edit-other-admin');
        $a = $this->makeUser('admin', 'edit-demoted');

        $c = $this->edit(['user_id' => $a['user_id'], 'email_address' => $a['user_id'], 'role' => 'viewer']);

        $this->assertArrayNotHasKey('validation_errors', $c->data);
        $this->assertSame('viewer', (string) $this->user($a['user_id'])->get('role'));
    }

    /** The installer's account is not special: its name and role are edited like any other. */
    public function testTheInstallersAccountIsEditedLikeAnyOther(): void
    {
        $this->makeUser('admin', 'edit-second-admin');
        $a = $this->makeUser('admin', 'edit-installer');

        $u = $this->user($a['user_id']);
        $u->set('real_name', \OWA\Module\Base\Entity\User::ADMIN_USER_REAL_NAME);
        $this->assertTrue((bool) $u->update(), 'fixture update failed');

        $c = $this->edit(['user_id' => $a['user_id'], 'email_address' => $a['user_id'], 'role' => 'analyst']);

        $this->assertArrayNotHasKey('validation_errors', $c->data);
        $this->assertSame('Edited', (string) $this->user($a['user_id'])->get('real_name'));
        $this->assertSame('analyst', (string) $this->user($a['user_id'])->get('role'));
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
