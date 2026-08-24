<?php /** @var \OWA\Core\ViewScope $view */ ?>
<H2><?php echo $view->headline;?>: <?php $view->out($view->period_label);?></H2>

<table>
    <?php if (!empty($view->visitors)):?>
    <?php foreach ($view->visitors as $visitor):?>
    <TR>
        <TD><img src="<?php echo $view->makeImageLink('base/i/user_icon_small.gif');?>" align="top">
            <a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'visitor', 'visitor_id' => $visitor['visitor_id']));?>">
            <?php if(!empty($visitor['user_name'])):
                $view->out( $visitor['user_name'] );
            elseif(!empty($visitor['user_email'])):
                $view->out( $visitor['user_email'] );
            else:
                $view->out( $visitor['visitor_id'] );
            endif;?>
            </a>
        </TD>
    </TR>
    <?php endforeach;?>
    <?php else:?>
    <TR>
        <TD>
            There are no visitors during this time period.
        </TD>
    </TR>
    <?php endif;?>
</table>