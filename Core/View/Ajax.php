<?php
namespace OWA\Core\View;

/**
 * The response of a Controller\Ajax endpoint:
 * { "data": <payload or null>, "error": <message or null> }.
 *
 * index.php has already sent Cache-Control: private, no-store.
 */
class Ajax extends \OWA\Core\View {

    function render() {

        $this->t->set_template( 'wrapper_blank.php' );
        $this->body->set_template( 'json.php' );

        $this->body->set( 'json', json_encode( array(
            'data'  => $this->get( 'ajax_data' ) ?: null,
            'error' => $this->get( 'ajax_error' ) ?: null,
        ) ) );

        if ( ! headers_sent() ) {

            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'X-Content-Type-Options: nosniff' );
        }
    }
}
