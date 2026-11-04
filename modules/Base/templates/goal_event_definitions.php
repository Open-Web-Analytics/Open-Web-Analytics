<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The goal event definitions of one Property.
 *
 * The goals screen listed twenty numbered rows whether or not anyone had filled
 * them in, because the storage was a fixed-length array and the screen showed
 * the storage. This lists what exists, and says so when that is nothing.
 */
?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<div class="owa_panelIntro">A goal event definition counts one thing visitors do, such as reaching a page or taking an action: every event that matches its condition is counted, or every one of an event of your own. Goal event definitions belong to the <strong>Property</strong>, so they count the same way in all of its Observation Profiles.</div>

<?php
/*
 * The house shape for an admin list: a fieldset whose legend names the section
 * and carries the way to add one, then the table. Same as the user roster and
 * the allowed-users list.
 *
 * It was an orange .owa-button above the table -- which is the PRIMARY button,
 * the one "Save" wears, so a list of things to read led with the loudest thing
 * on the screen and looked like a different design from every other settings
 * page.
 */
?>
<fieldset>

    <legend>
        Goal Event Definitions <span class="legend_link">(<a href="<?php echo $view->makeLink( array(
            'do' => 'base.goalEventDefinitionEdit', 'propertyId' => $view->propertyId ) );?>">Add New Goal Event Definition</a>)</span>
    </legend>

<?php if ( empty( $view->goalEventDefinitions ) ):?>

    <div class="owa_emptyState">
        <p>This Property counts nothing yet.</p>
    </div>

<?php else:?>

    <table class="management">
        <thead>
            <tr>
                <th>Name</th>
                <th>Counts</th>
                <th>Value</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $view->goalEventDefinitions as $owa_ke ):?>
            <tr>
                <td><?php $view->out( $owa_ke['name'] );?></td>
                <td class="owa_goalEventCondition">
                    <?php $owa_trigger = (string) ( $owa_ke['trigger_event_type'] ?? '' );?>
                    <?php if ( ! empty( $owa_ke['conditions'] ) ):?>
                    <span class="owa_goalEventTrigger"><?php $view->out( $owa_trigger );?></span>
                    <span class="owa_goalEventJoin">where</span>
                    <?php foreach ( $owa_ke['conditions'] as $owa_i => $owa_cond ):?>
                        <?php if ( $owa_i ):?>
                        <span class="owa_goalEventJoin"><?php $view->out( $owa_ke['condition_match'] === 'any' ? 'or' : 'and' );?></span>
                        <?php endif;?>
                        <span class="owa_goalEventProperty"><?php $view->out( $owa_cond['property'] );?></span>
                        <span class="owa_goalEventOperator"><?php $view->out(
                            \OWA\Module\Base\Entity\GoalEventDefinition::operators()[ $owa_cond['operator'] ] ?? $owa_cond['operator'] );?></span>
                        <span class="owa_goalEventValue"><?php $view->out( $owa_cond['value'] );?></span>
                    <?php endforeach;?>
                    <?php elseif ( \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $owa_trigger ) ):?>
                    every <span class="owa_goalEventTrigger"><?php $view->out( $owa_trigger );?></span>
                    <?php else:?>
                    <?php
                        /*
                         * A definition with no condition counts nothing. Said
                         * plainly rather than shown as an empty cell: this
                         * install had a goal in exactly this state -- a type
                         * the evaluator has no case for and no URL -- and
                         * nothing anywhere said it would never fire.
                         */
                    ?>
                    <span class="owa_goalEventIncomplete">no condition set &mdash; counts nothing</span>
                    <?php endif;?>
                </td>
                <td><?php $view->out( \OWA\Module\Base\Entity\GoalEventDefinition::centsToDecimal( $owa_ke['value'] ) );?></td>
                <td><?php echo $owa_ke['is_active'] ? 'Active' : 'Inactive';?></td>
                <td>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.goalEventDefinitionEdit',
                        'propertyId' => $view->propertyId, 'goalEventDefinitionId' => $owa_ke['id'] ) );?>">Edit</a>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>

<?php endif;?>

</fieldset>
</div>
