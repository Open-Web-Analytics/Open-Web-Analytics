<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One row of the application password roster, from $owa_row.
 *
 * Also drawn once, empty, inside a <template> that the Add dialog fills for a
 * password it has just made; the data-field cells are the ones it sets.
 */
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
