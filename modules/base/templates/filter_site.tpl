<div id="owa_reportSiteFilter">

	<span>Web Site:</span>
	<SELECT name="owa_reportSiteFilterSelect" id="owa_reportSiteFilterSelect" style="width:auto;height:auto;">
	<?php foreach ($sites as $site => $value):?>
		<OPTION VALUE="<?php $this->out($value['site_id'], false);?>" <?php if ($params['siteId'] === $value['site_id']):?>selected="selected" selected <?php endif; ?>><?php $this->out( $value['name'] );?></OPTION>
	<?php endforeach;?>
	</SELECT>

</div>