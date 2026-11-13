<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!-- HEAD Elements -->
<?php
    /*
     * Added to a phone's Home Screen, OWA opens as an app: full screen, with
     * its own name and icon (public/base/i/manifest.json). iOS reads the
     * apple-* tags; the manifest serves everything else.
     */
?>
<link rel="manifest" href="<?php $view->out( $view->makeImageLink( 'base/i/manifest.json' ) ); ?>">
<link rel="apple-touch-icon" href="<?php $view->out( $view->makeImageLink( 'base/i/owa-app-icon-180.png' ) ); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="OWA">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="theme-color" content="#ffffff">
<?php if(!empty($view->css)): ?>
<?php foreach ($view->css as $cssfile): ?>
<LINK REL=StyleSheet HREF="<?php echo $cssfile['url'];?>" TYPE="text/css">
<?php endforeach; ?>
<?php endif;?>

<?php if(!empty($view->js)): ?>
<?php foreach ($view->js as $jsfile): ?>
<?php if ($jsfile['ie_only']):?>
 <!--[if IE]><script src="<?php echo $jsfile['url'];?>"></script><![endif]-->
<?php else: ?>
<script src="<?php echo $jsfile['url'];?>"></script>
<?php endif;?>
<?php endforeach; ?>
<?php endif;?>

<script>
<?php
    /*
     * Only when the bundle that defines OWA is on the page.
     *
     * config_dom.php assigns onto OWA.config, and the signed-out pages -- the
     * password reset request and the set-password form -- load no JavaScript at
     * all. So every one of them threw "OWA is not defined" on load, which was
     * harmless and permanently in the console, hiding anything that was not.
     */
?>
if (typeof OWA !== 'undefined' && OWA.config) {
<?php include('config_dom.php'); ?>
}
</script>


<!-- END HEAD -->