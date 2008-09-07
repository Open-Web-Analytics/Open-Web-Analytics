<!-- HEAD Elements -->
<?php if(!empty($css)): ?>
<?php foreach ($css as $cssfile): ?>
<LINK REL=StyleSheet HREF="<?=$cssfile;?>" TYPE="text/css">
<?php endforeach; ?>
<?php endif;?>

<?php if(!empty($js)): ?>
<?php foreach ($js as $jsfile): ?>
<script type="text/javascript" src="<?=$jsfile;?>"></script>
<?php endforeach; ?>
<?php endif;?>

<script> 

OWA.config.action_url = "<?=$config['action_url'];?>";
OWA.config.images_url = "<?=$config['images_url'];?>";
OWA.config.ns = "<?=$config['ns'];?>";

</script>

<!-- END HEAD -->