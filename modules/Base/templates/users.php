<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php echo $view->headline;?></h1>
<div id="panel">
<fieldset>

    <legend>
        <?php $view->out( \OWA\Core\CoreAPI::t( 'Users' ) );?> <span class="legend_link">(<a href="<?php echo $view->makeLink(array('do' => 'base.usersProfile'));?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Add New User' ) );?></a>)</span>
    </legend>

    <?php if($view->users):?>

    <table class="management">
        <thead>
            <TR>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'User ID' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Real Name' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Role' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Options' ) );?></TH>
            </TR>
        </thead>
        <tbody>
            <?php foreach ($view->users as $user => $value):?>
            <TR>
                <TD><?php $view->out( $value['user_id'] );?></TD>
                <TD><?php $view->out( $value['real_name'] );?></TD>
                <TD><?php $view->out( $value['role'] );?></TD>
                <TD><a href="<?php echo $view->makeLink(array('do' => 'base.usersProfile', 'edit' => true, 'user_id' => $value['user_id']));?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Edit' ) );?></a>
                <?php if ($value['id'] != 1):?>
                | <a href="<?php echo $view->makeLink( array( 'do' => 'base.usersDelete', 'user_id' => $value['user_id'] ), false, false, false, true );?>"
                     data-owa-confirm
                     data-owa-confirm-title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this user?' ) );?>"
                     data-owa-confirm-body="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '“%s” loses access immediately. This cannot be undone.' ), $value['user_id'] ) );?>"
                     data-owa-confirm-proceed="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete user' ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Delete' ) );?></a></TD>
                <?php endif;?>
            </TR>
            <?php endforeach;?>
        </tbody>
    </table>

    <?php else:?>
    <?php $view->out( \OWA\Core\CoreAPI::t( 'There are no User Accounts.' ) );?></TD>
    <?php endif;?>
</fieldset>
</div>