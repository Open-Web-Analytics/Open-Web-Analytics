<div id="<?=$dom_id;?>Container" style="width:; margin:0px; padding:0px;height:<?=$height;?>;">
	<div id="<?=$dom_id;?>"></div>
</div>

<script>
OWA.items['<?=$dom_id;?>'] = new OWA.chart();
OWA.items['<?=$dom_id;?>'].setDomId('<?=$dom_id;?>');
OWA.items['<?=$dom_id;?>'].setData(<?=$data;?>);
OWA.items['<?=$dom_id;?>'].config.ofc_version = '<?=OWA_OFC_VERSION;?>';

OWA.items['<?=$dom_id;?>'].render();
jQuery("#<?=$dom_id;?>").addclass('owa_ofcChart');
</script>