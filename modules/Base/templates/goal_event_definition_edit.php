<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One goal event definition.
 *
 * The condition is presented as a constraint row -- the same markup and the
 * same class names the report builder uses (li.constraintRow >
 * .constraintDimensionPicker / .constraintOperatorPicker /
 * .constraintValueField), because the pill styling is written against those
 * classes and naming a condition is the same act in both places.
 *
 * ONE row, no add button. The schema holds a single property/operator/value
 * triple, which is what v2 specifies (PLAN.html §7.14: an event type and a
 * condition). Whether several conditions can combine is a v2 question, and
 * rendering an add button that cannot be saved would answer it in the UI first.
 */
$owa_ke = (array) $view->goalEventDefinition;

$owa_operators = array(
    \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_EXACT  => 'Exactly Matching',
    \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_BEGINS => 'Begins With',
    \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_REGEX  => 'Matches Regex',
);
?>
<h1 class="panel_headline"><?php echo $view->headline;?></h1>
<div id="panel">
<div class="owa_panelIntro">Goal events are custom events that are created when OWA observes
a behavior pattern you specify. A goal event definition names them and says which events count:
choose the event, then pick a property of it, how to compare it, and what to compare it to.</div>

<form method="post" name="owa_goalEventDefinition">

    <div class="setting">
        <?php // Once saved the name is text, not a field: nothing to label. ?>
        <?php if ( ! empty( $view->goalEventDefinitionId ) && ! empty( $owa_ke['name'] ) ):?>
        <div class="title">Name</div>
        <div class="description"><?php $view->out( \OWA\Core\CoreAPI::t( 'Each goal event is stored as an event with this name. It cannot be changed: for another name, create another goal event definition and switch this one off.' ) );?></div>
        <div class="field">
            <code><?php $view->out( $owa_ke['name'] );?></code>
        </div>
        <?php else:?>
        <label class="title" for="owa_goalName">Name</label>
        <div class="description"><?php $view->out( \OWA\Core\CoreAPI::t( 'Each goal event is stored as an event with this name, which reports show: Signup_Completed rather than Goal_3. A letter, then up to 39 letters, digits and underscores. It cannot be changed once the goal event definition is saved.' ) );?></div>
        <div class="field">
            <input class="owa_largeFormField" type="text" maxlength="40" id="owa_goalName"
                   name="<?php echo $view->getNs();?>name"
                   value="<?php $view->out( $owa_ke['name'] ?? '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['name'] ?? '' );?></span>
        </div>
        <?php endif;?>
    </div>

    <div class="setting">
        <div class="title">Counts when</div>
        <div class="description">Which event counts as this goal, and the values it must carry. Only properties the chosen event carries are offered: a page view has no click target, for example. A goal event definition with no conditions matches nothing.</div>
        <div class="field">
            <?php
            /*
             * ONE SENTENCE, because the two halves are one decision: the event
             * decides which properties exist, so the event picker opens the
             * condition builder rather than sitting in a section of its own.
             *
             * The field names are unchanged -- triggerEvent, conditionMatch and the
             * three conditionX[] arrays -- so the save path and a page with no
             * JavaScript behave as before. owa.goalbuilder.js adds the rest: the
             * property list follows the event, and the pickers are searchable and
             * describe what they offer.
             */
            $owa_conditions = $view->conditions ?: array( array(
                'condition_property' => '', 'condition_operator' => '', 'condition_value' => '' ) );
            ?>
            <div class="owa_goalBuilder"
                 data-not-carried-label="<?php $view->out( \OWA\Core\CoreAPI::t( '%s -- not carried by %s' ) );?>"
                 data-not-carried-help="<?php $view->out( \OWA\Core\CoreAPI::t( 'A %s event does not carry this property.' ) );?>">
                <div class="owa_goalSentence">
                    A
                    <?php
                    /*
                     * OWA's events, then the site's own: the ones its Profiles
                     * sent lately, and this definition's trigger if it is one of
                     * them and has not been sent lately. The last choice names
                     * any other in the field beside the picker.
                     */
                    $owa_custom = (array) $view->customTriggerEvents;

                    if ( \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $view->triggerEvent )
                         && ! in_array( $view->triggerEvent, $owa_custom, true ) ) {

                        array_unshift( $owa_custom, $view->triggerEvent );
                    }

                    $owa_other = \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM;
                    ?>
                    <select class="owa_goalTrigger" name="<?php echo $view->getNs();?>triggerEvent"
                            aria-label="The event this goal counts">
                        <optgroup label="OWA events">
                        <?php foreach ( (array) $view->triggerEvents as $owa_event ):?>
                            <option value="<?php $view->out( $owa_event );?>"
                                <?php echo ( $view->triggerEvent === $owa_event ) ? 'selected' : '';?>>
                                <?php $view->out( $owa_event );?>
                            </option>
                        <?php endforeach;?>
                        </optgroup>
                        <optgroup label="Your events">
                        <?php foreach ( $owa_custom as $owa_event ):?>
                            <option value="<?php $view->out( $owa_event );?>"
                                <?php echo ( $view->triggerEvent === $owa_event ) ? 'selected' : '';?>>
                                <?php $view->out( $owa_event );?>
                            </option>
                        <?php endforeach;?>
                            <option value="<?php $view->out( $owa_other );?>"
                                <?php echo ( $view->triggerEvent === $owa_other ) ? 'selected' : '';?>>
                                Another event of yours&hellip;
                            </option>
                        </optgroup>
                    </select>
                    <input class="owa_goalCustomTrigger" type="text" maxlength="40"
                           name="<?php echo $view->getNs();?>customTriggerEvent"
                           value="<?php $view->out( (string) $view->customTriggerEvent );?>"
                           placeholder="newsletter_signup" aria-label="The name of your event">
                    event where
                    <select class="owa_goalMatch" name="<?php echo $view->getNs();?>conditionMatch"
                            aria-label="How many conditions must match">
                        <option value="all" <?php echo ( $owa_ke['condition_match'] ?? 'all' ) !== 'any' ? 'selected' : '';?>>all</option>
                        <option value="any" <?php echo ( $owa_ke['condition_match'] ?? '' ) === 'any' ? 'selected' : '';?>>any</option>
                    </select>
                    of these match:
                </div>
                <div class="owa_goalTriggerHelp">An event of your own, sent with trackCustomEvent, needs no conditions: leave them empty to count every one.</div>
                <span class="validation_error"><?php $view->out( $view->validation_errors['triggerEvent'] ?? '' );?></span>

                <ul class="constraintList owa_goalEventCondition" data-owa-repeatable>
                <?php foreach ( $owa_conditions as $owa_cond ):?>
                    <li class="constraintRow owa_goalCondition"
                        data-saved-property="<?php $view->out( in_array( (string) ( $owa_cond['condition_property'] ?? '' ),
                            (array) $view->savedProperties, true ) ? $owa_cond['condition_property'] : '' );?>">
                        <span class="constraintDimensionPicker">
                            <select class="dim-list owa_goalProperty" name="<?php echo $view->getNs();?>conditionProperty[]"
                                    aria-label="Property">
                            <?php foreach ( (array) $view->conditionProperties as $owa_prop ):?>
                                <option value="<?php $view->out( $owa_prop['name'] );?>"
                                    data-description="<?php $view->out( $owa_prop['description'] ?? '' );?>"
                                    <?php echo ( ( $owa_cond['condition_property'] ?? '' ) === $owa_prop['name'] ) ? 'selected' : '';?>>
                                    <?php $view->out( $owa_prop['label'] );?>
                                </option>
                            <?php endforeach;?>
                            </select>
                        </span>
                        <span class="constraintOperatorPicker">
                            <select class="operator-list" name="<?php echo $view->getNs();?>conditionOperator[]"
                                    aria-label="Comparison">
                            <?php foreach ( \OWA\Module\Base\Entity\GoalEventDefinition::operators() as $owa_value => $owa_label ):?>
                                <option value="<?php $view->out( $owa_value );?>"
                                    <?php echo ( ( $owa_cond['condition_operator'] ?? '' ) === $owa_value ) ? 'selected' : '';?>>
                                    <?php $view->out( $owa_label );?>
                                </option>
                            <?php endforeach;?>
                            </select>
                        </span>
                        <input class="constraintValueField" type="text" aria-label="Value"
                               name="<?php echo $view->getNs();?>conditionValue[]"
                               value="<?php $view->out( $owa_cond['condition_value'] ?? '' );?>">
                        <span class="constraintAddButton" role="button" tabindex="0"
                              title="Add another condition" aria-label="Add another condition">+</span>
                        <span class="constraintRemoveButton" role="button" tabindex="0"
                              title="Remove this condition" aria-label="Remove this condition">X</span>
                        <div class="owa_goalConditionHelp"></div>
                    </li>
                <?php endforeach;?>
                </ul>
            </div>
            <span class="validation_error"><?php $view->out( $view->validation_errors['conditionValue'] ?? '' );?></span>
            <script type="application/json" id="owa_goalVocabulary"><?php
                echo json_encode( (array) $view->conditionVocabulary,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            ?></script>
        </div>
    </div>

    <div class="setting">
        <label class="title" for="owa_goalValue">Value <span class="owa_optional">optional</span></label>
        <div class="description"><?php $view->out( \OWA\Core\CoreAPI::t( 'What one goal event is worth, in the Property\'s currency. Left blank, a goal event met by a purchase carries the purchase\'s revenue, and any other carries no value.' ) );?></div>
        <div class="field">
            <input class="owa_shortFormField" type="text" id="owa_goalValue"
                   name="<?php echo $view->getNs();?>value"
                   value="<?php $view->out( isset( $owa_ke['id'] )
                       ? \OWA\Module\Base\Entity\GoalEventDefinition::centsToDecimal( $owa_ke['value'] ?? 0 )
                       : '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['value'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <label class="title" for="owa_goalCountMode"><?php $view->out( \OWA\Core\CoreAPI::t( 'How often' ) );?></label>
        <div class="description"><?php $view->out( \OWA\Core\CoreAPI::t( 'Once per event adds a goal event for every event that matches. Once per session adds one for the first match in a session, and none for the rest of it.' ) );?></div>
        <div class="field">
            <?php
            /*
             * A NEW definition defaults to once per event. An existing one shows
             * what it stores, and a falsy count_mode is once per session, which
             * is what every definition migrated from 1.x is
             * (GoalEventDefinition::countMode()). A refused form comes back with
             * what was submitted.
             */
            $owa_count = ! empty( $owa_ke['count_mode'] ) || ! empty( $owa_ke['id'] )
                ? \OWA\Module\Base\Entity\GoalEventDefinition::countModeOf( $owa_ke['count_mode'] ?? '' )
                : \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_EVENT;
            ?>
            <select id="owa_goalCountMode" name="<?php echo $view->getNs();?>countMode">
            <?php foreach ( \OWA\Module\Base\Entity\GoalEventDefinition::countModes() as $owa_value => $owa_label ):?>
                <option value="<?php $view->out( $owa_value );?>" <?php echo $owa_count === $owa_value ? 'selected' : '';?>><?php $view->out( $owa_label );?></option>
            <?php endforeach;?>
            </select>
        </div>
    </div>

    <div class="setting">
        <label class="title" for="owa_goalIsActive">Status</label>
        <div class="description">An inactive goal event definition is kept and stops
        counting.</div>
        <div class="field">
            <?php
            /*
             * A NEW definition defaults to Active.
             *
             * The stored default is falsy, deliberately -- a row half-written
             * by anything other than this form must not start counting on its
             * own. But someone filling this in has said what they want counted,
             * and defaulting the control to Inactive means they save it and it
             * silently never fires. Existing rows show what they actually are.
             */
            $owa_active = isset( $owa_ke['id'] ) ? ! empty( $owa_ke['is_active'] ) : true;
            ?>
            <select id="owa_goalIsActive" name="<?php echo $view->getNs();?>isActive">
                <option value="1" <?php echo $owa_active ? 'selected' : '';?>>Active</option>
                <option value="0" <?php echo $owa_active ? '' : 'selected';?>>Inactive</option>
            </select>
        </div>
    </div>

    <?php echo $view->createNonceFormField('base.goalEventDefinitionSave');?>
    <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $view->propertyId );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>goalEventDefinitionId" value="<?php $view->out( $view->goalEventDefinitionId ?? '' );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.goalEventDefinitionSave">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Goal Event Definition">
</form>

<?php if ( ! empty( $owa_ke['id'] ) ):?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this goal event definition</div>
    <div class="owa_dangerZoneBody">
        It stops counting and is removed from reports. The events it has already counted are kept.
    </div>
    <form method="post">
        <?php echo $view->createNonceFormField('base.goalEventDefinitionDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $view->propertyId );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>goalEventDefinitionId" value="<?php $view->out( $owa_ke['id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.goalEventDefinitionDelete">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Goal Event Definition"
               data-owa-confirm
               data-owa-confirm-title="Delete this goal event definition?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_ke['name'] ?? '' );?>&rdquo; stops counting and leaves reports. Events already counted are kept."
               data-owa-confirm-proceed="Delete goal event definition">
    </form>
</div>
<?php endif;?>
</div>
