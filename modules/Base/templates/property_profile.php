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

        <?php
        /*
         * WHAT KIND, and it is asked once.
         *
         * Only on the add path. The kind decides which identifier the Property
         * is known by -- a domain for a website, a bundle id for an app -- and
         * every Profile beneath it is set up against that identifier. Changing
         * it later would invalidate the lot, so on the edit path it is STATED
         * rather than offered.
         *
         * Frozen to web for now. App Properties arrive with app stream support;
         * offering the choice before then would let someone create a Property
         * that nothing can report into.
         */
        $owa_isNew = empty( $view->property['id'] );
        $owa_type  = $view->property['property_type'] ?? \OWA\Module\Base\Entity\Property::TYPE_WEB;
        $owa_type  = $owa_type ?: \OWA\Module\Base\Entity\Property::TYPE_WEB;
        $owa_types = \OWA\Module\Base\Entity\Property::types();
        $owa_isWeb = $owa_type === \OWA\Module\Base\Entity\Property::TYPE_WEB;
        ?>

        <div class="inline_h3">This Property is</div>
        <?php if ( $owa_isNew ):?>
        <select class="owa_largeFormField" name="<?php echo $view->getNs();?>propertyType">
            <option value="<?php $view->out( \OWA\Module\Base\Entity\Property::TYPE_WEB );?>" selected>
                <?php $view->out( $owa_types[ \OWA\Module\Base\Entity\Property::TYPE_WEB ] );?>
            </option>
        </select>
        <span class="form-instructions">Chosen once. It decides which identifier this Property is
        known by, and every Observation Profile beneath it is set up against that &mdash; so it
        cannot be changed afterwards. Apps arrive with app stream support.</span>
        <?php else:?>
        <span class="owa_statedValue"><?php $view->out( $owa_types[ $owa_type ] ?? $owa_type );?></span>
        <?php
            /*
             * Posted back as a hidden field, not left out.
             *
             * The save reads propertyType; a form that shows the kind but does
             * not send it would have the save fall back to the default, so
             * editing an app Property's name would quietly turn it into a web
             * one. The save ignores it on an existing row anyway -- this is the
             * belt to that braces.
             */
        ?>
        <input type="hidden" name="<?php echo $view->getNs();?>propertyType" value="<?php $view->out( $owa_type );?>">
        <span class="form-instructions">Chosen when this Property was created, and not editable
        &mdash; every Observation Profile beneath it is set up against the identifier it
        implies.</span>
        <?php endif;?>
        <BR><BR>

        <div class="inline_h3">Domain<?php if ( ! $owa_isWeb ):?> <span class="owa_optional">optional</span><?php endif;?></div>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70"
               name="<?php echo $view->getNs();?>domain" value="<?php $view->out( $view->property['domain'] ?? '' );?>">
        <span class="form-instructions">The domain of the website or application being observed.<?php
            if ( $owa_isWeb ):?> Required: it is the origin a tracking request is accepted or
        refused on.<?php endif;?></span>
        <?php
            /*
             * No per-field error span here. A refused save on this screen comes
             * back as the page-level error_msg the chrome shows -- see
             * PropertyEdit::errorAction() -- the same way the name field's does.
             * A span reading a validation_errors the view does not forward
             * would print nothing and look like the check was missing.
             */
        ?>
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
