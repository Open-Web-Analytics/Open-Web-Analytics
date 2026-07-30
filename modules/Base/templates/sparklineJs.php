<?php /** @var \OWA\Core\ViewScope $view */ ?>
<span id="<?php echo $view->dom_id;?>Sparkline"><?php echo $view->values;?></span>
<script>
    jQuery('#<?php echo $view->dom_id;?>Sparkline').sparkline('html', {width:'<?php echo $view->width;?>px', height:'<?php echo $view->height;?>px', spotRadius: 2, fillColor: '', lineColor: '#ffffff'});
</script>