<?php
namespace OWA\Module\Base\Controller;

/**
 * The funnel builder's live preview: the counts for steps that are not saved.
 *
 * The funnel itself, counted the same way, from the steps and filter the
 * builder sends rather than from a stored row -- so the preview cannot count
 * differently from the funnel the author is about to save. It changes
 * nothing, and asks for edit_reports, as saving does: the steps are the
 * caller's, so the query is too, run for as long as it is allowed
 * (ResultSetManager::QUERY_SECONDS).
 *
 *   steps          JSON: [{name, event, match, conditions: [{property, operator, value}]}],
 *                  the event already the one named, for "another event of yours"
 *   funnelFilter   the funnel's own filter, in the stored constraints format
 *
 * Answers {data: {scope, steps: [{name, reached}]}} or {error}.
 */
class VisualizationPreview extends VisualizationFunnel {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_reports' );
    }

    /**
     * @return array{name: string, definition: array{steps: list<array<string, mixed>>, constraints: string}}
     */
    protected function visualization() {

        $posted = json_decode( (string) $this->getParam( 'steps' ), true );
        $posted = is_array( $posted ) ? array_slice( array_values( $posted ), 0, VisualizationSave::MAX_STEPS ) : [];

        return [
            'name'       => '',
            'definition' => [
                'steps'       => VisualizationSave::stepsFromForm( array_values( array_filter( $posted, 'is_array' ) ) ),
                'constraints' => (string) $this->getParam( 'funnelFilter' ),
            ],
        ];
    }

    public function action() {

        foreach ( $this->visualization()['definition']['steps'] as $i => $step ) {

            $too_many = \OWA\Module\Base\Classes\FunnelStep::tooManyConditions( $step, $i + 1 );

            if ( $too_many !== '' ) {

                $this->set( 'ajax_error', $too_many );
                return;
            }
        }

        $problem = \OWA\Module\Base\Classes\CustomReports::constraintsProblem(
            (string) $this->getParam( 'funnelFilter' ), \OWA\Core\CoreAPI::t( 'The funnel' ),
            (string) $this->getParam( 'propertyId' ) );

        if ( $problem !== '' ) {

            $this->set( 'ajax_error', $problem );
            return;
        }

        parent::action();

        $steps = [];

        foreach ( (array) ( $this->data['funnel'] ?? [] ) as $step ) {

            $steps[] = [
                'name'    => (string) ( $step['name'] ?? '' ),
                'reached' => (int) ( $step['visitors'] ?? 0 ),
            ];
        }

        $this->set( 'ajax_error', $this->data['funnel_segment_error'] ?? $this->data['funnel_step_error'] ?? null );
        $this->set( 'ajax_data', [
            'scope' => (string) ( $this->data['funnel_scope_label'] ?? '' ),
            'steps' => $steps,
        ] );
    }

    public function post() {

        parent::post();

        // JSON, not the report page the funnel would be.
        $this->setView( 'base.ajax' );
    }
}
