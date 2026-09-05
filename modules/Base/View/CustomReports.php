<?php

namespace OWA\Module\Base\View;

/**
 * The custom report roster.
 *
 * Every value the template reads is forwarded BY NAME: the body is a separate
 * template with its own scope, so a value the controller set but this method
 * does not mention simply is not there -- and a missing template variable is
 * not an error, it is an undefined that reaches whatever reads it.
 *
 * @since owa 1.8.0
 */
class CustomReports extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Custom Reports' );

        $this->body->set_template( 'custom_reports.php' );

        $this->body->set( 'custom_reports', $this->get( 'custom_reports' ) );
        $this->body->set( 'roster_type', $this->get( 'roster_type' ) );
        // Empty on the reports roster, which is what stops it rendering the
        // "which kind" modal that only visualizations have.
        $this->body->set( 'visualization_types', $this->get( 'visualization_types' ) );
        $this->body->set( 'sees_all', $this->get( 'sees_all' ) );
        $this->body->set( 'may_author', $this->get( 'may_author' ) );
        $this->body->set( 'current_user_id', $this->get( 'current_user_id' ) );
        $this->body->set( 'roster_sort', $this->get( 'roster_sort' ) );
        $this->body->set( 'roster_desc', $this->get( 'roster_desc' ) );
    }
}
