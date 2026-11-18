<?php
namespace OWA\Module\Base\View;

/**
 * Funnel Report View
 */

class VisualizationFunnel extends \OWA\Core\View {

    /**
     * @return void
     */
    public function render() {

        $this->body->set_template('visualization_funnel.php');
        $this->body->set('funnel', $this->get('funnel'));
        $this->body->set('funnel_json', json_encode($this->get('funnel')));
        $this->body->set('completion_rate', $this->get('completion_rate'));
        $this->body->set( 'visualization_id', $this->get( 'visualization_id' ) );

        /*
         * The scope toggle and any segment complaint.
         *
         * Listed here because this view hands the body each variable by name --
         * a template reading one this method does not copy sees nothing at all,
         * which is silent.
         */
        $this->body->set('funnel_scope',         $this->get('funnel_scope') );
        $this->body->set('funnel_scope_other',   $this->get('funnel_scope_other') );
        $this->body->set('funnel_scope_label',   $this->get('funnel_scope_label') );
        $this->body->set('funnel_segment_error', $this->get('funnel_segment_error') );
        // The funnel's own filter, in words: it narrows every count below. A
        // custom dimension in it is named by its Property's label.
        $this->body->set('funnel_saved_filter', \OWA\Module\Base\Classes\CustomReports::constraintsInWords(
            (string) $this->get('funnel_saved_constraints'), (string) $this->get('currentPropertyId') ) );
        // A step the funnel cannot count: no event, or a property it cannot test. Refused
        // rather than drawn with that step ignored -- see stepPredicate().
        $this->body->set('funnel_step_error',    $this->get('funnel_step_error') );
        // Handed to owa.rowsReport.js by the template.
        $this->body->set('funnel_table', $this->get('funnel_table') );
        $this->body->set('funnel_filter', [
            'dimensions'  => $this->get('funnel_filter_dimensions'),
            'metrics'     => $this->get('funnel_filter_metrics'),
            'constraints' => $this->get('funnel_constraints'),
        ] );
    }
}
