<!-- Sparkline data for '<?=$dom_id;?>' -->
<span id="<?=$dom_id;?>"><?=$data;?></span>

<script>
/* Sparkline DOM configuration for '<?=$dom_id;?>' */
OWA.items['<?=$dom_id;?>'] = new OWA.sparkline();
OWA.items['<?=$dom_id;?>'].setDomId('<?=$dom_id;?>');
OWA.items['<?=$dom_id;?>'].setWidth('<?=$width;?>');
OWA.items['<?=$dom_id;?>'].setHeight('<?=$height;?>');
OWA.items['<?=$dom_id;?>'].render();
</script>