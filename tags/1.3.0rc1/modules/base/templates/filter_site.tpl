<div id="owa_reportSiteFilter">
<?php //print_r($params['siteId']);?>
	<span>Web Site:</span>
	<SELECT name="owa_reportSiteFilterSelect" id="owa_reportSiteFilterSelect" style="width:auto;height:auto;">
	<?php foreach ($sites as $site => $value):?>
		<OPTION VALUE="<?php echo $value['site_id'];?>" <?php if ($params['siteId'] === $value['site_id']):?>selected="selected" selected <?php endif; ?>><?php echo $value['name'];?></OPTION>
	<?php endforeach;?>
		<OPTION VALUE="" <?php if (empty($params['siteId'])):?>selected="selected" selected <?php endif; ?>>All Sites</OPTION>
	</SELECT>

</div>