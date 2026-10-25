<?php
namespace OWA\Module\Base\Controller;

/**
 * The funnel builder's live preview: the counts for steps that are not saved.
 *
 * The funnel itself, counted the same way, from the steps and filter the
 * builder sends rather than from a stored row -- so the preview cannot count
 * differently from the funnel the author is about to save. A read, like the
 * funnel: it asks for view_reports on the Property and changes nothing.
 *
 *   steps          JSON: [{kind: path|goal_event, path, goal_event_id, name}]
 *   funnelFilter   the funnel's own filter, in the stored constraints format
 *
 * Answers {data: {scope, steps: [{name, reached}]}} or {error}.
 */
class VisualizationPreview extends VisualizationFunnel {

    protected function visualization() {

        $posted = json_decode( (string) $this->getParam( 'steps' ), true );
        $posted = is_array( $posted ) ? array_slice( array_values( $posted ), 0, VisualizationSave::MAX_STEPS ) : array();

        $column = static function ( $key ) use ( $posted ) {

            return array_map( static function ( $step ) use ( $key ) {
                return is_array( $step ) && is_scalar( $step[ $key ] ?? '' ) ? (string) ( $step[ $key ] ?? '' ) : '';
            }, $posted );
        };

        return array(
            'name'       => '',
            'definition' => array(
                'steps'       => VisualizationSave::stepsFromForm(
                    $column( 'name' ), $column( 'kind' ), $column( 'path' ), $column( 'goal_event_id' ) ),
                'constraints' => (string) $this->getParam( 'funnelFilter' ),
            ),
        );
    }

    function action() {

        $problem = \OWA\Module\Base\Classes\CustomReports::constraintsProblem(
            (string) $this->getParam( 'funnelFilter' ), \OWA\Core\CoreAPI::t( 'The funnel' ) );

        if ( $problem !== '' ) {

            $this->set( 'ajax_error', $problem );
            return;
        }

        parent::action();

        $steps = array();

        foreach ( (array) ( $this->data['funnel'] ?? array() ) as $step ) {

            $steps[] = array(
                'name'    => (string) ( $step['name'] ?? '' ),
                'reached' => (int) ( $step['visitors'] ?? 0 ),
            );
        }

        $this->set( 'ajax_error', $this->data['funnel_segment_error'] ?? null );
        $this->set( 'ajax_data', array(
            'scope' => (string) ( $this->data['funnel_scope_label'] ?? '' ),
            'steps' => $steps,
        ) );
    }

    function post() {

        parent::post();

        // JSON, not the report page the funnel would be.
        $this->setView( 'base.ajax' );
    }
}
