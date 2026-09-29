<?php
namespace OWA\Module\Domstream\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The recordings report's body.
 *
 * setTemplateFile(), not set_template(): the latter always searches Base's
 * template directory, so a module's own template would render as nothing.
 */
class ReportDomstreams extends \OWA\Core\View {

    function render() {

        $this->body->setTemplateFile( 'domstream', 'report_domstreams.php' );

        foreach ( array( 'domstreams', 'domstreams_pagination', 'domstreams_total',
                         'domstreams_filter_dimensions', 'domstreams_filter_metrics',
                         'domstreams_constraints', 'domstreams_segment_error' ) as $key ) {

            $this->body->set( $key, $this->get( $key ) );
        }
    }
}

?>
