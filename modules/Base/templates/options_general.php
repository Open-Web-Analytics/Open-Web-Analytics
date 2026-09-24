<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></div>

<?php
/*
 * #panel, like every other settings screen.
 *
 * This one alone used .subview_content, which is padding and nothing else --
 * so the general options were the only settings screen with no white card
 * under them, sitting straight on the page's grey with each `.setting` drawing
 * its own box. See .owa_hierarchyContent #panel in owa.report.css, which is
 * what gives these screens their ground.
 */
?>
<div id="panel">

<form method="post" name="owa_options">

<?php
/*
 * The fields are BUILT, not written out.
 *
 * Each of these used to be a hand-written block: a title div, a description
 * div, and a control whose name was assembled here as config[base.key]. Eight
 * of them, and every one an independent chance to name a setting the registry
 * does not have -- which does not error, it saves a value nothing reads.
 *
 * The declaration in modules/Base/settings.php already says what each control
 * is and what to call it, and registerSettingsFieldSet() in Module.php says
 * which settings appear here and in what order. Both are read below rather
 * than restated.
 *
 * ONE BEHAVIOUR CHANGED. A setting supplied by a config-file constant is
 * rendered disabled with a note naming the constant. That was hand-written for
 * base.timezone and for nothing else, so every other field on this page used to
 * accept an edit that the next boot silently discarded.
 */
foreach ( $view->settings_fieldsets as $set ) {

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set, $view->getNs() );
    echo "<br>\n";
}
?>

    <?php echo $view->createNonceFormField('base.optionsUpdate');?>

    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
    <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="base.optionsReset">Reset Configuration to Default Values</BUTTON>

</form>
</div>
