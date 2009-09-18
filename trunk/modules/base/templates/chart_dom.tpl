<div id="<?php echo $dom_id;?>Container" style="width:; margin:0px; padding:0px;height:<?php echo $height;?>;">
	<div id="<?php echo $dom_id;?>"></div>
</div>

<script>
OWA.items['<?php echo $dom_id;?>'] = new OWA.chart();
OWA.items['<?php echo $dom_id;?>'].setDomId('<?php echo $dom_id;?>');
OWA.items['<?php echo $dom_id;?>'].setData(<?php echo $data;?>);
OWA.items['<?php echo $dom_id;?>'].config.ofc_version = '<?php echo OWA_OFC_VERSION;?>';

OWA.items['<?php echo $dom_id;?>'].render();
jQuery("#<?php echo $dom_id;?>").addClass('owa_ofcChart');
</script>