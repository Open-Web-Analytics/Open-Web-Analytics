<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One visualization.
 *
 * The KIND was answered before this screen opened -- in the modal on the
 * roster, the same order the widget builder puts the question in, because the
 * kind decides what this form then asks for. So it is STATED here, not asked
 * again: two places to answer one question is a way for the two to disagree.
 *
 * And it cannot be changed. The definition a kind produces means nothing to
 * another kind, so re-typing a funnel would leave steps that whatever computes
 * it does not read. Building the other kind is how to get the other kind.
 */
$owa_v     = (array) $view->visualization;
$owa_type  = (string) ( $view->visualizationType ?: 'funnel' );
?>
<div class="owa_panelIntro">A visualization computes something a report cannot arrange from
metrics and dimensions. A funnel counts how many people reached each step of a path, in order,
and where they left.</div>

<?php
/*
 * An explicit action URL, not a hidden owa_action field.
 *
 * The admin screens post to the current URL and name the action in a hidden
 * field. A REPORT page does not route that way -- the custom report builder
 * posts to makeLink( do => ... ) -- and posting the admin way ran the save but
 * then rendered an empty document, because nothing had resolved a view.
 */
?>
<form method="post" name="owa_visualization"
      action="<?php echo $view->makeLink( array( 'do' => 'base.visualizationSave' ) );?>">

    <div class="setting">
        <div class="title">Name</div>
        <div class="description">What this is called in the Visualizations list and its own
        heading.</div>
        <div class="field">
            <input class="owa_largeFormField" type="text" maxlength="255"
                   name="<?php echo $view->getNs();?>name"
                   value="<?php $view->out( $owa_v['name'] ?? '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['name'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Type</div>
        <div class="description">What kind of visualization this is. Each kind computes its own
        numbers, so it decides what this form asks for &mdash; which is why it is chosen before
        the form opens and cannot be changed afterwards.</div>
        <div class="field">
            <span class="owa_statedValue">
                <i class="<?php $view->out( (string) $view->visualizationTypeIcon );?>"
                   aria-hidden="true"></i>
                <?php $view->out( (string) $view->visualizationTypeLabel );?>
            </span>
            <div class="owa_statedValueHint"><?php
                $view->out( (string) $view->visualizationTypeHint );?></div>
            <?php
                /*
                 * Posted as a hidden field, not inferred at the save.
                 *
                 * The save writes visualization_type, and a form that shows a
                 * kind but does not send it would have the controller pick the
                 * default -- so an unchanged edit could quietly change what
                 * computes the row.
                 */
            ?>
            <input type="hidden" name="<?php echo $view->getNs();?>visualizationType"
                   value="<?php $view->out( $owa_type );?>">
        </div>
    </div>

    <div class="setting">
        <div class="title">Steps</div>
        <div class="description">In order, first to last. A step is either a page &mdash; matched
        on its path &mdash; or one of this Property's goal events, which counts a step exactly
        where that goal event would have counted a conversion. A step with nothing chosen is
        ignored, so a row left blank costs nothing.</div>
        <div class="field">
            <ul class="constraintList owa_goalEventFunnel" id="owa_goalEventFunnel" data-owa-repeatable>
            <?php
            /* Always one row, or there is nowhere to type the first step. */
            $owa_steps = $view->steps ?: array( array( 'name' => '', 'path' => '' ) );
            ?>
            <?php foreach ( $owa_steps as $owa_step ):?>
            <?php $owa_stepGoal = (string) ( $owa_step['goal_event_id'] ?? '' );?>
                <li class="constraintRow owa_funnelStep">
                    <input class="constraintValueField owa_funnelStepName" type="text"
                           placeholder="Step name"
                           name="<?php echo $view->getNs();?>stepName[]"
                           value="<?php $view->out( $owa_step['name'] ?? '' );?>">
                    <?php
                        /*
                         * WHAT the step is, then the value it needs.
                         *
                         * A step is a condition, and a goal event is a
                         * condition somebody already named -- so naming one
                         * here means exactly what writing its conditions into
                         * the step by hand would mean. The counting compiles
                         * both to the same predicate; see GoalEventPredicate.
                         *
                         * It reads as the operator slot of a constraint row
                         * because that is what it is: the same three-part
                         * sentence the report builder writes, subject / verb /
                         * value.
                         */
                    ?>
                    <span class="constraintOperatorPicker owa_funnelStepMatches">
                        <select class="owa_funnelStepKind" name="<?php echo $view->getNs();?>stepKind[]">
                            <option value="path" <?php echo $owa_stepGoal === '' ? 'selected' : '';?>>is the page</option>
                            <option value="goal_event" <?php echo $owa_stepGoal !== '' ? 'selected' : '';?>>is the goal event</option>
                        </select>
                    </span>
                    <input class="constraintValueField owa_funnelStepPath" type="text"
                           placeholder="/path"
                           name="<?php echo $view->getNs();?>stepPath[]"
                           value="<?php $view->out( $owa_step['path'] ?? '' );?>">
                    <select class="constraintValueField owa_funnelStepGoal"
                            name="<?php echo $view->getNs();?>stepGoalEventId[]">
                        <option value="">Choose a goal event&hellip;</option>
                        <?php foreach ( (array) $view->goalEvents as $owa_ge ):?>
                            <option value="<?php $view->out( $owa_ge['id'] );?>"
                                <?php echo $owa_stepGoal === (string) $owa_ge['id'] ? 'selected' : '';?>>
                                <?php $view->out( $owa_ge['name'] );?><?php
                                    echo empty( $owa_ge['is_active'] ) ? ' (inactive)' : '';?>
                            </option>
                        <?php endforeach;?>
                    </select>
                    <span class="constraintAddButton" role="button" tabindex="0"
                          title="Add another step" aria-label="Add another step">+</span>
                    <span class="constraintRemoveButton" role="button" tabindex="0"
                          title="Remove this step" aria-label="Remove this step">X</span>
                </li>
            <?php endforeach;?>
            </ul>
            <span class="validation_error"><?php $view->out( $view->validation_errors['stepPath1'] ?? '' );?></span>
            <span class="validation_error"><?php $view->out( $view->validation_errors['stepName1'] ?? '' );?></span>
            <span class="validation_error"><?php $view->out( $view->validation_errors['stepGoalEvent1'] ?? '' );?></span>
        </div>
    </div>

    <?php echo $view->createNonceFormField('base.visualizationSave');?>
    <input type="hidden" name="<?php echo $view->getNs();?>visualizationId" value="<?php $view->out( $view->visualizationId ?? '' );?>">
    <?php
        /*
         * WHICH SITE, carried by the form.
         *
         * The action is built with makeLink(), which does not add state unless
         * asked -- so without this the save receives no siteId, redirects to
         * the funnel without one, and the funnel counts against site_id = ''.
         * It draws perfectly: right stages, right names, nought visitors.
         *
         * Unprefixed, like the custom report builder's, because that is the
         * name its save reads.
         */
    ?>
    <input type="hidden" name="siteId" value="<?php $view->out( $view->get('siteId') );?>">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Visualization">
</form>

<?php if ( ! empty( $owa_v['id'] ) ):?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this visualization</div>
    <div class="owa_dangerZoneBody">
        It disappears from the Visualizations list. Nothing it counted is removed &mdash; a
        visualization computes from events that stay exactly as they are.
    </div>
    <form method="post"
          action="<?php echo $view->makeLink( array( 'do' => 'base.customReportDelete' ) );?>">
        <?php echo $view->createNonceFormField('base.customReportDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>customReportId" value="<?php $view->out( $owa_v['id'] );?>">
        <?php /* And the same for delete, which redirects to a roster -- and a
                 roster with no site has no left-hand nav at all. */ ?>
        <input type="hidden" name="siteId" value="<?php $view->out( $view->get('siteId') );?>">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Visualization"
               data-owa-confirm
               data-owa-confirm-title="Delete this visualization?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_v['name'] ?? '' );?>&rdquo; disappears from the list. Nothing it counted is removed."
               data-owa-confirm-proceed="Delete visualization">
    </form>
</div>
<?php endif;?>

<script type="text/javascript">
jQuery( function () {

    /*
     * Show the field the chosen kind needs, and only that one.
     *
     * Both are rendered so the form works with no JavaScript at all -- the save
     * reads the KIND and ignores the other field, so a browser that runs none
     * of this still saves the right thing. This only stops the reader being
     * asked for two answers when one is wanted.
     */
    function syncStep( row ) {

        var kind = jQuery( row ).find( '.owa_funnelStepKind' ).val();

        jQuery( row ).find( '.owa_funnelStepPath' ).toggle( kind !== 'goal_event' );
        jQuery( row ).find( '.owa_funnelStepGoal' ).toggle( kind === 'goal_event' );
    }

    // Delegated, because the + button clones a row at runtime and a per-row
    // binding would cover only the rows present at load.
    jQuery( document ).on( 'change', '.owa_funnelStepKind', function () {

        syncStep( jQuery( this ).closest( '.constraintRow' ) );
    } );

    jQuery( document ).on( 'click keypress', '#owa_goalEventFunnel .constraintAddButton',
        function () {

            // After the clone, not before: the new row is inserted by the
            // repeatable-list handler bound on the same event.
            window.setTimeout( function () {

                jQuery( '#owa_goalEventFunnel .constraintRow' ).each( function () {

                    syncStep( this );
                } );
            }, 0 );
        } );

    jQuery( '#owa_goalEventFunnel .constraintRow' ).each( function () {

        syncStep( this );
    } );
} );
</script>
