<?php

namespace OWA\Module\Base\View;

/**
 * The custom report builder.
 *
 * Every value the template reads is forwarded BY NAME, because the body is a
 * separate template with its own scope: a value the controller set but this
 * method does not mention is simply absent, and a missing template variable is
 * an undefined rather than an error.
 *
 * @since owa 1.8.0
 */
class CustomReportEdit extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Custom Report Builder' );

        $this->body->set_template( 'custom_report_edit.php' );

        $this->body->set( 'siteId', $this->get( 'siteId' ) );
        $this->body->set( 'custom_report_id', $this->get( 'custom_report_id' ) );
        $this->body->set( 'custom_report_name', $this->get( 'custom_report_name' ) );
        $this->body->set( 'custom_report_definition', $this->get( 'custom_report_definition' ) );
        $this->body->set( 'custom_report_error', $this->get( 'custom_report_error' ) );

        $this->body->set( 'metric_choices', $this->get( 'metric_choices' ) );
        $this->body->set( 'dimension_choices', $this->get( 'dimension_choices' ) );
        $this->body->set( 'widget_types', $this->get( 'widget_types' ) );
        $this->body->set( 'max_widgets', $this->get( 'max_widgets' ) );
    }
}
