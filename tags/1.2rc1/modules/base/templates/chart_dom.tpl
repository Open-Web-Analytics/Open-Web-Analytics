<div id="<?=$dom_id;?>Container" style="width:<?=$width;?>; height:<?=$height;?>;">
	<div id="<?=$dom_id;?>"></div>
</div>

<script>
OWA.items['<?=$dom_id;?>'] = new OWA.chart();
OWA.items['<?=$dom_id;?>'].setDomId('<?=$dom_id;?>');
OWA.items['<?=$dom_id;?>'].setData(<?=$data;?>);
OWA.items['<?=$dom_id;?>'].render();
</script>