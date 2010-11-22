<!-- Sparkline data for '<?php echo $dom_id;?>' -->
<span id="<?php echo $dom_id;?>"><?php echo $data;?></span>

<script>
/* Sparkline DOM configuration for '<?php echo $dom_id;?>' */
OWA.items['<?php echo $dom_id;?>'] = new OWA.sparkline();
OWA.items['<?php echo $dom_id;?>'].setDomId('<?php echo $dom_id;?>');
OWA.items['<?php echo $dom_id;?>'].setWidth('<?php echo $width;?>');
OWA.items['<?php echo $dom_id;?>'].setHeight('<?php echo $height;?>');
OWA.items['<?php echo $dom_id;?>'].render();
</script>