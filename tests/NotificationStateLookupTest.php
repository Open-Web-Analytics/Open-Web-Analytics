<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\NotificationManager as NM;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A user's state row for a notification is found by its notification_id and
 * user_id columns, and a new row's id separates the two: notification 900 for
 * user 1<name> and 9001 for <name> run together to the same string.
 */
final class NotificationStateLookupTest extends TestCase
{
    /** @var string a user id unique to this run; the other user is '1' . $user */
    private string $user = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs the database');
        }

        $this->user = 'nm-state-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->user === '') {
            return;
        }

        foreach ([$this->user, '1' . $this->user] as $user_id) {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->deleteFrom($this->table());
            $db->where('user_id', $user_id);
            $db->executeQuery();
        }
    }

    private function table(): string
    {
        return \OWA\Core\CoreAPI::entityFactory('base.notification_state')->getTableName();
    }

    /** @return list<array<string, mixed>> the user's state rows for the notification */
    private function rows(string $notification_id, string $user_id): array
    {
        return array_values((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            'SELECT * FROM %s WHERE notification_id = ? AND user_id = ?', $this->table()),
            [$notification_id, $user_id]));
    }

    public function testTheSeparatedIdsDiffer(): void
    {
        $this->assertNotSame((string) NM::stateId('900', '1' . $this->user), (string) NM::stateId('9001', $this->user));
    }

    public function testTwoPairsThatRunTogetherAreMarkedAndDismissedApart(): void
    {
        $one = '1' . $this->user;

        $this->assertTrue(NM::markRead('900', $one));

        $this->assertCount(1, $this->rows('900', $one));
        $this->assertSame([], $this->rows('9001', $this->user), 'marking one did not mark the other');

        $this->assertTrue(NM::dismiss('9001', $this->user));

        $theirs = $this->rows('9001', $this->user);
        $this->assertCount(1, $theirs);
        $this->assertGreaterThan(0, (int) $theirs[0]['dismissed_at']);

        $mine = $this->rows('900', $one);
        $this->assertCount(1, $mine);
        $this->assertGreaterThan(0, (int) $mine[0]['read_at']);
        $this->assertSame(0, (int) $mine[0]['dismissed_at'], 'dismissing the other did not dismiss this one');

        $this->assertSame((string) NM::stateId('900', $one), (string) $mine[0]['id']);
        $this->assertSame((string) NM::stateId('9001', $this->user), (string) $theirs[0]['id']);
    }

    public function testARowUnderTheOldIdIsFoundByItsColumns(): void
    {
        $state = \OWA\Core\CoreAPI::entityFactory('base.notification_state');
        $old   = $state->generateId('9001' . $this->user);

        $state->set('id', $old);
        $state->set('notification_id', '9001');
        $state->set('user_id', $this->user);
        $state->set('read_at', 0);
        $state->set('dismissed_at', 0);
        $this->assertTrue((bool) $state->create(), 'the old row must be written');

        $this->assertTrue(NM::markRead('9001', $this->user));
        $this->assertTrue(NM::dismiss('9001', $this->user));

        $rows = $this->rows('9001', $this->user);

        $this->assertCount(1, $rows, 'no second row was written beside it');
        $this->assertSame((string) $old, (string) $rows[0]['id']);
        $this->assertGreaterThan(0, (int) $rows[0]['read_at']);
        $this->assertGreaterThan(0, (int) $rows[0]['dismissed_at']);
    }
}
