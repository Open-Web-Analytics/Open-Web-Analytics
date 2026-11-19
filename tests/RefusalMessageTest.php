<?php

use OWA\Module\Base\Controller\CustomReportEdit;
use OWA\Module\Base\Controller\MyProfile;
use OWA\Module\Base\Controller\MyProfileSave;
use PHPUnit\Framework\TestCase;

/**
 * The reason a refused save is redrawn with comes from OWA, not the request.
 *
 * base.myProfileSave redirects to base.myProfile with message codes from
 * conf/messages.php, and that screen shows only those codes' messages.
 * base.customReportSave hands base.customReportEdit its reason in-process, so
 * the builder reads none from the request.
 */
final class RefusalMessageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    public function testTextInMyProfileErrorIsNotShown(): void
    {
        $screen = new MyProfile([]);

        $this->assertSame('', $screen->refusalText('Your session expired. Call 555-0100.'));
        $this->assertSame('', $screen->refusalText('3006'), 'a code that is not a profile refusal');
        $this->assertSame('', $screen->refusalText('3020x'));
    }

    public function testAProfileRefusalCodeShowsItsMessage(): void
    {
        $screen = new MyProfile([]);

        $this->assertSame('That is not your current password.', $screen->refusalText('3022'));
        $this->assertSame('The new passwords do not match. The new password must be at least '
            . MyProfileSave::MIN_PASSWORD_LENGTH . ' characters.', $screen->refusalText('3027,3028'));
        $this->assertSame('Enter the new password.', $screen->refusalText('3026,hello'));
    }

    public function testEveryProfileRefusalHasAMessage(): void
    {
        $screen = new MyProfile([]);

        foreach (MyProfileSave::REFUSALS as $code) {
            $this->assertNotSame('', $screen->refusalText((string) $code), "code $code");
        }

        $this->assertSame([], array_diff(MyProfileSave::VALIDATION_REFUSALS, MyProfileSave::REFUSALS),
            'each validation is refused with a listed code');
    }

    public function testAProfileRefusalRedirectsWithCodes(): void
    {
        $save = new class(['current_password' => 'x']) extends MyProfileSave {
            public function getValidationErrorMsgs()
            {
                return ['password_match' => 'text', 'new_password' => 'text'];
            }
        };

        $save->errorAction();

        $this->assertSame('base.myProfile', $save->data['do']);
        $this->assertSame('3026,3027', $save->data['myProfileError']);
    }

    public function testTheBuilderDoesNotReadAReasonFromTheRequest(): void
    {
        $edit = new CustomReportEdit(['customReportError' => 'Your session expired. Call 555-0100.']);

        $edit->action();

        $this->assertArrayNotHasKey('custom_report_error', $edit->data);
    }

    public function testTheBuilderShowsTheReasonTheSaveGaveIt(): void
    {
        $edit = new CustomReportEdit([]);

        $edit->refuseWith('A custom report needs a name and at least one widget.')->action();

        $this->assertSame('A custom report needs a name and at least one widget.', $edit->data['custom_report_error']);
    }
}
