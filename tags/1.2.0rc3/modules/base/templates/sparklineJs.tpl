<span id="<?=$dom_id;?>Sparkline"><?=$values;?></span>
<script>
	jQuery('#<?=$dom_id;?>Sparkline').sparkline('html', {width:'<?=$width;?>px', height:'<?=$height;?>px', spotRadius: 3});
</script>