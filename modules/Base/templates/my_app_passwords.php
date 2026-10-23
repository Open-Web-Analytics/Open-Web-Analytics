<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The signed-in user's application passwords.
 *
 * Add opens a dialog whose form is sent with fetch() to
 * base.myAppPasswordCreateAjax, and the token is shown there, once; the row is added to
 * the roster when the answer arrives. Nothing is posted to this page, so a reload
 * repeats nothing. Only the token's hash is stored, so it is never drawn again.
 */
$owa_passwords = (array) $view->app_passwords;
$owa_scopes    = (array) $view->scopes;
$owa_empty_row = array( 'lookup_id' => '', 'name' => '', 'scope' => '', 'created_at' => 0,
                        'last_used_at' => 0, 'last_used_ip' => '' );

/*
 * One roster row. Drawn for each password, and once, empty, inside the
 * <template> that the Add dialog fills for a password it has just made; the
 * data-field cells are the ones it sets.
 */
$owa_password_row = function ( array $owa_row ) use ( $view, $owa_scopes ) {
?>
    <tr data-lookup-id="<?php $view->out( $owa_row['lookup_id'] ); ?>">
        <td data-field="name"><?php $view->out( $owa_row['name'] ); ?></td>
        <td data-field="scope"><?php $view->out( $owa_scopes[ $owa_row['scope'] ] ?? $owa_row['scope'] ); ?></td>
        <td data-field="created"><?php $view->out( $owa_row['created_at'] ? date( 'Y-m-d H:i', (int) $owa_row['created_at'] ) : '' ); ?></td>
        <td><?php $view->out( $owa_row['last_used_at'] ? date( 'Y-m-d H:i', (int) $owa_row['last_used_at'] ) : 'Never' ); ?></td>
        <td><?php $view->out( (string) $owa_row['last_used_ip'] ); ?></td>
        <td>
            <form method="post" style="margin:0" action="<?php echo $view->makeLink( array( 'do' => 'base.myAppPasswordRevoke' ) ); ?>">
                <?php echo $view->createNonceFormField( 'base.myAppPasswordRevoke' ); ?>
                <input type="hidden" name="lookup_id" data-field="lookup_id" value="<?php $view->out( $owa_row['lookup_id'] ); ?>">
                <input type="hidden" name="siteId" value="<?php $view->out( $view->siteId ); ?>">
                <button type="submit" class="owa-button owa-button-quiet owa_appPasswordRevoke"
                        data-owa-confirm
                        data-owa-confirm-title="Revoke this application password?"
                        data-owa-confirm-body="Anything using &ldquo;<?php $view->out( $owa_row['name'] ); ?>&rdquo; stops working immediately."
                        data-owa-confirm-proceed="Revoke">Revoke</button>
            </form>
        </td>
    </tr>
<?php
};
?>
<DIV class="panel_headline">Application Passwords</DIV>
<div id="panel">

<?php if ( ! $view->enabled ): ?>
<div class="notice error" role="alert">Application passwords are turned off on this
installation. Yours are kept, but every request that uses one is refused.</div>
<?php endif; ?>

<div class="owa_panelIntro">
    <p>An application password lets a script or another application use the REST API as you,
    without your login password. It has your role's access, or less if it is read only. Revoke one
    and anything using it stops working immediately.<?php if ( $view->require_https ): ?> Requests
    must use HTTPS.<?php endif; ?></p>
    <p>Send it in the <code>Authorization</code> header:</p>
    <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><code>curl -H "Authorization: Bearer &lt;password&gt;" "<?php
        $view->out( $view->api_url ); ?>?owa_rest_params=base/v1/sites"</code></pre>
</div>

<p><button type="button" class="owa-button owa_appPasswordAdd">Add Application Password</button></p>

<fieldset class="options">

    <table class="management owa_appPasswords"<?php if ( ! $owa_passwords ): ?> hidden<?php endif; ?>>
        <thead>
            <tr>
                <th>Name</th>
                <th>Access</th>
                <th>Created</th>
                <th>Last used</th>
                <th>From</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $owa_passwords as $owa_row ) { $owa_password_row( $owa_row ); } ?>
        </tbody>
    </table>
    <p class="owa_appPasswordsNone"<?php if ( $owa_passwords ): ?> hidden<?php endif; ?>>You have no application passwords.</p>
</fieldset>

<template id="owa_appPasswordRowTemplate">
    <?php $owa_password_row( $owa_empty_row ); ?>
</template>

<div id="owa_appPasswordDialog" class="owa_appPasswordDialog" title="Add an application password" hidden>

    <form method="post" class="owa_appPasswordForm" novalidate
          action="<?php echo $view->makeLink( array( 'do' => 'base.myAppPasswordCreateAjax' ) ); ?>">
        <?php echo $view->createNonceFormField( 'base.myAppPasswordCreateAjax' ); ?>
        <div class="setting">
            <div class="title"><label for="owa_appPasswordName">Name</label></div>
            <div class="description">What will use it, so you know which one to revoke later.</div>
            <div class="field">
                <input type="text" id="owa_appPasswordName" size="30" maxlength="255" name="name" required>
            </div>
        </div>

        <div class="setting">
            <div class="title"><label for="owa_appPasswordScope">Access</label></div>
            <div class="description">Read only allows GET requests and nothing else.</div>
            <div class="field">
                <select id="owa_appPasswordScope" name="scope">
                    <?php foreach ( $owa_scopes as $owa_scope => $owa_label ): ?>
                    <option value="<?php $view->out( $owa_scope ); ?>"><?php $view->out( $owa_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <div class="owa_appPasswordResult" hidden>
        <p><strong class="owa_appPasswordResultName"></strong> is ready. Copy it now: it will not be
        shown again.</p>
        <p><input type="text" readonly class="owa_appPasswordToken" size="50"
                  aria-label="New application password"></p>
    </div>

    <p class="owa_appPasswordError" role="alert" hidden></p>
</div>
</div>
