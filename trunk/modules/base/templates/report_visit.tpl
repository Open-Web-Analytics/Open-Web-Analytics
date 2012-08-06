<div class="owa_reportSectionHeader">Visit Summary</div>
<div class="owa_reportSectionContent">
	<?php include('report_latest_visits.tpl');?>
</div>	

<div class="owa_reportSectionHeader">Visit Clickstream</div>

<div class="owa_reportSectionContent">  
    
	<div class="propertyList">
		<?php foreach($clickstream->resultsRows as $s): ?>
		<dt><?php echo $s['hour'];?>:<?php echo $s['minute'];?>:<?php echo $s['second'];?></dt>
		<dd>
			<a href="<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'pageUrl' => urlencode( $s['url'] ) ), true );?>"><span><?php echo $s['uri'];?></span></a>
		</dd>
		<BR><BR>
		<?php endforeach; ?>
	</div>
	
</div>
 