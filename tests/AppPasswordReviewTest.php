<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Module\Base\Classes\AppPasswords;
use OWA\Module\Base\Classes\NotificationManager;

/**
 * A password change does not revoke the account's application passwords;
 * it puts them in front of the account to review (AppPasswords::askForReview()),
 * however the password was changed: every way goes through
 * UserManager::updateUserPassword().
 */
final class AppPasswordReviewTest extends RestControllerTestCase
{
    /** @var string */
    private $user_id = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = $this->makeUser('viewer', 'review')['user_id'];
    }

    protected function tearDown(): void
    {
        if ($this->user_id !== '') {
            AppPasswords::revokeAllForUser($this->user_id);
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE source = 'app_password_review' AND user_id = ?",
                \OWA\Core\CoreAPI::entityFactory('base.notification')->getTableName()), [$this->user_id]);
        }

        parent::tearDown();
    }

    private function changePassword(): void
    {
        $this->assertNotFalse((new \OWA\Module\Base\Classes\UserManager())->updateUserPassword(
            ['user_id' => $this->user_id, 'password' => 'a-new-password-' . $this->tok]));
    }

    /** @return list<string> */
    private function notices(): array
    {
        return array_column(NotificationManager::undismissedFor($this->user_id), 'title');
    }

    public function testAChangeAsksForAReviewAndRevokesNothing(): void
    {
        $made = AppPasswords::create($this->user_id, 'Export script', 'read');
        AppPasswords::create($this->user_id, 'Dashboard', 'read');

        $this->changePassword();

        $this->assertNotNull(AppPasswords::verify($made['token']), 'still works');
        $this->assertSame(['Your password was changed. Review your 2 application passwords.'], $this->notices());
    }

    public function testAnAccountWithNoneIsNotAsked(): void
    {
        $this->changePassword();

        $this->assertSame([], $this->notices());
    }
}
