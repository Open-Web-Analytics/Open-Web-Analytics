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
<h1 class="panel_headline"><?php echo $view->headline;?></h1>
<div id="panel">
<div class="owa_panelIntro"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'A goal event definition counts one thing visitors do, such as reaching a page or taking an action: every event that matches its condition is counted, or every one of an event of your own. Goal event definitions belong to the %s, so they count the same way in all of its Observation Profiles.' ), '<strong>' . htmlspecialchars( (string) \OWA\Core\CoreAPI::t( 'Property' ), ENT_QUOTES, 'UTF-8' ) . '</strong>' ); ?></div>

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
        <?php $view->out( \OWA\Core\CoreAPI::t( 'Goal Event Definitions' ) ); ?> <span class="legend_link">(<a href="<?php echo $view->makeLink( array(
            'do' => 'base.goalEventDefinitionEdit', 'propertyId' => $view->propertyId ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Add New Goal Event Definition' ) ); ?></a>)</span>
    </legend>

<?php if ( empty( $view->goalEventDefinitions ) ):?>

    <div class="owa_emptyState">
        <p><?php $view->out( \OWA\Core\CoreAPI::t( 'This Property counts nothing yet.' ) ); ?></p>
    </div>

<?php else:?>

    <table class="management owa_cardTable" data-ui="goal-events-table">
        <thead>
            <tr>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Counts' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'How often' ) );?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Value' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $view->goalEventDefinitions as $owa_ke ):?>
            <tr data-ui="goal-event-row">
                <td data-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) ); ?>"><?php $view->out( $owa_ke['name'] );?></td>
                <td class="owa_goalEventCondition" data-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Counts' ) ); ?>">
                    <?php $owa_trigger = (string) ( $owa_ke['trigger_event_type'] ?? '' );?>
                    <?php if ( ! empty( $owa_ke['conditions'] ) ):?>
                    <span class="owa_goalEventTrigger"><?php $view->out( $owa_trigger );?></span>
                    <span class="owa_goalEventJoin"><?php $view->out( \OWA\Core\CoreAPI::t( 'where' ) ); ?></span>
                    <?php foreach ( $owa_ke['conditions'] as $owa_i => $owa_cond ):?>
                        <?php if ( $owa_i ):?>
                        <span class="owa_goalEventJoin"><?php $view->out( $owa_ke['condition_match'] === 'any' ? \OWA\Core\CoreAPI::t( 'or' ) : \OWA\Core\CoreAPI::t( 'and' ) );?></span>
                        <?php endif;?>
                        <span class="owa_goalEventProperty"><?php $view->out( $owa_cond['property'] );?></span>
                        <span class="owa_goalEventOperator"><?php $view->out(
                            \OWA\Module\Base\Entity\GoalEventDefinition::operators()[ $owa_cond['operator'] ] ?? $owa_cond['operator'] );?></span>
                        <span class="owa_goalEventValue"><?php $view->out( $owa_cond['value'] );?></span>
                    <?php endforeach;?>
                    <?php elseif ( \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $owa_trigger ) ):?>
                    <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'every %s' ), '<span class="owa_goalEventTrigger">' . htmlspecialchars( (string) $owa_trigger, ENT_QUOTES, 'UTF-8' ) . '</span>' ); ?>
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
                    <span class="owa_goalEventIncomplete"><?php $view->out( \OWA\Core\CoreAPI::t( 'no condition set — counts nothing' ) ); ?></span>
                    <?php endif;?>
                </td>
                <td class="owa_goalEventCountMode" data-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'How often' ) );?>"><?php $view->out( \OWA\Module\Base\Entity\GoalEventDefinition::countModes()[
                    \OWA\Module\Base\Entity\GoalEventDefinition::countModeOf( $owa_ke['count_mode'] ?? '' ) ] );?></td>
                <td data-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Value' ) ); ?>"><?php $view->out( \OWA\Module\Base\Entity\GoalEventDefinition::centsToDecimal( $owa_ke['value'] ) );?></td>
                <td data-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?>"><?php $view->out( $owa_ke['is_active'] ? \OWA\Core\CoreAPI::t( 'Active' ) : \OWA\Core\CoreAPI::t( 'Inactive' ) );?></td>
                <td class="owa_rowActions">
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.goalEventDefinitionEdit',
                        'propertyId' => $view->propertyId, 'goalEventDefinitionId' => $owa_ke['id'] ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Edit' ) ); ?></a>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>

<?php endif;?>

</fieldset>
</div>
