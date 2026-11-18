<?php
namespace OWA\Module\Base\Controller;

/**
 * Edit User Controller
 */

class UsersEdit extends \OWA\Core\AdminController {

    public function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // check that user_id is present
        $this->addValidation('user_id', $this->getParam('user_id'), 'required');

        // Checked when one is posted: the form posts none for the admin user.
        if ( $this->getParam('role') !== null && $this->getParam('role') !== '' ) {

            $roles = \OWA\Core\CoreAPI::getAssignableRoles();
            $this->addValidation('role', $this->getParam('role'), 'inArray', [
                'possible_values' => $roles,
                'errorMsg'        => sprintf( \OWA\Core\CoreAPI::t( 'A user\'s role is one of: %s.' ), implode( ', ', $roles ) ),
                'stopOnError'     => true,
            ] );
        }

        // Check user name exists
        $userEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'user_id',
            'errorMsg'  => $this->getMsg(3001)
        ];

        $this->addValidation('user_id', $this->getParam('user_id'), 'entityExists', $userEntityConf);
    }

    public function action() {

        // This needs form validation in a bad way.

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $this->getParam('user_id'));
        $u->set('email_address', $this->getParam('email_address'));

        // never change the role of the admin user
        if (!$u->isOWAAdmin()) {
            $u->set('real_name', $this->getParam('real_name'));
            $u->set('role', $this->getParam('role'));
        }


        $u->update();
        $this->setStatusCode( 3003 );
        $this->setRedirectAction('base.users');
    }

    public function errorAction() {
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview('base.usersProfile');
        /*
         * 3002 -- "the form had errors" -- not 3311, which is the CLI-updates
         * message. Controller::doAction() sets validation_errors before calling
         * errorAction() and msgs.php shows error_msg only when there are none, so
         * a wrong code here shows only once that branch changes.
         */
        $this->set('error_code', 3002);
        $this->set('user', $this->params);
    }

}
