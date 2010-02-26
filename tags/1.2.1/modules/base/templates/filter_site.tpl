<div id="owa_reportSiteFilter">
	<span>Web Site:</span><BR>
	<SELECT name="owa_reportSiteFilterSelect" id="owa_reportSiteFilterSelect">
	<?php foreach ($sites as $site => $value):?>
		<OPTION VALUE="<?php echo $value['site_id'];?>" <?php if ($params['site_id'] == $value['site_id']): echo 'selected'; endif; ?>><?php echo $value['name'];?></OPTION>
	<?php endforeach;?>
		<OPTION VALUE="" <?php if (empty($params['site_id'])): echo 'selected'; endif; ?>>All Sites</OPTION>
	</SELECT>

</div>