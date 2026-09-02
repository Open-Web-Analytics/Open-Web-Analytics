<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php echo $view->headline;?></div>
<div id="panel">
<fieldset>

    <legend>
        Users <span class="legend_link">(<a href="<?php echo $view->makeLink(array('do' => 'base.usersProfile'));?>">Add New User</a>)</span>
    </legend>

    <?php if($view->users):?>

    <table class="management">
        <thead>
            <TR>
                <TH>User ID</TH>
                <TH>Real Name</TH>
                <TH>Role</TH>
                <TH>Options</TH>
            </TR>
        </thead>
        <tbody>
            <?php foreach ($view->users as $user => $value):?>
            <TR>
                <TD><?php $view->out( $value['user_id'] );?></TD>
                <TD><?php $view->out( $value['real_name'] );?></TD>
                <TD><?php $view->out( $value['role'] );?></TD>
                <TD><a href="<?php echo $view->makeLink(array('do' => 'base.usersProfile', 'edit' => true, 'user_id' => $value['user_id']));?>">Edit</a>
                <?php if ($value['id'] != 1):?>
                | <a href="<?php echo $view->makeLink( array( 'do' => 'base.usersDelete', 'user_id' => $value['user_id'] ), false, false, false, true );?>"
                     data-owa-confirm
                     data-owa-confirm-title="Delete this user?"
                     data-owa-confirm-body="&ldquo;<?php $view->out( $value['user_id'] );?>&rdquo; loses access immediately. This cannot be undone."
                     data-owa-confirm-proceed="Delete user">Delete</a></TD>
                <?php endif;?>
            </TR>
            <?php endforeach;?>
        </tbody>
    </table>

    <?php else:?>
    There are no User Accounts.</TD>
    <?php endif;?>
</fieldset>
</div>