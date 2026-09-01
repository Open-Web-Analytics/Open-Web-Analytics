<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div style="width:550px;">
    <div class="inline_h1" style="text-align:left;">Property Details</div><BR>
    <div class="inline_h2" style="text-align:left;">A Property is the website or app.
    The Observation Profiles beneath it are the ways it is being watched, and each of
    those carries its own tracking id.</div><BR>

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
        <span class="form-instructions">Used to decide which Property a newly added site belongs to.</span>
        <BR><BR>

        <div class="inline_h3">Description</div>
        <input class="owa_largeFormField" type="text" size="52"
               name="<?php echo $view->getNs();?>description" value="<?php $view->out( $view->property['description'] ?? '' );?>">
        <BR><BR>

        <input class="owa-button" type="submit" value="Save Property">
    </form>
</div>
