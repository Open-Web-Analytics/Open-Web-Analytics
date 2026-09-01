<?php

namespace OWA\Module\Base\View;

class PropertyAccess extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Property Access Management' );
        $this->body->set_template( 'property_access.php' );
        $this->body->set( 'site', $this->get( 'site' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
        $this->body->set( 'users', $this->getAllUserRows() );
    }

    /*
     * Every user, because the form REPLACES the whole grant set: a user missing
     * from the rendered list is a user revoked on submit. Same query the site
     * profile view used when this form lived on that page -- hardcoded table
     * name and all, kept identical so the split changed no behaviour.
     */
    private function getAllUserRows() {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( 'owa_user' );
        $db->selectColumn( '*' );

        return $db->getAllRows();
    }
}

?>
