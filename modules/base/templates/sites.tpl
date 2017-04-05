
<P>Below is the list of web sites that are being tracked.</P>


<p class="inline_h2"><a href="<?php echo $this->makeLink(array('do' => 'base.sitesProfile'));?>">Add New</a></p>

<?php if ($tracked_sites): ?>
	<?php foreach ($tracked_sites as $site):?>
	<div class="owa_reportSectionContent" style="min-width:500px;">	
	<TABLE width="" border="0" class="management">
		<thead></thead>
		<tbody>
			<TR>
				
				<td style="width:150px;" valign="">
					<?php $this->getSiteThumbnail( $site->get( 'domain' ), 150 );?>
				</td>
				
				<td valign="" style="min-width:300px;">
					<span style="font-size:14px; font-weight:bold;">
						<a href="<?php echo $this->makeLink( array('do' => 'base.reportDashboard', 'siteId' => $site->get('site_id') ), false );?>"><?php $this->out( $site->get('name') );?></a>
					</span><BR>
					<?php if ($site->get('description') != ''):?>
					<span class="info_text"><?php $this->out( $site->get('description') );?></span><BR>
					<?php endif;?>
					<span class="externalUrl"><?php $this->out( $site->get('domain') );?></span><BR><BR>
					<div>
					<a href="<?php echo $this->makeLink( array('do' => 'base.reportDashboard', 'siteId' => $site->get('site_id') ), true );?>">View Reports</a> |
					<a href="<?php echo $this->makeLink( array('do' => 'base.sitesProfile', 'siteId' => $site->get('site_id'), 'edit' => true ) );?>">Edit Profile</a> |
					<a href="<?php echo $this->makeLink( array('do' => 'base.sitesInvocation', 'siteId' => $site->get('site_id') ) );?>">Get Tracking Code</a> | 
					<a href="<?php echo $this->makeLink( array('do' => 'base.optionsGoals', 'siteId' => $site->get('site_id') ) );?>">Goals</a>
					|
					<a href="<?php echo $this->makeLink( array('do' => 'base.sitesDelete', 'siteId' => $site->get('site_id') ), false, false, false, true );?>">Delete</a>
					</div>
				</td>
				<td>
					<!-- stats -->
					<div id="trend-metrics-<?php echo $site->get('site_id'); ?>"></div>
					<script>
						var aurl = '<?php echo $this->makeApiLink(array(
											'do'			=> 'getResultSet', 
											'metrics'		=> 'visits,pageViews,bounceRate', 
											'dimensions' 	=> 'date', 
											'sort' 			=> 'date',
											'format' 		=> 'json',
											'constraints'	=> urlencode('siteId=='.$site->get('site_id'))	
									), true); ?>';
													  
						var rsh = new OWA.resultSetExplorer('site-trend');
					
						//rsh.asyncQueue.push(['makeAreaChart', [{x: 'date', y: 'visits'}], 'trend-chart']);
						rsh.options.metricBoxes.width = '150px';
						rsh.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics-<?php echo $site->get('site_id'); ?>']);
						rsh.load(aurl);
						OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer('rsh-<?php echo $site->get('site_id'); ?>', rsh);
					</script>
					
					
					
				</td>
			</TR>
		</tbody>
	</TABLE>
</div>
<?php endforeach;?>
<?php else: ?>
	
There are no tracked sites. <a href="<?php echo $this->makeLink(array('do' => 'base.sitesProfile'));?>">Add a site</a>.</TD>
	
<?php endif;?>
