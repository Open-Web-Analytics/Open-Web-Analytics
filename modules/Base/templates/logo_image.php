<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The logo: the configured image, or the name. Drawn in the header, and at the
 * top of the slide-out menu on a narrow screen (report.php,
 * options_hierarchy.php), where the menu button has the header's place for it.
 */
$owa_logo = $view->makeImageLinkIfPresent( \OWA\Core\CoreAPI::getSetting( 'base', 'logo_image_path' ) );
?>
<?php if ( $owa_logo ): ?><img src="<?php echo $owa_logo; ?>" alt="Open Web Analytics"><?php else: ?>Open Web Analytics<?php endif; ?>
