<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Access is granted to a PROPERTY, not to one way of observing it. The grants
 * are stored per Profile; PropertyGrants shows a user as granted when any
 * Profile is, and the save writes every Profile.
 *
 * The submit is a delta over the users it rendered, so it must render every
 * user, never a partial list.
 */
?>
<DIV class="panel_headline">Property Access Management</DIV>
<div id="panel">
<div class="owa_panelIntro">Who can see this Property. A grant carries three capabilities &mdash; viewing reports, viewing e-commerce reports, and editing the Property &mdash; and covers every Observation Profile beneath it. Administrators always have access and cannot be changed here.</div>
<form method="post" name="owa-allowedusersform">
    <fieldset name="owa-allowedusers" class="options">
    <legend>Allowed Users</legend>

        <?php if ( $view->edit ): ?>

        <div class="field">
            <input type="text" id="owa-user-filter" class="owa-user-filter"
                placeholder="Filter by name, login or role" autocomplete="off">
        </div>

        <table class="management owa-allowed-users">
            <tr>
                <th style="width:1%"></th>
                <th>Login</th>
                <th>Name</th>
                <th>Role</th>
            </tr>
            <?php foreach ($view->users as $user):
                $isAdmin   = ( $user['role'] ?? '' ) === 'admin';
                $isAllowed = in_array( (int) $user['id'], $view->grantedUserIds, true );
            ?>
            <tr class="owa-user-row">
                <td>
                    <?php if ( $isAdmin ): ?>
                        <input type="checkbox" checked disabled title="Administrators always have access">
                    <?php else: ?>
                        <input type="checkbox"
                            name="<?php echo $view->getNs();?>allowed_users[]"
                            value="<?php $view->out( $user['id'] );?>"
                            id="owa-user-<?php $view->out( $user['id'] );?>"
                            <?php if ( $isAllowed ): ?>checked<?php endif;?>>
                        <input type="hidden"
                            name="<?php echo $view->getNs();?>rendered_users[]"
                            value="<?php $view->out( $user['id'] );?>">
                    <?php endif;?>
                </td>
                <td><label for="owa-user-<?php $view->out( $user['id'] );?>"><?php $view->out( $user['user_id'] );?></label></td>
                <td><?php $view->out( $user['real_name'] );?></td>
                <td>
                    <?php $view->out( $user['role'] );?>
                    <?php if ( $isAdmin ): ?><span class="owa-always">always has access</span><?php endif;?>
                </td>
            </tr>
            <?php endforeach;?>
        </table>

        <br>
        <?php echo $view->createNonceFormField('base.propertyAccessUpdate');?>
        <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $view->propertyId );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.propertyAccessUpdate">
        <input type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Users" class="owa-button">

        <?php endif;?>

    </fieldset>
</form>

<style>
.owa-allowed-users td { vertical-align: middle; }
.owa-user-filter { width: 22em; }
.owa-always { color: #767676; font-size: 0.9em; margin-left: 0.5em; }
</style>

<script>
jQuery(document).ready(function() {
    jQuery('#owa-user-filter').on('keyup', function() {
        var needle = jQuery(this).val().toLowerCase();
        jQuery('.owa-allowed-users tr.owa-user-row').each(function() {
            jQuery(this).toggle( jQuery(this).text().toLowerCase().indexOf( needle ) !== -1 );
        });
    });
});
</script>
</div>
