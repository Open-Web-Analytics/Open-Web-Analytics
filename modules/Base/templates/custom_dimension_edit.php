<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Register one custom dimension.
 *
 * Reached from the list's "Register New Custom Dimension" link, and rendered
 * again by CustomDimensionSave::errorAction() when the registrar refuses --
 * with its reason beside the name and what was typed still in the fields.
 */
?>
<div class="panel_headline">New Custom Dimension</div>
<div id="panel">

<div class="owa_panelIntro">
    Register a value your site sets on an event or a visitor, so reports can group by it.
</div>

<?php if ( ! $view->cube['exists'] ): ?>

    <div class="owa_panelIntro">
        <b>This Property has not collected anything yet.</b>
        Its reporting cube is created the first time data arrives, and there is nothing to
        add a column to until then. Come back once the tracker has sent something.
    </div>

<?php elseif ( $view->used >= $view->cube['capacity'] ): ?>

    <div class="owa_panelIntro">
        <b>This Property has no room for another.</b>
        All <?php echo (int) $view->cube['capacity']; ?> are in use. Remove one from the list
        to make room.
    </div>

<?php else: ?>

<form method="post" name="owa-custom-dimension-form">

    <div class="setting">
        <div class="title"><label for="owa-cd-key">Name</label></div>
        <div class="description">Exactly the name your tracker sets it under &mdash;
            <code>setEventProperty('plan', ...)</code> is <code>plan</code>.
            Letters, digits and underscores, starting with a letter.</div>
        <div class="field">
            <input class="owa_largeFormField" type="text" id="owa-cd-key"
                name="<?php echo $view->getNs(); ?>dimensionKey"
                value="<?php $view->out( $view->submitted['dimensionKey'] ); ?>"
                autocomplete="off">
            <span class="validation_error"><?php $view->out( $view->validation_errors['dimensionKey'] ?? '' ); ?></span>
        </div>
    </div>

    <div class="setting">
        <div class="title"><label for="owa-cd-label">Label</label></div>
        <div class="description">What reports call it. Defaults to the name.</div>
        <div class="field">
            <input class="owa_largeFormField" type="text" id="owa-cd-label"
                name="<?php echo $view->getNs(); ?>label"
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

    <div class="owa_panelIntro">
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

    <input class="owa-button" type="submit" name="<?php echo $view->getNs(); ?>submit_btn"
        value="Register Custom Dimension">
</form>

<?php endif; ?>

</div>
