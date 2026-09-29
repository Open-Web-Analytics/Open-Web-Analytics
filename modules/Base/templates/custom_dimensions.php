<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Custom dimensions belong to a PROPERTY, because the reporting cube does.
 * Two Properties may use the same key for different things, which is the
 * point -- v1's numbered slots forced one namespace on an installation.
 *
 * REGISTERING DOES NOT ADD THE COLUMN HERE. It writes the registration and
 * returns; the column is added under the lock a cube build holds, within
 * minutes. The list says which state each one is in, because otherwise the
 * gap between "registered" and "reportable" would look like a fault.
 */
?>
<DIV class="panel_headline">Custom Dimensions</DIV>
<div id="panel">

<div class="owa_panelIntro">
    Values your site sets on an event or a visitor become reportable by being registered here.
    Everything you send is stored either way &mdash; registering is what gives a value a column
    of its own so reports can group by it.
</div>

<?php if ( ! $view->cube['exists'] ): ?>

    <div class="owa_panelIntro">
        <b>This Property has not collected anything yet.</b>
        Its reporting cube is created the first time data arrives, and there is nothing to
        add a column to until then. Come back once the tracker has sent something.
    </div>

<?php else: ?>

    <?php $owa_hasRoom = count( $view->dimensions ) < $view->cube['capacity']; ?>

    <fieldset>
    <legend>Registered</legend>

    <?php if ( $owa_hasRoom ): ?>
    <div class="owa_cdActions">
        <button type="button" class="owa-button" data-owa-cd-open>Register a dimension</button>
    </div>
    <?php endif; ?>

    <?php if ( ! $view->dimensions ): ?>

        <div class="owa_panelIntro">None yet.</div>

    <?php else: ?>

        <table class="management">
            <tr>
                <th>Name</th>
                <th>Label</th>
                <th>Scope</th>
                <th>Type</th>
                <th>Status</th>
                <th style="width:1%"></th>
            </tr>
            <?php foreach ( $view->dimensions as $dimension ): ?>
            <tr>
                <td><code><?php $view->out( $dimension['dimension_key'] ); ?></code></td>
                <td><?php $view->out( $dimension['label'] ); ?></td>
                <td><?php $view->out( $dimension['scope'] ); ?></td>
                <td><?php $view->out( $dimension['data_type'] ); ?></td>
                <td>
                    <?php if ( $dimension['state'] === 'applied' ): ?>
                        Reportable
                    <?php elseif ( $dimension['state'] === 'failed' ): ?>
                        <b>Could not be added.</b>
                        <?php $view->out( $dimension['state_message'] ); ?>
                    <?php else: ?>
                        Being added &mdash; reportable within a few minutes
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post">
                        <?php echo $view->createNonceFormField( 'base.customDimensionDelete' ); ?>
                        <input type="hidden" name="<?php echo $view->getNs(); ?>propertyId"
                            value="<?php $view->out( $view->propertyId ); ?>">
                        <input type="hidden" name="<?php echo $view->getNs(); ?>siteId"
                            value="<?php $view->out( $view->siteId ); ?>">
                        <input type="hidden" name="<?php echo $view->getNs(); ?>dimensionKey"
                            value="<?php $view->out( $dimension['dimension_key'] ); ?>">
                        <input type="hidden" name="<?php echo $view->getNs(); ?>action"
                            value="base.customDimensionDelete">
                        <input class="owa-button owa-button-danger" type="submit"
                            name="<?php echo $view->getNs(); ?>submit_btn" value="Remove"
                            data-owa-confirm
                            data-owa-confirm-title="Remove this custom dimension?"
                            data-owa-confirm-body="&ldquo;<?php $view->out( $dimension['dimension_key'] ); ?>&rdquo; stops being reportable, and its column and the values in it go at the next cube build. The events they were read from are untouched, so registering it again and rebuilding brings them back."
                            data-owa-confirm-proceed="Remove dimension">
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

    <div class="owa_panelIntro">
        <?php echo (int) count( $view->dimensions ); ?> of <?php echo (int) $view->cube['capacity']; ?> used.
        <?php if ( $view->cube['capacity'] < $view->cube['cap'] ): ?>
            (<?php echo (int) $view->cube['capacity']; ?> rather than the usual
            <?php echo (int) $view->cube['cap']; ?>: this database server will not take
            more columns on a row of this cube's shape.)
        <?php endif; ?>
    </div>
    </fieldset>

    <?php if ( $owa_hasRoom ): ?>
    <?php
        /*
         * THE REGISTRATION FORM IS THE MODAL'S BODY. Hidden here; jQuery UI
         * lifts it into a dialog when the button above is pressed.
         *
         * A REFUSED FORM COMES BACK OPEN. CustomDimensionSave::errorAction()
         * renders this screen again with what was typed and the registrar's
         * reason, and both are in here -- so the dialog opens itself on load
         * rather than leaving the reason on a page behind a closed modal and
         * the typed values where nobody can see them.
         */
        $owa_errors = array_filter( (array) $view->validation_errors );
    ?>
    <div id="owa_cdDialog" class="owa_cdDialog" style="display:none;"
        <?php if ( $owa_errors ): ?>data-owa-open="1"<?php endif; ?>>
    <form method="post" name="owa-custom-dimension-form">

        <?php if ( $owa_errors ): ?>
        <div class="owa_cdDialogError">
            <?php foreach ( $owa_errors as $owa_error ): ?>
                <div><?php $view->out( is_array( $owa_error ) ? ( $owa_error['message'] ?? '' ) : $owa_error ); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="setting">
            <div class="title"><label for="owa-cd-key">Name</label></div>
            <div class="description">Exactly the name your tracker sets it under &mdash;
                <code>setEventProperty('plan', ...)</code> is <code>plan</code>.
                Letters, digits and underscores, starting with a letter.</div>
            <div class="field">
                <input type="text" id="owa-cd-key" name="<?php echo $view->getNs(); ?>dimensionKey"
                    value="<?php $view->out( $view->submitted['dimensionKey'] ); ?>"
                    autocomplete="off">
            </div>
        </div>

        <div class="setting">
            <div class="title"><label for="owa-cd-label">Label</label></div>
            <div class="description">What reports call it. Defaults to the name.</div>
            <div class="field">
                <input type="text" id="owa-cd-label" name="<?php echo $view->getNs(); ?>label"
                    value="<?php $view->out( $view->submitted['label'] ); ?>">
            </div>
        </div>

        <div class="setting">
            <div class="title"><label for="owa-cd-scope">Scope</label></div>
            <div class="description"><b>event</b> &mdash; describes one thing that happened, set with
                <code>setEventProperty()</code>.
                <b>user</b> &mdash; describes the visitor, set with
                <code>setUserProperty()</code>, and carried onto every event of theirs.
                There is no session scope: a session-scoped value is one a report derives,
                not one a browser carries.</div>
            <div class="field">
                <select id="owa-cd-scope" name="<?php echo $view->getNs(); ?>scope">
                    <?php foreach ( $view->scopes as $scope ): ?>
                    <option value="<?php $view->out( $scope ); ?>"
                        <?php if ( $view->submitted['scope'] === $scope ): ?>selected<?php endif; ?>>
                        <?php $view->out( $scope ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="setting">
            <div class="title"><label for="owa-cd-type">Type</label></div>
            <div class="description">Fixed when the column is made, so it cannot be changed afterwards without
                removing the dimension and registering it again. A value that will not
                convert is stored as nothing rather than failing the report.</div>
            <div class="field">
                <select id="owa-cd-type" name="<?php echo $view->getNs(); ?>dataType">
                    <?php foreach ( $view->types as $type ): ?>
                    <option value="<?php $view->out( $type ); ?>"
                        <?php if ( $view->submitted['dataType'] === $type ): ?>selected<?php endif; ?>>
                        <?php $view->out( $type ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="owa_cdDialogNote">
            Nothing already collected is filled in automatically &mdash; a new dimension is empty
            for past events and filled from now on. To reach back over data you already have,
            rebuild the cube for the range you want.
        </div>

        <input type="hidden" name="<?php echo $view->getNs(); ?>propertyId"
            value="<?php $view->out( $view->propertyId ); ?>">
        <input type="hidden" name="<?php echo $view->getNs(); ?>siteId"
            value="<?php $view->out( $view->siteId ); ?>">
        <input type="hidden" name="<?php echo $view->getNs(); ?>action"
            value="base.customDimensionSave">
        <?php echo $view->createNonceFormField( 'base.customDimensionSave' ); ?>

        <?php
            /*
             * The form's own submit button rather than a jQuery UI dialog
             * button, so Enter in the name field registers, and the POST is an
             * ordinary form post with nothing for script to reassemble.
             */
        ?>
        <div class="owa_cdDialogButtons">
            <button type="button" class="owa-button owa-button-quiet" data-owa-cd-cancel>Cancel</button>
            <input class="owa-button" type="submit" value="Register">
        </div>
    </form>
    </div>

<script type="text/javascript">
jQuery( function () {

    var dialog = jQuery( '#owa_cdDialog' );

    if ( ! dialog.length ) {
        return;
    }

    dialog.dialog( {
        autoOpen: dialog.data( 'owa-open' ) === 1,
        modal: true,
        resizable: false,
        width: Math.min( 560, jQuery( window ).width() - 40 ),
        title: 'Register a custom dimension',
        dialogClass: 'owa_cdDialogFrame',
        open: function () {
            jQuery( '#owa-cd-key' ).trigger( 'focus' );
        }
    } );

    jQuery( document ).on( 'click', '[data-owa-cd-open]', function ( e ) {

        e.preventDefault();

        dialog.dialog( 'open' );
    } );

    jQuery( document ).on( 'click', '[data-owa-cd-cancel]', function ( e ) {

        e.preventDefault();

        dialog.dialog( 'close' );
    } );
} );
</script>
    <?php endif; ?>

<?php endif; ?>

</div>
