<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Custom dimensions belong to a PROPERTY, because the reporting cube does.
 * Two Properties may use the same key for different things, which is the
 * point -- v1's numbered slots forced one namespace on an installation.
 *
 * REGISTERING IS ITS OWN SCREEN (base.customDimensionEdit), linked from the
 * legend like Goal Event Definitions' "Add New Goal Event Definition".
 *
 * REGISTERING DOES NOT ADD THE COLUMN. It writes the registration and returns;
 * the column is added under the lock a cube build holds, within minutes. The
 * list says which state each one is in, because otherwise the gap between
 * "registered" and "reportable" would look like a fault.
 */
?>
<h1 class="panel_headline"><?php $view->out( \OWA\Core\CoreAPI::t( 'Custom Dimensions' ) ); ?></h1>
<div id="panel">

<div class="owa_panelIntro">
    <?php $view->out( \OWA\Core\CoreAPI::t( 'Register a value your site sets on an event or a visitor to report on it. Every value is stored whether or not it is registered; registering gives it a column in the reporting cube, so reports can group by it.' ) ); ?>
</div>

<?php if ( ! $view->cube['exists'] ): ?>

    <div class="owa_panelIntro">
        <b><?php $view->out( \OWA\Core\CoreAPI::t( 'This Property has not collected anything yet.' ) ); ?></b>
        <?php $view->out( \OWA\Core\CoreAPI::t( 'Its reporting cube is created the first time data arrives, and there is nothing to add a column to until then. Come back once the tracker has sent something.' ) ); ?>
    </div>

<?php else: ?>

    <fieldset>
    <legend>
        <?php $view->out( \OWA\Core\CoreAPI::t( 'Registered' ) ); ?>
        <?php if ( count( $view->dimensions ) < $view->cube['capacity'] ): ?>
        <span class="legend_link">(<a href="<?php echo $view->makeLink( array(
            'do' => 'base.customDimensionEdit', 'propertyId' => $view->propertyId ) ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Register New Custom Dimension' ) ); ?></a>)</span>
        <?php endif; ?>
    </legend>

    <?php if ( ! $view->dimensions ): ?>

        <div class="owa_panelIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'None yet.' ) ); ?></div>

    <?php else: ?>

        <table class="management">
            <tr>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Label' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Scope' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Type' ) ); ?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?></th>
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
                        <?php $view->out( \OWA\Core\CoreAPI::t( 'Reportable' ) ); ?>
                    <?php elseif ( $dimension['state'] === 'failed' ): ?>
                        <b><?php $view->out( \OWA\Core\CoreAPI::t( 'Could not be added.' ) ); ?></b>
                        <?php $view->out( $dimension['state_message'] ); ?>
                    <?php else: ?>
                        <?php $view->out( \OWA\Core\CoreAPI::t( 'Being added — reportable within a few minutes' ) ); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post">
                        <?php echo $view->createNonceFormField( 'base.customDimensionDelete' ); ?>
                        <input type="hidden" name="propertyId"
                            value="<?php $view->out( $view->propertyId ); ?>">
                        <input type="hidden" name="dimensionKey"
                            value="<?php $view->out( $dimension['dimension_key'] ); ?>">
                        <input type="hidden" name="action"
                            value="base.customDimensionDelete">
                        <input class="owa-button owa-button-danger" type="submit"
                            name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Remove' ) ); ?>"
                            data-owa-confirm
                            data-owa-confirm-title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Remove this custom dimension?' ) ); ?>"
                            data-owa-confirm-body="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '“%s” stops being reportable, and its column and the values in it go at the next cube build. The events they were read from are untouched, so registering it again and rebuilding brings them back.' ), $dimension['dimension_key'] ) ); ?>"
                            data-owa-confirm-proceed="<?php $view->out( \OWA\Core\CoreAPI::t( 'Remove dimension' ) ); ?>">
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

    <div class="owa_panelIntro">
        <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '%1$s of %2$s used.' ), (int) count( $view->dimensions ), (int) $view->cube['capacity'] ) ); ?>
        <?php if ( $view->cube['capacity'] < $view->cube['cap'] ): ?>
            <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '(%1$s rather than the usual %2$s: this database server will not take more columns on a row of this cube\'s shape.)' ), (int) $view->cube['capacity'], (int) $view->cube['cap'] ) ); ?>
        <?php endif; ?>
    </div>
    </fieldset>

<?php endif; ?>

</div>
