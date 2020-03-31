<div class="panel_headline"><?php echo $headline;?></div>
<div id="panel">
<fieldset>

    <legend>
        Users <span class="legend_link">(<a href="<?php echo $this->makeLink(array('do' => 'base.usersProfile'));?>">Add New User</a>)</span>
    </legend>

    <?php if($users):?>

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
            <?php foreach ($users as $user => $value):?>
            <TR>
                <TD><?php $this->out( $value['user_id'] );?></TD>
                <TD><?php $this->out( $value['real_name'] );?></TD>
                <TD><?php $this->out( $value['role'] );?></TD>
                <TD><a href="<?php echo $this->makeLink(array('do' => 'base.usersProfile', 'edit' => true, 'user_id' => $value['user_id']));?>">Edit</a>
                <?php if ($value['id'] != 1):?>
                | <a href="<?php echo $this->makeLink( array( 'do' => 'base.usersDelete', 'user_id' => $value['user_id'] ), false, false, false, true );?>">Delete</a></TD>
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