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
        <?php if ($view->edit == true):?>
        <TR>
            <TH>Property:</TH>
            <TD>
                <?php $view->out( $view->propertyName ?? '' );?>
                <?php $owa_ident = ( $view->site['stream_type'] ?? 'web' ) === 'app'
                        ? ( $view->site['app_id'] ?? '' )
                        : ( $view->site['domain'] ?? '' );?>
                <?php if ( $owa_ident ):?>
                <span class="owa_siteControlDomain"><?php $view->out( $owa_ident );?></span>
                <?php endif;?>
                <span class="form-instructions">The Property this Profile belongs to, and
                what this Profile observes.</span>
            </TD>
        </TR>
        <?php else:?>
        <?php
        /*
         * WHICH Property, rather than "what domain".
         *
         * The old form asked for a domain and derived a Property from it, which
         * made the domain the Property's key: adding a second Profile to a
         * website meant retyping its domain, and a Property with no domain (an
         * app) could never be chosen at all. Choosing is also what makes an
         * empty Property usable -- there was previously no way to put a Profile
         * back into one.
         */
        ?>
        <TR>
            <TH>Property:</TH>
            <TD>
                <select name="<?php echo $view->getNs();?>propertyId" id="owa_profilePropertyId" class="owa_largeFormField">
                    <option value="">&mdash; a new Property &mdash;</option>
                    <?php foreach ( (array) ( $view->properties ?? array() ) as $owa_prop ):?>
                    <option value="<?php $view->out( $owa_prop['id'] );?>"
                        <?php echo ( ( $view->propertyId ?? '' ) === (string) $owa_prop['id'] ) ? 'selected' : '';?>>
                        <?php $view->out( $owa_prop['name'] );?><?php if ( $owa_prop['domain'] ):?> (<?php $view->out( $owa_prop['domain'] );?>)<?php endif;?>
                    </option>
                    <?php endforeach;?>
                </select>
                <span class="form-instructions">The Property this Profile belongs to. Pick
                an existing one to add another way of observing it, or create a new one.</span>
                <span class="validation_error"><?php $view->out( $view->validation_errors['propertyId'] ?? '' );?></span>
            </TD>
        </TR>
        <TR id="owa_newPropertyFields">
            <TH>New Property Name:</TH>
            <TD>
                <input type="text" name="<?php echo $view->getNs();?>name" size="52" maxlength="70" value="<?php $view->out( $view->site['name'] ?? '' );?>">
                <span class="form-instructions">What the website or product is called. Only
                used when creating a new Property.</span>
                <span class="validation_error"><?php $view->out( $view->validation_errors['name'] ?? '' );?></span>
            </TD>
        </TR>
        <?php
        /*
         * What this Profile observes, and therefore which identifier it needs.
         *
         * On the Profile rather than the Property because the answer differs
         * per Profile: one Property can hold a website and its app, and there
         * is no single identifier that describes both.
         */
        $owa_stream = $view->site['stream_type'] ?? \OWA\Module\Base\Entity\Site::STREAM_WEB;
        ?>
        <TR>
            <TH>This Profile observes:</TH>
            <TD>
                <select name="<?php echo $view->getNs();?>streamType" id="owa_streamType" class="owa_largeFormField">
                    <option value="web" <?php echo $owa_stream === 'app' ? '' : 'selected';?>>A website</option>
                    <option value="app" <?php echo $owa_stream === 'app' ? 'selected' : '';?>>An app</option>
                </select>
            </TD>
        </TR>
        <TR id="owa_streamWebFields">
            <TH>Domain:</TH>
            <TD>
                <input type="text" name="<?php echo $view->getNs();?>domain" size="52" maxlength="70" value="<?php $view->out( $view->site['domain'] ?? '' );?>">
                <span class="form-instructions">The domain of the website being observed.</span>
                <span class="validation_error"><?php $view->out( $view->validation_errors['domain'] ?? '' );?></span>
            </TD>
        </TR>
        <TR id="owa_streamAppFields">
            <TH>App ID:</TH>
            <TD>
                <input type="text" name="<?php echo $view->getNs();?>appId" size="52" maxlength="70" value="<?php $view->out( $view->site['app_id'] ?? '' );?>">
                <span class="form-instructions">The bundle id or package name, for example
                com.example.myapp.</span>
                <span class="validation_error"><?php $view->out( $view->validation_errors['appId'] ?? '' );?></span>
            </TD>
        </TR>
        <?php endif;?>
        <?php if ($view->edit == true):?>
        <TR>
            <TH>Profile Name:</TH>
            <TD><input type="text" name="<?php echo $view->getNs();?>name" size="52" maxlength="70" value="<?php $view->out( $view->site['name'] ?? '' );?>">
				<span class="form-instructions">Example: Main Profile</span>            
            </TD>
        </TR>
        <?php endif;?>
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
        This Profile stops observing and disappears from reporting. The Property
        it observes is not affected, and neither is any other Profile of that
        Property &mdash; if this is the last one, the Property is left empty and
        you can add new Profiles to it.
    </div>
    <form method="post">
        <?php echo $view->createNonceFormField('base.sitesDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.sitesDelete">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Profile"
               data-owa-confirm
               data-owa-confirm-title="Delete this Observation Profile?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $view->site['name'] ?? '' );?>&rdquo; will stop recording immediately and will no longer appear in reporting. Everything it has already collected is kept, and an administrator can restore it."
               data-owa-confirm-proceed="Delete Profile">
    </form>
</div>
<?php endif;?>

</fieldset>
</div>
