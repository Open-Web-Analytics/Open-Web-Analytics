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
</div>
