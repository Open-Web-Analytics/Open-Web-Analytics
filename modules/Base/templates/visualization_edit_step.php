<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One step of the funnel builder (visualization_edit.php): its handle and
 * number, Page is / Event is, the path or the goal event, and remove. The
 * numbers and labels are written by the builder's script as the list changes.
 *
 *   $owa_step        the step: path, goal_event_id, name
 *   $owa_stepNo      its number, 0 for the blank row Add a step copies
 *   $owa_stepGoal    its goal event id, or ''
 *   $owa_stepErrors  messages about it
 *   $owa_goals       the Property's goal events
 */
$owa_step       = (array) ( $owa_step ?? array() );
$owa_stepNo     = (int) ( $owa_stepNo ?? 0 );
$owa_stepGoal   = (string) ( $owa_stepGoal ?? '' );
$owa_stepErrors = (array) ( $owa_stepErrors ?? array() );
$owa_goals      = (array) ( $owa_goals ?? array() );
?>
<li class="owa_funnelStep">
    <button type="button" class="owa_builderMove" title="Drag to move, or use the arrow keys"
            aria-label="Move step <?php echo $owa_stepNo;?>"><i class="fas fa-grip-vertical" aria-hidden="true"></i></button>
    <span class="owa_funnelStepNumber"><?php echo $owa_stepNo ?: '';?></span>
    <select class="owa_funnelStepKind" name="<?php echo $view->getNs();?>stepKind[]">
        <option value="path" <?php echo $owa_stepGoal === '' ? 'selected' : '';?>>Page is</option>
        <option value="goal_event" <?php echo $owa_stepGoal !== '' ? 'selected' : '';?>>Event is</option>
    </select>
    <input class="owa_funnelStepPath" type="text" placeholder="/path"
           name="<?php echo $view->getNs();?>stepPath[]"
           value="<?php $view->out( $owa_step['path'] ?? '' );?>">
    <select class="owa_funnelStepGoal" name="<?php echo $view->getNs();?>stepGoalEventId[]">
        <option value=""><?php echo $owa_goals ? 'Choose a goal event&hellip;' : 'No goal events yet';?></option>
        <?php foreach ( $owa_goals as $owa_ge ):?>
        <option value="<?php $view->out( $owa_ge['id'] );?>"
            <?php echo $owa_stepGoal === (string) $owa_ge['id'] ? 'selected' : '';?>><?php
            $view->out( $owa_ge['name'] );?><?php echo empty( $owa_ge['is_active'] ) ? ' (inactive)' : '';?></option>
        <?php endforeach;?>
    </select>
    <?php // The stored name, kept while the step is unchanged; see the builder's header. ?>
    <input class="owa_funnelStepName" type="hidden" name="<?php echo $view->getNs();?>stepName[]"
           value="<?php $view->out( $owa_step['name'] ?? '' );?>">
    <button type="button" class="owa_iconButton owa_funnelStepRemove" title="Remove this step"
            aria-label="Remove step <?php echo $owa_stepNo;?>"><i class="fas fa-times" aria-hidden="true"></i></button>
    <?php foreach ( $owa_stepErrors as $owa_stepError ):?>
    <span class="validation_error owa_funnelStepError"><?php $view->out( $owa_stepError );?></span>
    <?php endforeach;?>
</li>
