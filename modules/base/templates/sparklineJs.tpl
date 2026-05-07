<span id="<?php echo $dom_id;?>Sparkline"><?php echo $values;?></span>
<script>
    jQuery('#<?php echo $dom_id;?>Sparkline').sparkline('html', {width:'<?php echo $width;?>px', height:'<?php echo $height;?>px', spotRadius: 2, fillColor: '', lineColor: '#ffffff'});
</script>