<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="section">

<table id="layout_panels" class="layout_panels" cellpadding="0" cellspacing="0">
    <TR>
        <TD colspan="2" class="headline">
            <?php $view->out( $view->headline );?>
        </TD>
    </TR>
    <TR>
        <TD colspan="2" class="introtext">
            <P>You are using <strong>Open Web Analytics <?php $view->out(OWA_VERSION);?></strong></P>
            <P>Open Web Analytics has several configuration options that can be set using the controls below. Once changes are made click the "save" button to save the configuration to the database. To learn more about configuring OWA, visit the <a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki">OWA Wiki</a></P>
        </TD>
    </TR>
    <TR>
        <TD valign="top" id="nav_left">

            <?php foreach ($view->panels as $group => $items):?>

                <H4><?php echo $group;?></H4>
                    <UL>
                    <?php foreach ($items as $k => $v):?>
                        <LI><a href="<?php echo $view->makeLink(array('do' => $v['do']));?>"><?php echo $v['anchortext'];?></a></LI>
                    <?php endforeach;?>
                    </UL>
            <?php endforeach;?>
        </TD>
        <TD class="layout_subview"><?php echo $view->subview;?></TD>
    </TR>

</table>
</div>
