<?php
namespace OWA\Module\Base\Controller;

/**
 * Password Reset Request Controller
 */

class PasswordResetForm extends \OWA\Core\Controller {

    public function action() {

        $this->setView( 'base.passwordResetForm', self::page() );
    }

    /**
     * The form, which PasswordResetRequest draws again with its outcome.
     *
     * @return array<string, mixed> as setSubview() takes it
     */
    public static function page() {

        return [
            'template' => 'users_password_reset_request.php',
            'wrapper'  => 'wrapper_public.php',
            'title'    => \OWA\Core\CoreAPI::t( 'Password Reset' ),
        ];
    }
}
