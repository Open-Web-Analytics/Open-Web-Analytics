<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Clear of the top nav.
 *
 * The margin was 0 at the top, so the heading sat against the nav bar's lower
 * edge with nothing between them -- on the one screen a person reaches when
 * something is already wrong, which is a poor moment to look broken.
 */
?>
<div id="updates" style="width:800px; margin: 30px auto 20px auto;">
    <div class="" style="text-align:center;">
        <h1>Open Web Analytics Updater</h1>
    </div>
    <br>
    <div class="layout_subview" valign="top" style="text-align:left;">

        <h2>Some Modules need to create or update their database tables.</h2>

        <P>Here is the list of modules that have updates that needs to be applied:</P>

        <P>
        <UL>

        <?php foreach ($view->modules as $k => $module): ?>

            <LI><?php echo $module; ?></LI>

        <?php endforeach;?>

        </UL>
        </P>
        <P><I>It is recommended that you backup your database before applying updates.</I></P>
        <BR>

        <P>
        <?php // 5th arg = $add_nonce. base.updatesApply mutates the schema, so
              // the link must carry a nonce or its setNonceRequired() check fails. ?>
        <a href="<?php echo $view->makeLink(array('do' => 'base.updatesApply'), false, '', false, true);?>"><span class="owa-button">Apply updates</span></a>
        </P>

    </div>

</div>



