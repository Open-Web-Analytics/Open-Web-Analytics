<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div style="width:550px;">
    <div class="inline_h1" style="text-align:left;">Organization Details</div><BR>
    <div class="inline_h2" style="text-align:left;">Every Property and every user
    account belongs to this Organization.</div><BR>

    <form method="POST" action="<?php echo $view->makeLink( array( 'do' => 'base.organizationEdit' ) );?>">
        <?php echo $view->createNonceFormField( 'base.organizationEdit' );?>

        <div class="inline_h3">Name</div>
        <input class="owa_largeFormField" type="text" size="52" maxlength="70"
               name="<?php echo $view->getNs();?>name" value="<?php $view->out( $view->organization['name'] ?? '' );?>">
        <BR><BR>

        <input class="owa-button" type="submit" value="Save Organization">
    </form>

    <?php
        /*
         * User accounts live in the Organization -- that is what the top tier
         * is for -- so this is where they are reached from. It used to be a
         * "User Management" entry in the settings nav, which put the people who
         * belong to an Organization somewhere that never mentioned one.
         */
    ?>
    <?php if ( $view->getCurrentUser()->isCapable('edit_users') ):?>
    <BR>
    <div class="inline_h3">Users</div>
    <span class="form-instructions">Everyone with an account in this Organization.</span><BR>
    <a href="<?php echo $view->makeLink( array( 'do' => 'base.users' ) );?>">Manage users</a>
    <?php endif;?>
</div>
