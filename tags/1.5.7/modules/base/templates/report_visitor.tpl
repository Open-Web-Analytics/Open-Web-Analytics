<div>
	<div style="display: table-cell; vertical-align: middle">
		<span><img class="owa_avatar" style="vertical-align:middle;" src="<?php echo $this->getAvatarImage( $this->out($this->get('visitor_avatar_id') ) );?>" /></span>
		<span class="inline_h2"><?php $this->out( $visitor_label );?></span>
	</div>
	<BR>
	<div>			
		<?php $this->renderKpiInfobox( $first_visit_date, 'First Visit' ); ?>
			
		<?php $this->renderKpiInfobox( $num_prior_visits, 'Total Visits' ); ?>
	</div>
</div>

<div style="clear:both;"></div>


<table width="100%">
		<TR>
			<td valign="top">
				<div class="owa_reportSectionContent" style="min-width:500px;">	
					<div class="owa_reportSectionHeader">Latest Visits</div>
					<?php include('report_latest_visits.tpl')?>
					<?php echo $this->makePaginationFromResultSet($visits, array('do' => 'base.reportVisitors'), true);?>
				</div>
			</td>
			<td valign="top">
				<div class="owa_reportSectionContent" style="min-width:300px;">
					<div class="owa_reportSectionHeader">Latest Actions</div>
					
					<?php echo $this->getLatestActions($this->get('startDate'), 
													   $this->get('endDate'), 
													   $this->get('siteId'), 
													   $this->get('visitor_id'), 
													   '',  
													   '300px'); ?>
				</div>
			</td>
		</TR>
</table>
				

