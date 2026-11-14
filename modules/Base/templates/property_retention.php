<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->headline );?></h1>
<div id="panel">
<div class="owa_panelIntro"><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'How many months of reporting data “%s” keeps. Event data is kept for the whole installation, on the instance’s Data Retention page; reports can never reach further back than it.' ),
    $view->property['name'] ?? '' ) );?></div>

    <form method="POST" action="<?php echo $view->makeLink( array( 'do' => 'base.propertyRetentionUpdate' ) );?>"
          data-owa-retention-form="property"
          data-owa-retention-property="<?php $view->out( $view->property['id'] ?? '' );?>"
          data-owa-retention-preview="<?php $view->out( $view->makeApiLink( array(
              'do' => 'retentionPreview', 'module' => 'base', 'version' => 'v1' ) ) ); ?>">
        <?php echo $view->createNonceFormField( 'base.propertyRetentionUpdate' );?>
        <input type="hidden" name="propertyId" value="<?php $view->out( $view->property['id'] ?? '' );?>">
        <?php
            echo \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet(
                \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.propertyRetention' ),
                'property', (string) ( $view->property['id'] ?? '' ) );
        ?>
        <input class="owa-button" type="submit" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save' ) ); ?>">
    </form>
</div>
