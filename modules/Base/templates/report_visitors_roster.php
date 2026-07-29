<H2><?php echo $headline;?>: <?php echo $date_label;?></H2>

<table>
    <?php if (!empty($visitors)):?>
    <?php foreach ($visitors as $visitor):?>
    <TR>
        <TD><img src="<?php echo $this->makeImageLink('base/i/user_icon_small.gif');?>" align="top">
            <a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $visitor['visitor_id'], 'period' => 'all_time'));?>">
            <?php if(!empty($visitor['user_name'])):
                $this->out( $visitor['user_name'] );
            elseif(!empty($visitor['user_email'])):
                $this->out( $visitor['user_email'] );
            else:
                $this->out( $visitor['visitor_id'] );
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