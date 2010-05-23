<div id="owa_reportSiteFilter" class="owa_reportControl">
	<span>Web Site:</span>
	<SELECT name="owa_reportSiteFilterSelect" id="owa_reportSiteFilterSelect" style="width:200px;height:auto;">
	<?php foreach ($sites as $site => $value):?>
		<OPTION VALUE="<?php echo $value['site_id'];?>" <?php if ($params['site_id'] == $value['site_id']): echo 'selected'; endif; ?>><?php echo $value['name'];?></OPTION>
	<?php endforeach;?>
		<OPTION VALUE="" <?php if (empty($params['site_id'])): echo 'selected'; endif; ?>>All Sites</OPTION>
	</SELECT>

</div>