<?php
namespace OWA\Module\Base\Controller;

/**
 * Add Site allowed User REST Controller
 *
 * Adds a new allowed user to a site.
 *
 */
class SiteAddAllowedUserRest extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_sites');

        /*
         * Reached through index.php as well as the REST route, where a signed-in
         * admin's cookie alone must not be enough: a link they followed could grant
         * anyone access to any site. The REST route checks its own nonce for a
         * cookie sign-in, and none for an application password.
         */
        $this->setNonceRequired();

        parent::__construct($params);
    }

    public function validate() {

        // both identifiers must be present
        $this->addValidation('siteId', $this->getParam('siteId'), 'required', ['stopOnError'    => true]);
        $this->addValidation('user_id', $this->getParam('user_id'), 'required', ['stopOnError'  => true]);

        // the site must exist - rejects bogus siteIds before any relation is written
        $this->addValidation('siteId', $this->getParam('siteId'), 'entityExists', [
            'entity'    => 'base.site',
            'column'    => 'site_id',
            'errorMsg'  => $this->getMsg(3208),
            'stopOnError'   => true
        ]);

        // the user must exist - rejects bogus user_ids before any relation is written
        $this->addValidation('user_id', $this->getParam('user_id'), 'entityExists', [
            'entity'    => 'base.user',
            'column'    => 'user_id',
            'errorMsg'  => $this->getMsg(3001),
            'stopOnError'   => true
        ]);
    }

    public function action() {

        $site_id = $this->getParam( 'siteId' );
        $s = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

        $user_id = $this->getParam( 'user_id' );
        $u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $u->load( $user_id, 'user_id' );

        // defense in depth: never write a relation with an unresolved foreign key.
        // validate() should already have rejected non-existent entities.
        if ( ! $s->wasPersisted() || ! $u->wasPersisted() ) {

            $this->set( 'response', [ 'error' => \OWA\Core\CoreAPI::t( 'The site or user does not exist.' ) ] );
            $this->errorAction();

            // return non-empty data so finishActionCall() does not fall through
            // to success() and overwrite the error response code.
            return $this->data;
        }

        $relation = \OWA\Core\CoreAPI::entityFactory( 'base.site_user' );
        $relation->set( 'user_id', $u->get( 'id' ) );
        $relation->set( 'site_id', $s->get( 'id' ) );
        $relation->save();

        $response = [

            'site'          => $s->getProperties(),
            'allowed_user'  => $u->getProperties( ['password', 'temp_passkey', 'session_epoch'] )
        ];

        $this->set('response', $response);

    }
    
    public function success() {

        $this->restResponse( 201, 'response' );
    }

    public function errorAction() {

        $this->restResponse( 422, 'response' );
    }

}
