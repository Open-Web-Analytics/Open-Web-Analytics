<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php echo $view->headline?></div>

<div id="panel">
<div class="owa_panelIntro">Goals belong to this Observation Profile. Each one names a
condition worth counting &mdash; a page reached, an action taken &mdash; and is measured
only by this Profile.</div>

    <table class="management">
        <thead>
            <tr>
                <th>Goal Number</th>
                <th>Goal Name</th>
                <th>Goal Group</th>
                <th>Goal Type</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>

            <?php foreach ($view->goals as $k => $goal): ?>
            <tr>
                <td>Goal <?php $view->out($k);?> <a class="" href="<?php echo $view->makeLink(array('do' => 'base.optionsGoalEntry', 'goal_number' => $k, 'siteId' => $view->siteId));?>">Edit</a></p></td>
                <td><?php $view->out($goal['goal_name']);?></td>
                <td>
                <?php
                    if ( isset( $goal['goal_group'] ) ) {
                        if ( !empty( $view->goal_groups[$goal['goal_group']] ) ) {
                            $view->out($view->goal_groups[$goal['goal_group']] );
                        } else {
                            $view->out( $goal['goal_group'] );
                        }
                    }
                ?>
                </td>
                <td><?php $view->out($goal['goal_type']);?></td>
                <td><?php $view->out($goal['goal_status']);?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>

        <tfoot>

        </tfoot>
    </table>

</div>
