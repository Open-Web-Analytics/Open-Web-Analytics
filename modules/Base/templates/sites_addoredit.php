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
<div class="owa_panelIntro">An Observation Profile is one way of watching a Property.
It carries its own tracking id, so a Property watched two ways has two Profiles and
two tags.</div>
<fieldset>

    <legend>Observation Profile</legend>

    <form method="POST">

    <table class="management">
        <?php if ($view->edit == true):?>
        <TR>
            <TH>Tracking ID:</TH>
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
            <TH>Profile Name:</TH>
            <TD><input type="text" name="<?php echo $view->getNs();?>name" size="52" maxlength="70" value="<?php $view->out( $view->site['name'] ?? '' );?>">
				<span class="form-instructions">Example: Main Profile</span>            
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

<?php if ( $view->edit == true && $view->getCurrentUser()->isCapable('edit_sites') ):?>
<?php
/*
 * Deleting an Observation Profile.
 *
 * This lived on the base.sites roster, which is gone -- so the action was
 * still registered and still worked, but nothing linked it and there was no
 * way to delete a Profile at all. It belongs here: this is the screen that
 * already owns this one Profile.
 *
 * Its own form, not a second submit on the one above, so a stray Enter in a
 * text field cannot reach it. The nonce is minted for base.sitesDelete
 * specifically -- one action, one nonce.
 */
?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this Observation Profile</div>
    <div class="owa_dangerZoneBody">
        This removes the Profile and stops it being reported on. The Property it
        observes is not affected, and neither is any other Profile of that
        Property. This cannot be undone.
    </div>
    <form method="post" onsubmit="return confirm('Delete this Observation Profile? This cannot be undone.');">
        <?php echo $view->createNonceFormField('base.sitesDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.sitesDelete">
        <input class="owa-button owa-button-danger" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Delete Profile">
    </form>
</div>
<?php endif;?>

</fieldset>
</div>
