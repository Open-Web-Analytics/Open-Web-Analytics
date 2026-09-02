<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The key events of one Observation Profile.
 *
 * The goals screen listed twenty numbered rows whether or not anyone had filled
 * them in, because the storage was a fixed-length array and the screen showed
 * the storage. This lists what exists, and says so when that is nothing.
 */
?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<div class="owa_panelIntro">A key event is something worth counting &mdash; a page
reached, an action taken. Each one names a condition, and every event matching it is
counted for this Observation Profile. Key events belong to this Profile only; another
Profile of the same Property can count different things.</div>

<?php if ( empty( $view->keyEvents ) ):?>

    <div class="owa_emptyState">
        <p>This Profile counts nothing yet.</p>
        <p><a class="owa-button" href="<?php echo $view->makeLink( array(
            'do' => 'base.keyEventEdit', 'siteId' => $view->siteId ) );?>">Create a key event</a></p>
    </div>

<?php else:?>

    <p><a class="owa-button" href="<?php echo $view->makeLink( array(
        'do' => 'base.keyEventEdit', 'siteId' => $view->siteId ) );?>">Create a key event</a></p>

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
        <?php foreach ( $view->keyEvents as $owa_ke ):?>
            <tr>
                <td><?php $view->out( $owa_ke['name'] );?></td>
                <td class="owa_keyEventCondition">
                    <?php if ( $owa_ke['condition_value'] !== '' && $owa_ke['condition_value'] !== null ):?>
                    <span class="owa_keyEventProperty"><?php $view->out( $owa_ke['condition_property'] );?></span>
                    <span class="owa_keyEventOperator"><?php $view->out( $owa_ke['condition_operator'] );?></span>
                    <span class="owa_keyEventValue"><?php $view->out( $owa_ke['condition_value'] );?></span>
                    <?php else:?>
                    <?php
                        /*
                         * A key event with no condition counts nothing. Said
                         * plainly rather than shown as an empty cell: this
                         * install had a goal in exactly this state -- a type
                         * the evaluator has no case for and no URL -- and
                         * nothing anywhere said it would never fire.
                         */
                    ?>
                    <span class="owa_keyEventIncomplete">no condition set &mdash; counts nothing</span>
                    <?php endif;?>
                </td>
                <td><?php $view->out( \OWA\Module\Base\Entity\KeyEvent::centsToDecimal( $owa_ke['value'] ) );?></td>
                <td><?php echo $owa_ke['is_active'] ? 'Active' : 'Inactive';?></td>
                <td>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.keyEventEdit',
                        'siteId' => $view->siteId, 'keyEventId' => $owa_ke['id'] ) );?>">Edit</a>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>

<?php endif;?>
</div>
