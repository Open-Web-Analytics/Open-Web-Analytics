<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One step of the funnel builder (visualization_edit.php): its handle and
 * number, then "A [page_view] event where [all] of these match:" and its
 * condition rows, and remove. The numbers, labels and the position in each
 * condition field's name are written by the builder's script as the list
 * changes (owa.funnelbuilder.js).
 *
 * The condition rows are the goal event definition form's -- .constraintRow,
 * a property picker that follows the event, an operator and a value -- under
 * the step's own class names, so owa.goalbuilder.js drives them the same way.
 *
 *   $owa_step        the step as VisualizationEdit::formSteps() draws it:
 *                    name, picked, custom_event, match, conditions, properties
 *   $owa_stepIndex   its position, from 0, for the condition fields' names
 *   $owa_stepNo      its number, 0 for the blank row Add a step copies
 *   $owa_stepErrors  messages about it
 */
$owa_step       = (array) ( $owa_step ?? array() );
$owa_stepIndex  = (int) ( $owa_stepIndex ?? 0 );
$owa_stepNo     = (int) ( $owa_stepNo ?? 0 );
$owa_stepErrors = (array) ( $owa_stepErrors ?? array() );

$owa_picked = (string) ( $owa_step['picked'] ?? \OWA\Module\Base\Classes\FunnelStep::DEFAULT_EVENT );
$owa_other  = \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM;

// The Property's own events, and this step's if it is one of them and has not been sent lately.
$owa_custom = (array) $view->customEventNames;

if ( \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $owa_picked )
     && ! in_array( $owa_picked, $owa_custom, true ) ) {

    array_unshift( $owa_custom, $owa_picked );
}
?>
<li class="owa_funnelStep">
    <button type="button" class="owa_builderMove" title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Drag to move, or use the arrow keys' ) );?>"
            aria-label="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Move step %s' ), $owa_stepNo ) );?>"><i class="fas fa-grip-vertical" aria-hidden="true"></i></button>
    <span class="owa_funnelStepNumber"><?php echo $owa_stepNo ?: '';?></span>

    <div class="owa_funnelStepBody">
        <div class="owa_funnelStepSentence">
            <?php $view->out( \OWA\Core\CoreAPI::t( 'A' ) );?>
            <select class="owa_funnelStepEvent" name="stepEvent[]"
                    aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'The event this step matches' ) );?>">
                <optgroup label="<?php $view->out( \OWA\Core\CoreAPI::t( 'OWA events' ) );?>">
                <?php foreach ( (array) $view->eventNames as $owa_event ):?>
                    <option value="<?php $view->out( $owa_event );?>" <?php echo $owa_picked === $owa_event ? 'selected' : '';?>><?php $view->out( $owa_event );?></option>
                <?php endforeach;?>
                </optgroup>
                <optgroup label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Your events' ) );?>">
                <?php foreach ( $owa_custom as $owa_event ):?>
                    <option value="<?php $view->out( $owa_event );?>" <?php echo $owa_picked === $owa_event ? 'selected' : '';?>><?php $view->out( $owa_event );?></option>
                <?php endforeach;?>
                    <option value="<?php $view->out( $owa_other );?>" <?php echo $owa_picked === $owa_other ? 'selected' : '';?>><?php $view->out( \OWA\Core\CoreAPI::t( 'Another event of yours…' ) );?></option>
                </optgroup>
            </select>
            <input class="owa_funnelStepCustomEvent" type="text" maxlength="40"
                   name="stepCustomEvent[]"
                   value="<?php $view->out( (string) ( $owa_step['custom_event'] ?? '' ) );?>"
                   placeholder="newsletter_signup"
                   aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'The name of your event' ) );?>"
                   <?php echo $owa_picked === $owa_other ? '' : 'hidden';?>>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'event where' ) );?>
            <select class="owa_funnelStepMatch" name="stepMatch[]"
                    aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'How many conditions must match' ) );?>">
                <option value="all" <?php echo ( $owa_step['match'] ?? 'all' ) !== 'any' ? 'selected' : '';?>><?php $view->out( \OWA\Core\CoreAPI::t( 'all' ) );?></option>
                <option value="any" <?php echo ( $owa_step['match'] ?? '' ) === 'any' ? 'selected' : '';?>><?php $view->out( \OWA\Core\CoreAPI::t( 'any' ) );?></option>
            </select>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'of these match:' ) );?>
        </div>

        <ul class="constraintList owa_funnelConditions" data-owa-repeatable>
        <?php foreach ( (array) ( $owa_step['conditions'] ?? array() ) as $owa_cond ):?>
            <li class="constraintRow owa_funnelCondition" data-saved-property="<?php $view->out( (string) ( $owa_cond['property'] ?? '' ) );?>">
                <span class="constraintDimensionPicker">
                    <select class="dim-list owa_funnelConditionProperty"
                            name="stepConditionProperty[<?php echo $owa_stepIndex;?>][]"
                            aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Property' ) );?>">
                    <?php foreach ( (array) ( $owa_step['properties'] ?? array() ) as $owa_prop ):?>
                        <option value="<?php $view->out( $owa_prop['name'] );?>"
                            data-description="<?php $view->out( $owa_prop['description'] ?? '' );?>"
                            <?php echo ( (string) ( $owa_cond['property'] ?? '' ) === (string) $owa_prop['name'] ) ? 'selected' : '';?>><?php $view->out( $owa_prop['label'] );?></option>
                    <?php endforeach;?>
                    </select>
                </span>
                <span class="constraintOperatorPicker">
                    <select class="operator-list owa_funnelConditionOperator"
                            name="stepConditionOperator[<?php echo $owa_stepIndex;?>][]"
                            aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Comparison' ) );?>">
                    <?php foreach ( \OWA\Module\Base\Entity\GoalEventDefinition::operators() as $owa_value => $owa_label ):?>
                        <option value="<?php $view->out( $owa_value );?>" <?php echo ( (string) ( $owa_cond['operator'] ?? '' ) === $owa_value ) ? 'selected' : '';?>><?php $view->out( $owa_label );?></option>
                    <?php endforeach;?>
                    </select>
                </span>
                <input class="constraintValueField owa_funnelConditionValue" type="text"
                       name="stepConditionValue[<?php echo $owa_stepIndex;?>][]"
                       aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Value' ) );?>"
                       value="<?php $view->out( (string) ( $owa_cond['value'] ?? '' ) );?>">
                <button type="button" class="constraintAddButton"
                        title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Add another condition' ) );?>"
                        aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Add another condition' ) );?>">+</button>
                <button type="button" class="constraintRemoveButton"
                        title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Remove this condition' ) );?>"
                        aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Remove this condition' ) );?>">X</button>
                <div class="owa_funnelConditionHelp"></div>
            </li>
        <?php endforeach;?>
        </ul>
    </div>

    <?php // The stored name, kept while the step is unchanged; see the builder's header. ?>
    <input class="owa_funnelStepName" type="hidden" name="stepName[]"
           value="<?php $view->out( $owa_step['name'] ?? '' );?>">
    <button type="button" class="owa_iconButton owa_funnelStepRemove" title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Remove this step' ) );?>"
            aria-label="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Remove step %s' ), $owa_stepNo ) );?>"><i class="fas fa-times" aria-hidden="true"></i></button>
    <?php foreach ( $owa_stepErrors as $owa_stepError ):?>
    <span class="validation_error owa_funnelStepError"><?php $view->out( $owa_stepError );?></span>
    <?php endforeach;?>
</li>
