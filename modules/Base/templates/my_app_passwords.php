<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The signed-in user's application passwords.
 *
 * A token appears once, on the response to the create form, and is never
 * drawn again: only its hash is stored.
 */
$owa_passwords = (array) $view->app_passwords;
$owa_scopes    = (array) $view->scopes;
$owa_new       = $view->new_app_password;
?>
<DIV class="panel_headline">Application Passwords</DIV>
<div id="panel">

<?php if ( ! $view->enabled ): ?>
<div class="notice error" role="alert">Application passwords are turned off on this
installation. Yours are kept, but every request that uses one is refused.</div>
<?php endif; ?>

<?php if ( $owa_new ): ?>
<div class="notice owa_appPasswordNew" role="status">
    <p><strong><?php $view->out( $owa_new['name'] ); ?></strong> is ready. Copy it now: it
    will not be shown again.</p>
    <p><input type="text" readonly class="owa_appPasswordToken" size="50"
              aria-label="New application password"
              value="<?php $view->out( $owa_new['token'] ); ?>"></p>
</div>
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

<fieldset class="options">

    <?php if ( $owa_passwords ): ?>
    <table class="management owa_appPasswords">
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
            <?php foreach ( $owa_passwords as $owa_row ): ?>
            <tr data-lookup-id="<?php $view->out( $owa_row['lookup_id'] ); ?>">
                <td><?php $view->out( $owa_row['name'] ); ?></td>
                <td><?php $view->out( $owa_scopes[ $owa_row['scope'] ] ?? $owa_row['scope'] ); ?></td>
                <td><?php $view->out( date( 'Y-m-d H:i', (int) $owa_row['created_at'] ) ); ?></td>
                <td><?php $view->out( $owa_row['last_used_at'] ? date( 'Y-m-d H:i', (int) $owa_row['last_used_at'] ) : 'Never' ); ?></td>
                <td><?php $view->out( (string) $owa_row['last_used_ip'] ); ?></td>
                <td>
                    <form method="post" style="margin:0" action="<?php echo $view->makeLink( array( 'do' => 'base.myAppPasswordRevoke' ) ); ?>">
                        <?php echo $view->createNonceFormField( 'base.myAppPasswordRevoke' ); ?>
                        <input type="hidden" name="lookup_id" value="<?php $view->out( $owa_row['lookup_id'] ); ?>">
                        <input type="hidden" name="siteId" value="<?php $view->out( $view->siteId ); ?>">
                        <button type="submit" class="owa-button owa-button-quiet owa_appPasswordRevoke"
                                data-owa-confirm
                                data-owa-confirm-title="Revoke this application password?"
                                data-owa-confirm-body="Anything using &ldquo;<?php $view->out( $owa_row['name'] ); ?>&rdquo; stops working immediately."
                                data-owa-confirm-proceed="Revoke">Revoke</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="owa_appPasswordsNone">You have no application passwords.</p>
    <?php endif; ?>
</fieldset>

<BR>

<form method="post" name="owa_appPasswordCreate"
      action="<?php echo $view->makeLink( array( 'do' => 'base.myAppPasswordCreate' ) ); ?>">

    <fieldset name="owa-options" class="options">

        <legend>Add an application password</legend>

        <div class="setting">
            <div class="title">Name</div>
            <div class="description">What will use it, so you know which one to revoke later.</div>
            <div class="field">
                <input type="text" size="30" maxlength="255" name="name" required>
            </div>
        </div>

        <div class="setting">
            <div class="title">Access</div>
            <div class="description">Read only allows GET requests and nothing else.</div>
            <div class="field">
                <select name="scope">
                    <?php foreach ( $owa_scopes as $owa_scope => $owa_label ): ?>
                    <option value="<?php $view->out( $owa_scope ); ?>"><?php $view->out( $owa_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <BR>

        <?php echo $view->createNonceFormField( 'base.myAppPasswordCreate' ); ?>
        <input type="hidden" name="siteId" value="<?php $view->out( $view->siteId ); ?>">
        <input class="owa-button" type="submit" value="Add Application Password">
    </fieldset>
</form>
</div>
