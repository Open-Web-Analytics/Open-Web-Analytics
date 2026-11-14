<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( \OWA\Core\CoreAPI::t( 'Organization Details' ) ); ?></h1>
<div id="panel">
<div class="owa_panelIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'The top of the hierarchy. Every Property and every user account belongs to this Organization.' ) ); ?></div>

    <form method="POST" action="<?php echo $view->makeLink( array( 'do' => 'base.organizationEdit' ) );?>">
        <?php echo $view->createNonceFormField( 'base.organizationEdit' );?>

        <label class="inline_h3" for="owa_organizationName"><?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) ); ?></label>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70" id="owa_organizationName"
               name="name" value="<?php $view->out( $view->organization['name'] ?? '' );?>">
        <BR><BR>

        <input class="owa-button" type="submit" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save Organization' ) ); ?>">
    </form>

</div>
