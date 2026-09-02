<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One key event.
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
$owa_ke = (array) $view->keyEvent;

$owa_operators = array(
    \OWA\Module\Base\Entity\KeyEvent::MATCH_EXACT  => 'Exactly Matching',
    \OWA\Module\Base\Entity\KeyEvent::MATCH_BEGINS => 'Begins With',
    \OWA\Module\Base\Entity\KeyEvent::MATCH_REGEX  => 'Matches Regex',
);
?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<div class="owa_panelIntro">A key event names something worth counting. Give it a name,
then say which events count: pick a property of the event, how to compare it, and what to
compare it to.</div>

<form method="post" name="owa_keyEvent">

    <div class="setting">
        <div class="title">Name</div>
        <div class="description">What this is called in reports. In v2 this becomes the
        event's own name, so it is worth choosing something you would want to see in a
        report &mdash; "Signup Completed" rather than "Goal 3".</div>
        <div class="field">
            <input class="owa_largeFormField" type="text" size="52" maxlength="255"
                   name="<?php echo $view->getNs();?>name"
                   value="<?php $view->out( $owa_ke['name'] ?? '' );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['name'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Counts when</div>
        <div class="description">An event matching this condition is counted as a key
        event.</div>
        <div class="field">
            <ul class="constraintList owa_keyEventCondition">
                <li class="constraintRow">
                    <span class="constraintDimensionPicker">
                        <select class="dim-list" name="<?php echo $view->getNs();?>conditionProperty">
                        <?php foreach ( (array) $view->conditionProperties as $owa_prop ):?>
                            <option value="<?php $view->out( $owa_prop['name'] );?>"
                                <?php echo ( ( $owa_ke['condition_property'] ?? '' ) === $owa_prop['name'] ) ? 'selected' : '';?>>
                                <?php $view->out( $owa_prop['label'] );?> (<?php $view->out( $owa_prop['name'] );?>)
                            </option>
                        <?php endforeach;?>
                        </select>
                    </span>
                    <span class="constraintOperatorPicker">
                        <select class="operator-list" name="<?php echo $view->getNs();?>conditionOperator">
                        <?php foreach ( $owa_operators as $owa_value => $owa_label ):?>
                            <option value="<?php $view->out( $owa_value );?>"
                                <?php echo ( ( $owa_ke['condition_operator'] ?? '' ) === $owa_value ) ? 'selected' : '';?>>
                                <?php $view->out( $owa_label );?>
                            </option>
                        <?php endforeach;?>
                        </select>
                    </span>
                    <input class="constraintValueField" type="text"
                           name="<?php echo $view->getNs();?>conditionValue"
                           value="<?php $view->out( $owa_ke['condition_value'] ?? '' );?>">
                </li>
            </ul>
            <span class="validation_error"><?php $view->out( $view->validation_errors['conditionValue'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Value <span class="owa_optional">optional</span></div>
        <div class="description">What one of these is worth. Left blank it counts without
        a value.</div>
        <div class="field">
            <input type="text" size="12"
                   name="<?php echo $view->getNs();?>value"
                   value="<?php $view->out( \OWA\Module\Base\Entity\KeyEvent::centsToDecimal( $owa_ke['value'] ?? 0 ) );?>">
            <span class="validation_error"><?php $view->out( $view->validation_errors['value'] ?? '' );?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title">Status</div>
        <div class="description">An inactive key event keeps its definition and stops
        counting.</div>
        <div class="field">
            <select name="<?php echo $view->getNs();?>isActive">
                <option value="1" <?php echo ! empty( $owa_ke['is_active'] ) ? 'selected' : '';?>>Active</option>
                <option value="0" <?php echo empty( $owa_ke['is_active'] ) ? 'selected' : '';?>>Inactive</option>
            </select>
        </div>
    </div>

    <?php echo $view->createNonceFormField('base.keyEventSave');?>
    <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->siteId );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>keyEventId" value="<?php $view->out( $view->keyEventId ?? '' );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.keyEventSave">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Key Event">
</form>

<?php if ( ! empty( $owa_ke['id'] ) ):?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this key event</div>
    <div class="owa_dangerZoneBody">
        It stops counting and disappears from reports. Events already counted under it are
        not removed &mdash; what was recorded stays recorded.
    </div>
    <form method="post">
        <?php echo $view->createNonceFormField('base.keyEventDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->siteId );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>keyEventId" value="<?php $view->out( $owa_ke['id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.keyEventDelete">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Key Event"
               data-owa-confirm
               data-owa-confirm-title="Delete this key event?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_ke['name'] ?? '' );?>&rdquo; stops counting and disappears from reports. Events already counted under it are not removed."
               data-owa-confirm-proceed="Delete key event">
    </form>
</div>
<?php endif;?>
</div>
