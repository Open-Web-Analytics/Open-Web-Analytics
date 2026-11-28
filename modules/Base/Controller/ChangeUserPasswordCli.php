<?php
namespace OWA\Module\Base\Controller;

/**
 * Change user password cli Controller
 */
class ChangeUserPasswordCli extends \OWA\Core\Controller\Cli
{

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Sets a user\'s password.',
            'arguments'   => [
                'user=<user id>'      => 'Required. The user name of the account.',
                'password=<password>' => 'Required. The new password, at least ' . \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH . ' characters and at most ' . \OWA\Module\Base\Entity\User::MAX_PASSWORD_BYTES . ' bytes.',
            ],
        ];
    }
    /**
     * @var \OWA\Module\Base\Classes\UserManager
     */
    private $_userManager;

    /**
     * owa_changeUserPasswordCliController constructor.
     * @param $params
     */
    public function __construct($params)
    {
        parent::__construct($params);

        $this->setRequiredCapability('edit_settings');

        $this->_userManager = new \OWA\Module\Base\Classes\UserManager();
    }

    public function validate()
    {
        $this->addValidation('user_required', $this->getParam('user'), 'required');
        $this->addValidation('password_required', $this->getParam('password'), 'required');

        $passwordLengthConf = [
            'operator'  => '>=',
            'length'    => \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH,
            'errorMsg'  => sprintf( 'Your password must be at least %d characters in length.', \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH ),
        ];

        // 'stringLength' (not 'required') is the validator that actually reads
        // the operator/length config above; typed as 'required' the length rule
        // silently degraded to a bare non-empty check and short passwords passed.
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', $passwordLengthConf);
        $this->addValidation('password_max_length', $this->getParam('password'), 'stringLength', [
            'operator' => '<=',
            'length'   => \OWA\Module\Base\Entity\User::MAX_PASSWORD_BYTES,
            'errorMsg' => \OWA\Module\Base\Entity\User::passwordTooLongMessage(false),
        ]);
    }

    /**
     *
     */
    public function action()
    {
        $user = $this->getParam('user');
        $password = $this->getParam('password');

        $status = $this->_userManager->updateUserPassword([
            'user_id' => $user,
            'password' => $password,
        ]);

        if ($status !== false) {
            \OWA\Core\CoreAPI::notice( "Updated user password successfully." );
            return;
        }

        $this->fail( "User password update failed." );
    }

    public function errorAction()
    {
        \OWA\Core\Controller\Cli::reportInvalidArguments( $this->data['validation_errors'] ?? [] );
    }
}
