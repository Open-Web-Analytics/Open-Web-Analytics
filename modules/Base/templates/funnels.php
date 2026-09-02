<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<div class="owa_panelIntro">A funnel is an ordered path through this website. Reports use it
to show where people leave. A funnel can say which goal event reaching the end of it counts
as &mdash; or say nothing, and simply describe the path.</div>

<?php if ( empty( $view->funnels ) ):?>

    <div class="owa_emptyState">
        <p>No funnels yet.</p>
        <p><a class="owa-button" href="<?php echo $view->makeLink( array(
            'do' => 'base.funnelEdit', 'siteId' => $view->siteId ) );?>">Create a funnel</a></p>
    </div>

<?php else:?>

    <p><a class="owa-button" href="<?php echo $view->makeLink( array(
        'do' => 'base.funnelEdit', 'siteId' => $view->siteId ) );?>">Create a funnel</a></p>

    <table class="management">
        <thead>
            <tr>
                <th>Name</th>
                <th>Steps</th>
                <th>Counts as</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $view->funnels as $owa_f ):?>
            <tr>
                <td><?php $view->out( $owa_f['name'] );?></td>
                <td><?php echo (int) $owa_f['step_count'];?></td>
                <td>
                    <?php if ( $owa_f['goal_event_name'] !== '' ):?>
                    <?php $view->out( $owa_f['goal_event_name'] );?>
                    <?php else:?>
                    <?php
                        /*
                         * Not an error. A funnel without a goal event is a path
                         * analysis -- the whole reason the two are only loosely
                         * coupled.
                         */
                    ?>
                    <span class="owa_goalEventIncomplete">nothing &mdash; a path analysis</span>
                    <?php endif;?>
                </td>
                <td>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.funnelEdit',
                        'siteId' => $view->siteId, 'funnelId' => $owa_f['id'] ) );?>">Edit</a>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>

<?php endif;?>
</div>
