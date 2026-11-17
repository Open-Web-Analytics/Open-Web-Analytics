<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The funnel builder.
 *
 * The KIND was answered before this screen opened -- in New's "What do you
 * want to make?" -- because the kind decides what this form asks for. It is
 * posted, so an unchanged edit cannot fall back to a default, and it cannot be
 * changed: steps mean nothing to another kind.
 *
 * The header is the funnel's name, Shared and Cancel / Save, as on the report
 * editor. Under it, the steps and the filter at the left and a preview at the
 * right: the funnel counted as it stands, from the steps on the screen, over
 * the last 30 days (base.visualizationPreview).
 *
 * Every step is an event and conditions on it (Classes\FunnelStep): a page is
 * a page_view with a page_path condition, which is what a new step starts as.
 * The form posts the steps as parallel arrays -- stepEvent[],
 * stepCustomEvent[], stepMatch[], stepName[] -- in row order, so the order on
 * the screen is the order saved, and each step's conditions as
 * stepConditionProperty[N][] and its siblings, N the step's position
 * (VisualizationSave::rowsFromRequest()). A step's name is not asked for. A
 * stored name rides along in stepName[] while the step it names is unchanged,
 * and a step with none is named for its page or its event.
 */
$owa_v      = (array) $view->visualization;
$owa_type   = (string) ( $view->visualizationType ?: 'funnel' );
$owa_id     = (string) ( $owa_v['id'] ?? '' );
$owa_errors = (array) $view->validation_errors;
$owa_steps  = (array) $view->steps ?: array( (array) $view->blankStep );

// The errors each step row shows; the rest are shown under the steps.
$owa_shownErrors = array( 'name' => true, 'constraints' => true );
?>

<?php
/*
 * An explicit action URL, not a hidden owa_action field: a report page does
 * not route the admin way, and posting so ran the save then rendered nothing.
 */
?>
<form method="post" name="owa_visualization" class="owa_editor owa_funnelEditor" id="owa_funnelForm"
      action="<?php echo $view->makeLink( array( 'do' => 'base.visualizationSave' ) );?>"
      data-not-carried-label="<?php $view->out( \OWA\Core\CoreAPI::t( '%s -- not carried by %s' ) );?>"
      data-not-carried-help="<?php $view->out( \OWA\Core\CoreAPI::t( 'A %s event does not carry this property.' ) );?>">

    <?php echo $view->createNonceFormField( 'base.visualizationSave' );?>
    <input type="hidden" name="visualizationId" value="<?php $view->out( $view->visualizationId ?? '' );?>">
    <input type="hidden" name="visualizationType" value="<?php $view->out( $owa_type );?>">
    <?php // The saved funnel opens on this Property, as a saved report does. ?>
    <input type="hidden" name="propertyId" value="<?php $view->out( $view->get( 'propertyId' ) );?>">
    <?php // The filter, written by the script from its pills. ?>
    <input type="hidden" name="constraints" id="owa_funnelConstraints"
           value="<?php $view->out( (string) $view->constraints );?>">

    <div class="owa_editorHead">
        <div class="owa_editorName">
            <div class="owa_builderFieldLabel">
                <label for="owa_funnelName"><?php $view->out( \OWA\Core\CoreAPI::t( 'Funnel name' ) ); ?></label>
                <span class="owa_builderRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span>
            </div>
            <input type="text" id="owa_funnelName" maxlength="255"
                   name="name"
                   placeholder="<?php $view->out( \OWA\Core\CoreAPI::t( 'Untitled funnel' ) ); ?>"
                   value="<?php $view->out( $owa_v['name'] ?? '' );?>">
            <?php if ( ! empty( $owa_errors['name'] ) ):?>
            <span class="validation_error"><?php $view->out( $owa_errors['name'] );?></span>
            <?php endif;?>
        </div>

        <?php // Listed to and opened by others, or by its author alone (CustomReports::mayView()). ?>
        <label class="owa_switch" for="owa_funnelShared">
            <input type="checkbox" role="switch" id="owa_funnelShared" value="1"
                   name="isShared"
                   aria-describedby="owa_funnelSharedHint"
                   <?php echo ! empty( $owa_v['is_shared'] ) ? 'checked="checked"' : '';?>>
            <span class="owa_switchTrack" aria-hidden="true"></span>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'Shared in everyone\'s list' ) ); ?>
        </label>
        <span id="owa_funnelSharedHint" class="owa_srOnly"><?php $view->out( \OWA\Core\CoreAPI::t( 'Off, it opens only for you and administrators, even from a link you have already sent.' ) ); ?></span>

        <div class="owa_editorActions">
            <a class="owa_button" id="owa_funnelCancel" href="<?php echo $owa_id
                ? $view->makeLink( array( 'do' => 'base.report',
                    'reportId' => \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX . $owa_id ), true )
                : $view->makeLink( array( 'do' => 'base.customReports' ), true );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Cancel' ) ); ?></a>
            <input class="owa_button is-primary" type="submit" id="owa_visualizationSubmit"
                   name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save funnel' ) ); ?>">
        </div>
    </div>

    <?php // Why Save will not go, from the same rules the save applies. ?>
    <p id="owa_visualizationBlocker" class="owa_builderBlocker" role="status" style="display:none;"></p>

    <div class="owa_funnelColumns">

        <section class="owa_funnelBuild" aria-labelledby="owa_funnelStepsHeading">

            <h2 id="owa_funnelStepsHeading"><?php $view->out( \OWA\Core\CoreAPI::t( 'Steps' ) ); ?> <span class="owa_builderRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span></h2>
            <p class="owa_builderHelp"><?php $view->out( \OWA\Core\CoreAPI::t( 'In order, first to last. Each step is an event, and optionally what it carries: a page is a page_view with its page path. A step with no conditions matches every event of that name.' ) );?></p>

            <ol class="owa_funnelStepList" id="owa_funnelSteps">
            <?php foreach ( array_values( $owa_steps ) as $owa_stepIndex => $owa_step ):?>
            <?php
                $owa_step       = (array) $owa_step;
                $owa_stepNo     = (int) $owa_stepIndex + 1;
                $owa_stepErrors = array();

                // The step's own messages: stepEvent<N>, and stepCondition<N>_<C> for each condition.
                foreach ( $owa_errors as $owa_field => $owa_message ) {
                    if ( $owa_field === 'stepEvent' . $owa_stepNo
                         || strpos( (string) $owa_field, 'stepCondition' . $owa_stepNo . '_' ) === 0 ) {
                        $owa_shownErrors[ $owa_field ] = true;
                        $owa_stepErrors[] = $owa_message;
                    }
                }

            ?>
                <?php include( 'visualization_edit_step.php' ); ?>
            <?php endforeach;?>
            </ol>

            <button type="button" class="owa_button owa_funnelAdd" id="owa_funnelAddStep">
                <i class="fas fa-plus" aria-hidden="true"></i> <?php $view->out( \OWA\Core\CoreAPI::t( 'Add a step' ) ); ?></button>

            <?php foreach ( array_diff_key( $owa_errors, $owa_shownErrors ) as $owa_otherError ):?>
            <p class="validation_error"><?php $view->out( $owa_otherError );?></p>
            <?php endforeach;?>

            <h2 id="owa_funnelFiltersLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Filters' ) ); ?></h2>
            <?php
                $owa_fp = array(
                    'pills' => 'owa_funnelFilters', 'add' => 'owa_funnelAddFilter', 'form' => 'owa_funnelFilterForm',
                    'dimension' => 'owa_funnelFilterDimension', 'operator' => 'owa_funnelFilterOperator',
                    'value' => 'owa_funnelFilterValue', 'addFilter' => 'owa_funnelFilterAdd',
                    'cancel' => 'owa_funnelFilterCancel', 'label' => 'owa_funnelFiltersLabel',
                );
                include( 'filter_pills.php' );
            ?>
            <p class="owa_builderHelp"><?php $view->out( \OWA\Core\CoreAPI::t( 'A filter applies to every step.' ) ); ?></p>
            <?php if ( ! empty( $owa_errors['constraints'] ) ):?>
            <p class="validation_error"><?php $view->out( $owa_errors['constraints'] );?></p>
            <?php endif;?>
        </section>

        <section class="owa_funnelPreview" aria-labelledby="owa_funnelPreviewHeading">
            <h2 id="owa_funnelPreviewHeading"><?php $view->out( \OWA\Core\CoreAPI::t( 'Preview' ) ); ?></h2>
            <p class="owa_funnelPreviewNote"><?php $view->out( \OWA\Core\CoreAPI::t( 'Sessions that reached each step, in order, last 30 days.' ) ); ?></p>
            <div id="owa_funnelPreview" aria-live="polite"></div>
        </section>
    </div>

    <?php // One blank step, for Add a step to copy. ?>
    <template id="owa_funnelStepTemplate">
        <?php
            $owa_step = (array) $view->blankStep; $owa_stepIndex = 0; $owa_stepNo = 0; $owa_stepErrors = array();
            include( 'visualization_edit_step.php' );
        ?>
    </template>
    <?php // Every event's condition properties, so a step's pickers follow its event (owa.goalbuilder.js). ?>
    <script type="application/json" id="owa_funnelVocabulary"><?php
        echo $view->scriptJson( (array) $view->conditionVocabulary );
    ?></script>
</form>

<?php if ( $owa_id ):?>
<div class="owa_editorFoot">
    <form method="post" action="<?php echo $view->makeLink( array( 'do' => 'base.customReportDelete' ) );?>">
        <?php echo $view->createNonceFormField( 'base.customReportDelete' );?>
        <input type="hidden" name="customReportId" value="<?php $view->out( $owa_id );?>">
        <?php // A roster with no Property has no navigation, so the Property goes along. ?>
        <input type="hidden" name="propertyId" value="<?php $view->out( $view->get( 'propertyId' ) );?>">
        <input class="owa_builderDelete" type="submit"
               name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this funnel' ) ); ?>"
               data-owa-confirm
               data-owa-confirm-title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this funnel?' ) ); ?>"
               data-owa-confirm-body="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '“%s” disappears from the list. Nothing it counted is removed.' ), $owa_v['name'] ?? '' ) ); ?>"
               data-owa-confirm-proceed="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete funnel' ) ); ?>">
    </form>
</div>
<?php endif;?>

<?php // The screen's data, for owa.visualizationEdit.js. ?>
<script type="application/json" data-ui="funnel-editor:config"><?php echo $view->scriptJson( array(
    'maxSteps'   => (int) ( $view->maxSteps ?: 10 ),
    'dimensions' => array_values( (array) $view->dimensionChoices ),
    'previewUrl' => $view->makeLink( array(
        'do'          => 'base.visualizationPreview',
        'period'      => 'last_thirty_days',
        'funnelScope' => 'session',
    ), true ),
) ); ?></script>
<p id="owa_funnelMoved" class="owa_srOnly" aria-live="polite"></p>
