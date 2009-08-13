<!-- HEAD Elements -->
<?php if(!empty($css)): ?>
<?php foreach ($css as $cssfile): ?>
<LINK REL=StyleSheet HREF="<?=$cssfile;?>" TYPE="text/css">
<?php endforeach; ?>
<?php endif;?>

<?php if(!empty($js)): ?>
<?php foreach ($js as $jsfile): ?>
<script type="text/javascript" src="<?=$jsfile['url'];?>"></script>
<?php endforeach; ?>
<?php endif;?>

<script>
<?php include('config_dom.tpl'); ?>
</script>


<!-- END HEAD -->