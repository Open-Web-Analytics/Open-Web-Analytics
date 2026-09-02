<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One funnel: a name, what reaching the end of it counts as, and its steps.
 *
 * Its own screen because a funnel is its own thing. It was a section on the
 * goal event form, which implied a funnel belongs to a goal event and cannot
 * exist without one.
 */
$owa_f = (array) $view->funnel;
?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<div class="owa_panelIntro">An ordered path through this website. Each step is matched as a
regular expression against the page URL, and reports use the order to show where people
leave.</div>

<form method="post" name="owa_funnel">

    <div class="setting">
        <div class="title">Name</div>
        <div class="description">What this path is called in reports.</div>
        <div class="field">
            <input class="owa_largeFormField" type="text" maxlength="255"
                   name="<?php echo $view->getNs();?>name"
                   value="<?php $view->out( $owa_f['name'] ?? '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['name'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Reaching the end counts as <span class="owa_optional">optional</span></div>
        <div class="description">The goal event completing this funnel records. Leave it as
        nothing to describe the path without counting it &mdash; two funnels can also lead to
        the same goal event, which is how two routes to one signup are compared.</div>
        <div class="field">
            <select name="<?php echo $view->getNs();?>goalEventId" class="owa_largeFormField">
                <option value="">&mdash; nothing, just describe the path &mdash;</option>
                <?php foreach ( (array) $view->goalEvents as $owa_ge ):?>
                <option value="<?php $view->out( $owa_ge['id'] );?>"
                    <?php echo ( (string) ( $owa_f['goal_event_id'] ?? '' ) === (string) $owa_ge['id'] ) ? 'selected' : '';?>>
                    <?php $view->out( $owa_ge['name'] );?>
                </option>
                <?php endforeach;?>
            </select>
        </div>
    </div>

    <div class="setting">
        <div class="title">Steps</div>
        <div class="description">In order, first to last. A step with no path is ignored, so a
        row left blank costs nothing.</div>
        <div class="field">
            <ul class="constraintList owa_goalEventFunnel" id="owa_goalEventFunnel" data-owa-repeatable>
            <?php
            /* Always at least one row, or there is nowhere to type the first step. */
            $owa_steps = $view->steps ?: array( 1 => array( 'name' => '', 'path' => '' ) );
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

    <?php echo $view->createNonceFormField('base.funnelSave');?>
    <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->siteId );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>funnelId" value="<?php $view->out( $view->funnelId ?? '' );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.funnelSave">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Funnel">
</form>

<?php if ( ! empty( $owa_f['id'] ) ):?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this funnel</div>
    <div class="owa_dangerZoneBody">
        The path stops being reported on. The goal event it counts as is not affected &mdash;
        it keeps counting, and what it has already counted is unchanged.
    </div>
    <form method="post">
        <?php echo $view->createNonceFormField('base.funnelDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->siteId );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>funnelId" value="<?php $view->out( $owa_f['id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.funnelDelete">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Funnel"
               data-owa-confirm
               data-owa-confirm-title="Delete this funnel?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_f['name'] ?? '' );?>&rdquo; stops being reported on. The goal event it counts as is not affected."
               data-owa-confirm-proceed="Delete funnel">
    </form>
</div>
<?php endif;?>
</div>
