<div>
	<div>
		<span><img src="<?php echo $this->getAvatarImage( $this->out($this->get('visitor_avatar_id') ) );?>" /></span>
		<span><?php $this->out( $visitor_label );?></span>
	</div>
	<div>			
		<?php $this->renderKpiInfobox( $first_visit_date, 'First Visit' ); ?>
			
		<?php $this->renderKpiInfobox( $num_prior_visits, 'Total Visits' ); ?>
	</div>
</div>

<div style="clear:both;"></div>


<table width="100%">
		<TR>
			<td>
				<div class="owa_reportSectionContent" style="width:500px;">	
					<div class="owa_reportSectionHeader">Latest Visits</div>
					<?php include('report_latest_visits.tpl')?>
					<?php echo $this->makePaginationFromResultSet($visits, array('do' => 'base.reportVisitors'), true);?>
				</div>
			</td>
			<td>
				<div class="owa_reportSectionHeader">Latest Actions</div>
				
			</td>
		</TR>
</table>
				

