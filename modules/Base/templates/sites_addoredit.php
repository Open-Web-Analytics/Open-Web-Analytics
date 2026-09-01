<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The Profile's DETAILS only.
 * 
 * This carried three forms -- details, observation settings and allowed
 * users -- stacked on one page that saved in pieces. They are separate
 * screens now, and the access one moved up to the Property.
 * 
 * It opens AND closes its own #panel. The original opened it here and
 * closed it at the very end of the third form, so splitting the file left
 * this one holding an unclosed div -- which swallows everything after it
 * and makes the page look broken rather than merely short.
 */
?>
<DIV class="panel_headline"><?php $view->out( $view->headline );?></DIV>
<div id="panel">
<fieldset>

    <legend>Site Profile</legend>

    <form method="POST">

    <table class="management">
        <?php if ($view->edit == true):?>
        <TR>
            <TH>Site ID:</TH>
            <TD><?php $view->out( $view->site['site_id'] );?></TD>
            <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] );?>">

        </TR>
        <?php endif;?>
        <TR>
            <TH>Domain:</TH>
            <?php if ($view->edit == true):?>
            <input type="hidden" name="<?php echo $view->getNs();?>domain" value="<?php $view->out( $view->site['domain'] );?>">
            <TD><?php $view->out( $view->site['domain'] );?></TD>
            <?php else:?>
            <TD>  
                <input type="text" name="<?php echo $view->getNs();?>domain" size="52" maxlength="70" value="<?php $view->out( $view->site['domain'] ?? '' );?>"><BR>
                Example: http://some.domain.com<BR>
                <span class="validation_error"><?php $view->out( $view->validation_errors['domain'] ?? '' );?></span>
            </TD>
            <?php endif;?>
        </TR>
        <TR>
            <TH>Site Name:</TH>
            <TD><input type="text" name="<?php echo $view->getNs();?>name" size="52" maxlength="70" value="<?php $view->out( $view->site['name'] ?? '' );?>">
				<span class="form-instructions">Example: My Website</span>            
            </TD>
        </TR>
        <TR>
            <TH>Description:</TH>
            <TD>
                <textarea name="<?php echo $view->getNs();?>description" cols="52" rows="3"><?php $view->out( $view->site['description'] ?? '' );?></textarea>
            </TD>
        </TR>



    </table>
    <BR>
    <?php echo $view->createNonceFormField($view->action);?>
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="<?php $view->out( $view->action, false );?>">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Profile">

    </form>

</fieldset>
</div>
