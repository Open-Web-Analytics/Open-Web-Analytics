<?php

namespace OWA\Module\Base\View;

class PropertyAccess extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Property Access Management' );
        $this->body->set_template( 'property_access.php' );
        $this->body->set( 'property', $this->get( 'property' ) );
        $this->body->set( 'propertyId', $this->get( 'propertyId' ) );

        /*
         * Who is granted, decided at the Property (PropertyGrants). Without it
         * the form rendered every checkbox unchecked, and submitting it would
         * have revoked everyone shown.
         */
        $this->body->set( 'grantedUserIds', array_map( 'intval', (array) $this->get( 'grantedUserIds' ) ) );

        /*
         * Always an edit: this screen only exists for a Property that already
         * exists. The flag is the site page's, which served add and edit from
         * one template.
         */
        $this->body->set( 'edit', true );
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
