<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!-- HEAD Elements -->
<?php if(!empty($view->css)): ?>
<?php foreach ($view->css as $cssfile): ?>
<LINK REL=StyleSheet HREF="<?php echo $cssfile['url'];?>" TYPE="text/css">
<?php endforeach; ?>
<?php endif;?>

<?php if(!empty($view->js)): ?>
<?php foreach ($view->js as $jsfile): ?>
<?php if ($jsfile['ie_only']):?>
 <!--[if IE]><script language="javascript" type="text/javascript" src="<?php echo $jsfile['url'];?>"></script><![endif]-->
<?php else: ?>
<script type="text/javascript" src="<?php echo $jsfile['url'];?>"></script>
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