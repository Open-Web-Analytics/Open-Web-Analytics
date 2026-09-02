<?php /** @var \OWA\Core\ViewScope $view */ ?>
<DIV class="panel_headline"><?php $view->out( $view->headline );?></DIV>
<div id="panel">
<div class="owa_panelIntro">A Property is the thing being measured. The Observation Profiles beneath it are the ways it is watched, and each carries its own tracking id.</div>

    <form method="POST" action="<?php echo $view->makeLink( array( 'do' => 'base.propertyEdit' ) );?>">
        <?php echo $view->createNonceFormField( 'base.propertyEdit' );?>
        <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $view->property['id'] ?? '' );?>">

        <div class="inline_h3">Name</div>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70"
               name="<?php echo $view->getNs();?>name" value="<?php $view->out( $view->property['name'] ?? '' );?>">
        <span class="form-instructions">What this Property is called. Profiles are grouped under this name.</span>
        <BR><BR>

        <div class="inline_h3">Domain <span class="owa_optional">optional</span></div>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70"
               name="<?php echo $view->getNs();?>domain" value="<?php $view->out( $view->property['domain'] ?? '' );?>">
        <span class="form-instructions">The domain of the website or application being observed.</span>
        <BR><BR>

        <div class="inline_h3">Description <span class="owa_optional">optional</span></div>
        <input class="owa_largeFormField" type="text" size="52"
               name="<?php echo $view->getNs();?>description" value="<?php $view->out( $view->property['description'] ?? '' );?>">
        <BR><BR>

        <input class="owa-button" type="submit" value="Save Property">
    </form>

<?php if ( ! empty( $view->property['id'] ) && $view->getCurrentUser()->isCapable('edit_sites') ):?>
<?php
/*
 * Deleting a Property.
 *
 * The cascade runs DOWNWARD only. Removing a Property is how someone says
 * "stop observing this website", so it takes its Observation Profiles with it
 * -- explicitly, because that is what they clicked. It does not run the other
 * way: archiving a Property's last Profile leaves the Property empty and
 * reachable, so its Profiles can be started over.
 *
 * Only on the edit path -- there is nothing to delete while adding.
 */
?>
<div class="owa_dangerZone">
    <div class="owa_dangerZoneTitle">Delete this Property</div>
    <div class="owa_dangerZoneBody">
        This removes the Property and every Observation Profile under it. Those
        Profiles stop recording and disappear from reporting.
    </div>
    <form method="post">
        <?php echo $view->createNonceFormField('base.propertyDelete');?>
        <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $view->property['id'] );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.propertyDelete">
        <input class="owa-button owa-button-danger" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete Property"
               data-owa-confirm
               data-owa-confirm-title="Delete this Property?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $view->property['name'] ?? '' );?>&rdquo; and <?php echo (int) ( $view->profileCount ?? 0 );?> Observation Profile(s) under it will stop recording immediately and will no longer appear in reporting. Everything already collected is kept, and an administrator can restore it."
               data-owa-confirm-proceed="Delete Property">
    </form>
</div>
<?php endif;?>
</div>
