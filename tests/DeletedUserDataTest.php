<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * Deleting a user (UserManager::deleteUser(), which base.usersDelete and
 * DELETE base/v1/users call) removes what was stored for them alone: their
 * private custom reports and funnels, their versions of shipped reports,
 * their favourites, and the notifications addressed to them. A report they
 * shared stays, owned by the admin who deleted them. A new account given the
 * same user name starts with none of it.
 *
 * Site access and application passwords: DeletedUserGrantsTest and
 * AppPasswordScreensTest.
 */
final class DeletedUserDataTest extends RestControllerTestCase
{
    /** @var list<string> custom_report ids this test made */
    private $reports = [];

    /** @var list<string> notification ids this test made */
    private $notifications = [];

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->reports as $id) {
            \OWA\Module\Base\Classes\CustomReports::delete($id);
        }

        foreach ($this->notifications as $id) {
            $db->query(sprintf('DELETE FROM %s WHERE notification_id = ?', $this->table('base.notification_state')), [$id]);
            $db->query(sprintf('DELETE FROM %s WHERE id = ?', $this->table('base.notification')), [$id]);
        }

        parent::tearDown();
    }

    private function table(string $entity): string
    {
        return \OWA\Core\CoreAPI::entityFactory($entity)->getTableName();
    }

    private function report(string $user_id, bool $shared, string $replaces = ''): string
    {
        $id = (string) random_int(1000000000, PHP_INT_MAX);

        $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            'INSERT INTO %s (id, name, user_id, property_id, report_type, is_shared, definition, creation_timestamp, last_updated_timestamp, replaces, replaces_hash)'
            . ' VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)', $this->table('base.custom_report')),
            [$id, 'Owatest ' . $this->tok, $user_id, 'report', $shared ? 1 : 0, '{}', time(), time(), $replaces, '']),
            'fixture insert failed');

        $this->reports[] = $id;

        return $id;
    }

    private function notification(string $user_id): string
    {
        $n  = \OWA\Core\CoreAPI::entityFactory('base.notification');
        $id = (string) $n->generateId('owatest' . $this->tok . $user_id);

        $n->set('id', $id);
        $n->set('source', 'owatest');
        $n->set('source_key', $this->tok . $user_id);
        $n->set('type', \OWA\Module\Base\Classes\NotificationManager::TYPE_GENERAL);
        $n->set('user_id', $user_id);
        $n->set('title', 'Owatest');
        $n->set('created_at', time());
        $this->assertNotFalse($n->create(), 'fixture insert failed');

        $this->notifications[] = $id;

        return $id;
    }

    /** @return array<string, string> custom_report id => user_id */
    private function owners(array $ids): array
    {
        $out = [];

        foreach ($ids as $id) {
            $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf('SELECT user_id FROM %s WHERE id = ?',
                $this->table('base.custom_report')), [$id]);

            if (is_array($row)) {
                $out[$id] = (string) $row['user_id'];
            }
        }

        return $out;
    }

    private function count(string $entity, string $column, string $value): int
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf('SELECT COUNT(*) AS n FROM %s WHERE %s = ?',
            $this->table($entity), $column), [$value]);

        return (int) ($row['n'] ?? 0);
    }

    public function testDeletingAUserRemovesWhatWasTheirsAlone(): void
    {
        $admin = $this->makeUser('admin', 'deleter');
        $other = $this->makeUser('viewer', 'bystander');
        $gone  = $this->makeUser('analyst', 'deleted');

        $private  = $this->report($gone['user_id'], false);
        $shared   = $this->report($gone['user_id'], true);
        $override = $this->report($gone['user_id'], false, 'base.dashboard');
        $kept     = $this->report($other['user_id'], false);

        \OWA\Module\Base\Classes\CustomReportFavorites::toggle($shared, $gone['user_id']);
        \OWA\Module\Base\Classes\CustomReportFavorites::toggle($kept, $gone['user_id']);
        \OWA\Module\Base\Classes\CustomReportFavorites::toggle($private, $other['user_id']);
        \OWA\Module\Base\Classes\CustomReportFavorites::toggle($shared, $other['user_id']);

        $theirs = $this->notification($gone['user_id']);
        $others = $this->notification($other['user_id']);
        \OWA\Module\Base\Classes\NotificationManager::markRead($others, $gone['user_id']);
        \OWA\Module\Base\Classes\NotificationManager::markRead($theirs, $other['user_id']);

        $this->assertSame(2, $this->count('base.custom_report_favorite', 'user_id', $gone['user_id']), 'fixture');
        $this->assertSame(1, $this->count('base.notification_state', 'user_id', $gone['user_id']), 'fixture');

        $this->assertTrue((new \OWA\Module\Base\Classes\UserManager())->deleteUser($gone['user_id'], $admin['user_id']));

        $this->assertSame([$shared => $admin['user_id'], $kept => $other['user_id']],
            $this->owners([$private, $shared, $override, $kept]),
            'the private report and the override go; the shared one passes to the admin');

        $this->assertSame(0, $this->count('base.custom_report_favorite', 'user_id', $gone['user_id']));
        $this->assertSame(0, $this->count('base.custom_report_favorite', 'custom_report_id', $private),
            'stars on a deleted report go with it');
        $this->assertSame(1, $this->count('base.custom_report_favorite', 'custom_report_id', $shared),
            'another user\'s star on the shared report stays');

        $this->assertSame(0, $this->count('base.notification', 'user_id', $gone['user_id']));
        $this->assertSame(0, $this->count('base.notification_state', 'user_id', $gone['user_id']));
        $this->assertSame(0, $this->count('base.notification_state', 'notification_id', $theirs));
        $this->assertSame(1, $this->count('base.notification', 'id', $others), 'another user\'s notification stays');
    }

    public function testANewAccountWithTheSameNameStartsEmpty(): void
    {
        $admin = $this->makeUser('admin', 'deleter');
        $gone  = $this->makeUser('viewer', 'renamed');

        $this->report($gone['user_id'], false);
        $this->report($gone['user_id'], false, 'base.dashboard');

        $this->assertTrue((new \OWA\Module\Base\Classes\UserManager())->deleteUser($gone['user_id'], $admin['user_id']));

        $again = \OWA\Core\CoreAPI::entityFactory('base.user');
        $this->assertNotFalse($again->createNewUser($gone['user_id'], 'viewer', 'pw' . $this->tok, $gone['user_id'], 'Again'));
        $this->trackForCleanup('base.user', $gone['user_id'], 'user_id');

        $this->assertSame(0, $this->count('base.custom_report', 'user_id', $gone['user_id']));
        $this->assertNull(\OWA\Module\Base\Classes\ReportOverrides::find('base.dashboard', $gone['user_id'], '1'));
    }

    /** No admin named: the shared report passes to an admin all the same. */
    public function testWithNoNewOwnerNamedASharedReportPassesToAnAdmin(): void
    {
        $this->makeUser('admin', 'remaining');
        $gone = $this->makeUser('analyst', 'no-owner');

        $shared = $this->report($gone['user_id'], true);

        $this->assertTrue((new \OWA\Module\Base\Classes\UserManager())->deleteUser($gone['user_id']));

        $owner = $this->owners([$shared])[$shared] ?? '';
        $u     = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->load($owner, 'user_id');

        $this->assertSame('admin', (string) $u->get('role'));
    }
}
