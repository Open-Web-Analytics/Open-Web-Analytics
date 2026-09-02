<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One goal event.
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
$owa_ke = (array) $view->goalEvent;

$owa_operators = array(
    \OWA\Module\Base\Entity\GoalEvent::MATCH_EXACT  => 'Exactly Matching',
    \OWA\Module\Base\Entity\GoalEvent::MATCH_BEGINS => 'Begins With',
    \OWA\Module\Base\Entity\GoalEvent::MATCH_REGEX  => 'Matches Regex',
);
?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<div class="owa_panelIntro">Goal events are custom events that are created when OWA observes
a behavior pattern you specify. Give it a name, then say which events count: pick a property of
the event, how to compare it, and what to compare it to.</div>

<form method="post" name="owa_goalEvent">

    <div class="setting">
        <div class="title">Name</div>
        <div class="description">What this is called in reports. In v2 this becomes the
        event's own name, so it is worth choosing something you would want to see in a
        report &mdash; "Signup Completed" rather than "Goal 3".</div>
        <div class="field">
            <input class="owa_largeFormField" type="text" maxlength="255"
                   name="<?php echo $view->getNs();?>name"
                   value="<?php $view->out( $owa_ke['name'] ?? '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['name'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Counts when</div>
        <div class="description">An event matching these conditions is counted as a goal
        event.</div>
        <div class="field">
            <?php if ( count( (array) $view->conditions ) > 1 || true ):?>
            <div class="owa_conditionMatch">
                Match
                <select name="<?php echo $view->getNs();?>conditionMatch">
                    <option value="all" <?php echo ( $owa_ke['condition_match'] ?? 'all' ) !== 'any' ? 'selected' : '';?>>all</option>
                    <option value="any" <?php echo ( $owa_ke['condition_match'] ?? '' ) === 'any' ? 'selected' : '';?>>any</option>
                </select>
                of the following:
            </div>
            <?php endif;?>
            <ul class="constraintList owa_goalEventCondition" data-owa-repeatable>
            <?php
            /*
             * At least one row, always. An empty list would render a condition
             * builder with nothing to build in, and a goal event with no
             * conditions deliberately matches NOTHING -- so there has to be
             * somewhere to type the first one.
             */
            $owa_conditions = $view->conditions ?: array( array(
                'condition_property' => '', 'condition_operator' => '', 'condition_value' => '' ) );
            ?>
            <?php foreach ( $owa_conditions as $owa_cond ):?>
                <li class="constraintRow">
                    <span class="constraintDimensionPicker">
                        <select class="dim-list" name="<?php echo $view->getNs();?>conditionProperty[]">
                        <?php foreach ( (array) $view->conditionProperties as $owa_prop ):?>
                            <option value="<?php $view->out( $owa_prop['name'] );?>"
                                <?php echo ( ( $owa_cond['condition_property'] ?? '' ) === $owa_prop['name'] ) ? 'selected' : '';?>>
                                <?php $view->out( $owa_prop['label'] );?> (<?php $view->out( $owa_prop['name'] );?>)
                            </option>
                        <?php endforeach;?>
                        </select>
                    </span>
                    <span class="constraintOperatorPicker">
                        <select class="operator-list" name="<?php echo $view->getNs();?>conditionOperator[]">
                        <?php foreach ( \OWA\Module\Base\Entity\GoalEvent::operators() as $owa_value => $owa_label ):?>
                            <option value="<?php $view->out( $owa_value );?>"
                                <?php echo ( ( $owa_cond['condition_operator'] ?? '' ) === $owa_value ) ? 'selected' : '';?>>
                                <?php $view->out( $owa_label );?>
                            </option>
                        <?php endforeach;?>
                        </select>
                    </span>
                    <input class="constraintValueField" type="text"
                           name="<?php echo $view->getNs();?>conditionValue[]"
                           value="<?php $view->out( $owa_cond['condition_value'] ?? '' );?>">
                    <span class="constraintAddButton" role="button" tabindex="0"
                          title="Add another condition" aria-label="Add another condition">+</span>
                    <span class="constraintRemoveButton" role="button" tabindex="0"
                          title="Remove this condition" aria-label="Remove this condition">X</span>
                </li>
            <?php endforeach;?>
            </ul>
            <span class="validation_error"><?php $view->out( $view->validation_errors['conditionValue'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Group</div>
        <div class="description">Goal events are grouped, and every group with an active goal
        event becomes a tab on the tabbed reports. Renaming a group renames that tab
        everywhere.</div>
        <div class="field">
            <select name="<?php echo $view->getNs();?>goalGroup">
            <?php foreach ( (array) $view->goalGroups as $owa_num => $owa_label ):?>
                <option value="<?php $view->out( $owa_num );?>"
                    <?php echo ( (string) ( $owa_ke['goal_group'] ?? '' ) === (string) $owa_num ) ? 'selected' : '';?>>
                    <?php $view->out( $owa_label );?>
                </option>
            <?php endforeach;?>
            </select>
            <input class="owa_mediumFormField" type="text" placeholder="Rename this group"
                   name="<?php echo $view->getNs();?>newGoalGroupName" value="">
            <span class="form-instructions">Leave the rename empty to keep the group's
            current name.</span>
            <span class="validation_error"><?php $view->out( $view->validation_errors['newGoalGroupName'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Value <span class="owa_optional">optional</span></div>
        <div class="description">What one of these is worth. Left blank it counts without
        a value.</div>
        <div class="field">
            <input class="owa_shortFormField" type="text"
                   name="<?php echo $view->getNs();?>value"
                   value="<?php $view->out( isset( $owa_ke['id'] )
                       ? \OWA\Module\Base\Entity\GoalEvent::centsToDecimal( $owa_ke['value'] ?? 0 )
                       : '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['value'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Count</div>
        <div class="description">How often a match counts. A conversion is currently recorded
        against the <em>session</em>, so once per session is what this release can measure &mdash;
        once per event is stored and takes effect when per-event recording lands.</div>
        <div class="field">
            <select name="<?php echo $view->getNs();?>countMode">
                <option value="once_per_session" <?php echo ( $owa_ke['count_mode'] ?? '' ) !== 'once_per_event' ? 'selected' : '';?>>Once per session</option>
                <option value="once_per_event" <?php echo ( $owa_ke['count_mode'] ?? '' ) === 'once_per_event' ? 'selected' : '';?>>Once per event</option>
            </select>
        </div>
    </div>

    <div class="setting">
        <div class="title">Status</div>
        <div class="description">An inactive goal event keeps its definition and stops
        counting.</div>
        <div class="field">
            <?php
            /*
             * A NEW goal event defaults to Active.
             *
             * The stored default is falsy, deliberately -- a row half-written
             * by anything other than this form must not start counting on its
             * own. But someone filling this in has said what they want counted,
             * and defaulting the control to Inactive means they save it and it
             * silently never fires. Existing rows show what they actually are.
             */
            $owa_active = isset( $owa_ke['id'] ) ? ! empty( $owa_ke['is_active'] ) : true;
            ?>
            <select name="<?php echo $view->getNs();?>isActive">
                <option value="1" <?php echo $owa_active ? 'selected' : '';?>>Active</option>
                <option value="0" <?php echo $owa_active ? '' : 'selected';?>>Inactive</option>
            </select>
        </div>
    </div>

    <?php echo $view->createNonceFormField('base.goalEventSave');?>
    <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->siteId );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>goalEventId" value="<?php $view->out( $view->goalEventId ?? '' );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.goalEventSave">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Goal Event">
</form>

<?php if ( ! empty( $owa_ke['id'] ) ):?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this goal event</div>
    <div class="owa_dangerZoneBody">
        It stops counting and disappears from reports. Events already counted under it are
        not removed &mdash; what was recorded stays recorded.
    </div>
    <form method="post">
        <?php echo $view->createNonceFormField('base.goalEventDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->siteId );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>goalEventId" value="<?php $view->out( $owa_ke['id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.goalEventDelete">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Goal Event"
               data-owa-confirm
               data-owa-confirm-title="Delete this goal event?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_ke['name'] ?? '' );?>&rdquo; stops counting and disappears from reports. Events already counted under it are not removed."
               data-owa-confirm-proceed="Delete goal event">
    </form>
</div>
<?php endif;?>
</div>
