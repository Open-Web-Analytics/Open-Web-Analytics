<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Custom dimensions belong to a PROPERTY, because the reporting cube does.
 * Two Properties may use the same key for different things, which is the
 * point -- v1's numbered slots forced one namespace on an installation.
 *
 * REGISTERING IS ITS OWN SCREEN (base.customDimensionEdit), linked from the
 * legend like Goal Events' "Add New Goal Event".
 *
 * REGISTERING DOES NOT ADD THE COLUMN. It writes the registration and returns;
 * the column is added under the lock a cube build holds, within minutes. The
 * list says which state each one is in, because otherwise the gap between
 * "registered" and "reportable" would look like a fault.
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

    <fieldset>
    <legend>
        Registered
        <?php if ( count( $view->dimensions ) < $view->cube['capacity'] ): ?>
        <span class="legend_link">(<a href="<?php echo $view->makeLink( array(
            'do' => 'base.customDimensionEdit', 'siteId' => $view->siteId ) ); ?>">Register New Custom Dimension</a>)</span>
        <?php endif; ?>
    </legend>

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

<?php endif; ?>

</div>
