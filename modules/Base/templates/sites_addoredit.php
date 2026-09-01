<?php /** @var \OWA\Core\ViewScope $view */ ?>
<DIV class="panel_headline"><?php $view->out( $view->headline );?></DIV>
<div id="panel">
<?php
/*
 * The Profile's DETAILS only.
 *
 * This template used to carry three forms -- details, observation settings and
 * allowed users -- stacked on one page that saved in pieces, with nothing
 * saying which tier each belonged to. They are separate screens under the
 * hierarchy nav now, and the access one moved up to the Property, which is what
 * access is actually granted to.
 */
?>
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