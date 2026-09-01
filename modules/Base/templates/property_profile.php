<?php /** @var \OWA\Core\ViewScope $view */ ?>
<DIV class="panel_headline">Property Details</DIV>
<div id="panel">
<div class="owa_panelIntro">A Property is the website or app being measured. The Observation Profiles beneath it are the ways it is watched, and each carries its own tracking id.</div>
<div style="width:550px;">

    <form method="POST" action="<?php echo $view->makeLink( array( 'do' => 'base.propertyEdit' ) );?>">
        <?php echo $view->createNonceFormField( 'base.propertyEdit' );?>
        <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $view->property['id'] ?? '' );?>">

        <div class="inline_h3">Name</div>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70"
               name="<?php echo $view->getNs();?>name" value="<?php $view->out( $view->property['name'] ?? '' );?>">
        <span class="form-instructions">What the website is called. This is what the site control groups by.</span>
        <BR><BR>

        <div class="inline_h3">Domain</div>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70"
               name="<?php echo $view->getNs();?>domain" value="<?php $view->out( $view->property['domain'] ?? '' );?>">
        <span class="form-instructions">Used to decide which Property a newly added Profile belongs to.</span>
        <BR><BR>

        <div class="inline_h3">Description</div>
        <input class="owa_largeFormField" type="text" size="52"
               name="<?php echo $view->getNs();?>description" value="<?php $view->out( $view->property['description'] ?? '' );?>">
        <BR><BR>

        <input class="owa-button" type="submit" value="Save Property">
    </form>
</div>
</div>
