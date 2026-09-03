<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One visualization.
 *
 * The type is asked first, because it decides what the rest of the form asks
 * for -- the same order the widget builder puts the question in. Only the
 * funnel exists today, and the picker is still shown: a single hardcoded kind
 * would not need naming, and the reader should see that this is a choice.
 */
$owa_v     = (array) $view->visualization;
$owa_type  = $owa_v['visualization_type'] ?? 'funnel';
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
        <div class="description">What kind of visualization this is. Each type computes its own
        numbers, so the type decides what it needs to know.</div>
        <div class="field">
            <select name="<?php echo $view->getNs();?>visualizationType" class="owa_largeFormField">
            <?php foreach ( (array) $view->visualizationTypes as $owa_key => $owa_label ):?>
                <option value="<?php $view->out( $owa_key );?>"
                    <?php echo $owa_type === $owa_key ? 'selected' : '';?>>
                    <?php $view->out( $owa_label );?>
                </option>
            <?php endforeach;?>
            </select>
        </div>
    </div>

    <div class="setting">
        <div class="title">Steps</div>
        <div class="description">In order, first to last. Each step is matched as a regular
        expression against the page URL. A step with no path is ignored, so a row left blank
        costs nothing.</div>
        <div class="field">
            <ul class="constraintList owa_goalEventFunnel" id="owa_goalEventFunnel" data-owa-repeatable>
            <?php
            /* Always one row, or there is nowhere to type the first step. */
            $owa_steps = $view->steps ?: array( array( 'name' => '', 'path' => '' ) );
            ?>
            <?php foreach ( $owa_steps as $owa_step ):?>
                <li class="constraintRow owa_funnelStep">
                    <input class="constraintValueField owa_funnelStepName" type="text"
                           placeholder="Step name"
                           name="<?php echo $view->getNs();?>stepName[]"
                           value="<?php $view->out( $owa_step['name'] ?? '' );?>">
                    <span class="constraintOperatorPicker owa_funnelStepMatches">matches</span>
                    <input class="constraintValueField owa_funnelStepPath" type="text"
                           placeholder="/path"
                           name="<?php echo $view->getNs();?>stepPath[]"
                           value="<?php $view->out( $owa_step['path'] ?? '' );?>">
                    <span class="constraintAddButton" role="button" tabindex="0"
                          title="Add another step" aria-label="Add another step">+</span>
                    <span class="constraintRemoveButton" role="button" tabindex="0"
                          title="Remove this step" aria-label="Remove this step">X</span>
                </li>
            <?php endforeach;?>
            </ul>
            <span class="validation_error"><?php $view->out( $view->validation_errors['stepPath1'] ?? '' );?></span>
            <span class="validation_error"><?php $view->out( $view->validation_errors['stepName1'] ?? '' );?></span>
        </div>
    </div>

    <?php echo $view->createNonceFormField('base.visualizationSave');?>
    <input type="hidden" name="<?php echo $view->getNs();?>visualizationId" value="<?php $view->out( $view->visualizationId ?? '' );?>">
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
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Visualization"
               data-owa-confirm
               data-owa-confirm-title="Delete this visualization?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_v['name'] ?? '' );?>&rdquo; disappears from the list. Nothing it counted is removed."
               data-owa-confirm-proceed="Delete visualization">
    </form>
</div>
<?php endif;?>
